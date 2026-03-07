<?php

namespace App\Console\Commands;

use App\Helpers\MqttHelper;
use App\Models\Fulfillment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpMqtt\Client\Facades\MQTT;

/**
 * MqttSubscribe — long-running artisan command that listens for RPi fulfillment messages.
 *
 * HOW IT WORKS:
 * - Connects to the MQTT broker using the "subscriber" connection (auto-reconnect enabled).
 * - Subscribes to "location/+/orders/fulfilled" (wildcard matches any location slug).
 * - When an RPi device publishes a fulfillment confirmation, this command:
 *   1. Validates the JSON payload (same rules as FulfillmentController::store)
 *   2. Saves/updates the fulfillment record in the database
 *   3. Syncs all metafields to Shopify (replicating FulfillmentController::store logic)
 *
 * RUNNING IN PRODUCTION:
 * Use Supervisor to keep this command alive:
 *   [program:mqtt-subscriber]
 *   command=php /path/to/artisan mqtt:subscribe
 *   autostart=true
 *   autorestart=true
 *   numprocs=1
 *   stdout_logfile=/path/to/storage/logs/mqtt-subscriber.log
 */
class MqttSubscribe extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mqtt:subscribe';

    /**
     * The console command description.
     */
    protected $description = 'Subscribe to MQTT topics for RPi fulfillment confirmations (long-running)';

    /**
     * Execute the console command.
     * Connects to the broker, subscribes, and runs an infinite event loop.
     * Returns exit code 1 on fatal error so Supervisor restarts the process.
     */
    public function handle(): int
    {
        $this->info('MQTT Subscriber starting...');

        try {
            // Connect using the "subscriber" connection which has auto-reconnect enabled
            $mqtt = MQTT::connection('subscriber');

            // The wildcard topic matches ALL locations:
            // location/standort_1/orders/fulfilled, location/standort_2/orders/fulfilled, etc.
            $topic = MqttHelper::fulfillmentSubscriptionTopic();

            // Subscribe with QoS 1 (at least once delivery) to ensure no messages are lost
            $mqtt->subscribe($topic, function (string $topic, string $message) {
                $this->processFulfillmentMessage($topic, $message);
            }, 1);

            $this->info("Subscribed to: {$topic}");
            Log::info('MQTT Subscriber: Listening on topic', ['topic' => $topic]);

            // Infinite event loop — blocks here forever, processing incoming messages.
            // The loop handles ping/pong keep-alive and auto-reconnect internally.
            $mqtt->loop(true);

        } catch (\Throwable $e) {
            $this->error('MQTT Subscriber fatal error: ' . $e->getMessage());
            Log::error('MQTT Subscriber: Fatal error, exiting for Supervisor restart', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 1 so Supervisor knows to restart the process
            return 1;
        }

        return 0;
    }

    /**
     * Process a single fulfillment message received from an RPi device.
     *
     * This replicates the logic from FulfillmentController::store():
     * 1. Validate the JSON payload
     * 2. Save/update the Fulfillment record in the database
     * 3. Build Shopify metafield updates for the order
     * 4. Push each metafield to the Shopify REST API
     *
     * @param string $topic   The MQTT topic the message arrived on
     * @param string $message The raw JSON payload from the RPi device
     */
    private function processFulfillmentMessage(string $topic, string $message): void
    {
        try {
            $this->info("Received message on: {$topic}");

            // -----------------------------------------------------------------
            // 1. Decode and validate the JSON payload
            // -----------------------------------------------------------------
            $data = json_decode($message, true);

            if (!$data || !is_array($data)) {
                Log::warning('MQTT Subscriber: Invalid JSON received', [
                    'topic' => $topic,
                    'raw_message' => $message,
                ]);
                return;
            }

            // Same validation rules as FulfillmentController::store()
            $validator = Validator::make($data, [
                'order_id' => 'required|integer',
                'order' => 'required|integer',
                'pick-up-date' => 'nullable|string|max:16',
                'location' => 'nullable|string',
                'status' => 'nullable',
                'status.*' => 'string',
                'items-bought' => 'nullable',
                'items-bought.*' => 'string',
                'right-items-removed' => 'nullable',
                'right-items-removed.*' => 'string',
                'wrong-items-removed' => 'nullable',
                'wrong-items-removed.*' => 'string',
                'time-of-pick-up' => 'nullable',
                'time-of-pick-up.*' => 'string|max:32',
                'door-open-time' => 'nullable',
                'door-open-time.*' => 'integer',
                'image-before' => 'nullable',
                'image-before.*' => 'string',
                'image-after' => 'nullable',
                'image-after.*' => 'string',
            ]);

            if ($validator->fails()) {
                Log::warning('MQTT Subscriber: Validation failed', [
                    'topic' => $topic,
                    'errors' => $validator->errors()->toArray(),
                    'data' => $data,
                ]);
                return;
            }

            $validatedData = $validator->validated();

            // -----------------------------------------------------------------
            // 2. Save/update the fulfillment record in the database
            //    (same as FulfillmentController::store line 98)
            // -----------------------------------------------------------------
            $order = Fulfillment::updateOrCreate(
                ['order_id' => $validatedData['order_id'], 'order' => $validatedData['order']],
                $validatedData
            );

            Log::info('MQTT Subscriber: Fulfillment record saved', [
                'order_id' => $validatedData['order_id'],
                'order_number' => $validatedData['order'],
            ]);

            // -----------------------------------------------------------------
            // 3. Get shop instance for Shopify API calls
            // -----------------------------------------------------------------
            $shop = User::find(env('db_shop_id', 1));
            if (!$shop) {
                Log::error('MQTT Subscriber: Shop not found, cannot sync metafields');
                return;
            }

            // -----------------------------------------------------------------
            // 4. Build Shopify metafield updates
            //    (replicates FulfillmentController::store lines 128-341)
            // -----------------------------------------------------------------
            $orderId = $validatedData['order_id'];
            $metafields = $this->buildMetafields($validatedData);

            // -----------------------------------------------------------------
            // 5. Push each metafield to Shopify via REST API
            // -----------------------------------------------------------------
            foreach ($metafields as $metafield) {
                $payload = [
                    'metafield' => [
                        'namespace' => $metafield['namespace'],
                        'key' => $metafield['key'],
                        'value' => $metafield['value'],
                        'type' => $metafield['type'],
                    ],
                ];

                $response = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $payload);

                if ($response['errors']) {
                    Log::error('MQTT Subscriber: Failed to update Shopify metafield', [
                        'order_id' => $orderId,
                        'metafield_key' => $metafield['key'],
                        'response' => $response,
                    ]);
                }
            }

            Log::info('MQTT Subscriber: Fulfillment processed successfully', [
                'order_id' => $orderId,
                'order_number' => $validatedData['order'],
                'metafields_count' => count($metafields),
            ]);

            $this->info("Processed fulfillment for order #{$validatedData['order']}");

        } catch (\Throwable $e) {
            // Log error but do NOT rethrow — we don't want one bad message to kill the subscriber
            Log::error('MQTT Subscriber: Error processing fulfillment message', [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error("Error processing message: {$e->getMessage()}");
        }
    }

    /**
     * Build the array of Shopify metafields from validated fulfillment data.
     *
     * This is the same metafield-building logic from FulfillmentController::store()
     * extracted into a reusable method. Each metafield handles the JSON array
     * normalization (string vs array) that Shopify expects.
     *
     * @param  array $data  Validated fulfillment data from the RPi
     * @return array        Array of metafield definitions ready for Shopify API
     */
    private function buildMetafields(array $data): array
    {
        $metafields = [];

        // Location metafield — simple text field
        if (isset($data['location'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'location',
                'value' => $data['location'],
                'type' => 'single_line_text_field',
            ];
        }

        // Pick-up date — convert from dd-mm-yyyy to Shopify's yyyy-mm-dd format
        if (isset($data['pick-up-date'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'pick_up_date',
                'value' => date('Y-m-d', strtotime($data['pick-up-date'])),
                'type' => 'date',
            ];
        }

        // Status — list of strings (e.g. ["fulfilled"])
        if (isset($data['status'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'status',
                'value' => json_encode($this->ensureArray($data['status'])),
                'type' => 'list.single_line_text_field',
            ];
        }

        // Items bought — list of strings (e.g. ["Sushi Box A (2)"])
        if (isset($data['items-bought'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'items_bought',
                'value' => json_encode($this->ensureArray($data['items-bought'])),
                'type' => 'list.single_line_text_field',
            ];
        }

        // Right items removed — list of strings
        if (isset($data['right-items-removed'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'right_items_removed',
                'value' => json_encode($this->ensureArray($data['right-items-removed'])),
                'type' => 'list.single_line_text_field',
            ];
        }

        // Wrong items removed — list of strings
        if (isset($data['wrong-items-removed'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'wrong_items_removed',
                'value' => json_encode($this->ensureArray($data['wrong-items-removed'])),
                'type' => 'list.single_line_text_field',
            ];
        }

        // Image before pickup — list of filenames/URLs
        if (isset($data['image-before'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'image_before',
                'value' => json_encode($this->ensureArray($data['image-before'])),
                'type' => 'list.single_line_text_field',
            ];
        }

        // Image after pickup — list of filenames/URLs
        if (isset($data['image-after'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'image_after',
                'value' => json_encode($this->ensureArray($data['image-after'])),
                'type' => 'list.single_line_text_field',
            ];
        }

        // Time of pick-up — list of datetime strings, formatted for Shopify
        if (isset($data['time-of-pick-up'])) {
            $times = $this->ensureArray($data['time-of-pick-up']);
            $formattedTimes = array_map(function ($time) {
                return date('Y-m-d\TH:i:s\Z', strtotime($time));
            }, $times);

            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'time_of_pick_up',
                'value' => json_encode($formattedTimes),
                'type' => 'list.date_time',
            ];
        }

        // Door open time — list of integers (seconds the door was open)
        if (isset($data['door-open-time'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'door_open_time',
                'value' => json_encode($this->ensureArray($data['door-open-time'])),
                'type' => 'list.number_integer',
            ];
        }

        return $metafields;
    }

    /**
     * Ensure a value is an array.
     *
     * Handles three cases from RPi payloads:
     * 1. Already an array -> return as-is
     * 2. A JSON string like '["item1","item2"]' -> decode it
     * 3. A plain string -> wrap in an array
     *
     * This normalization is needed because RPi devices may send values as
     * arrays or JSON-encoded strings depending on their firmware version.
     *
     * @param  mixed $value  The value to normalize
     * @return array         Always returns an array
     */
    private function ensureArray($value): array
    {
        // Already an array — just return it
        if (is_array($value)) {
            return $value;
        }

        // JSON-encoded array string (starts with "[") — decode it
        if (is_string($value) && strpos($value, '[') === 0) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Plain string or other scalar — wrap in an array
        return [$value];
    }
}
