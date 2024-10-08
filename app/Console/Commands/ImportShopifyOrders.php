<?php

namespace App\Console\Commands;

use App\Models\Metafields;
use App\Models\Orders;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportShopifyOrders extends Command
{
    protected $signature = 'shopify:import-orders';
    protected $description = 'Import orders from Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));
            $api = $shop->api(); // Get the API instance for the shop.

            // $orders = $api->rest('GET', '/admin/orders.json', [
            //     'created_at_min' => now()->subDays(15)->toIso8601String(),
            //     'status' => 'any'
            // ])['body']['orders'];


			$createdAtMin = now()->subDays(15)->toIso8601String();
			$createdAtMax = now()->addDays(7)->toIso8601String();

			$limit = 250; // Maximum allowed limit per request
			$allOrders = []; // Array to hold all orders
			$params = [
				'created_at_min' => $createdAtMin,
				'created_at_max' => $createdAtMax,
				'status' => 'any',
				'limit' => $limit
			];

			do {
				// Fetch orders from Shopify API
				$response = $api->rest('GET', '/admin/orders.json', $params);

				// Check if the response contains orders
				if (isset($response['body']['orders'])) {
					$orders = $response['body']['orders'];
					$orders = (array) $orders ?? [];
					$orders = $orders['container'];
					// Merge the fetched orders with the allOrders array
					$allOrders = array_merge($allOrders, $orders);
				}

				// Check if there is a 'next' page link
				$nextPageInfo = null;
				if (isset($response['body']['next_page_info'])) {
					$nextPageInfo = $response['body']['next_page_info'];
					$params['page_info'] = $nextPageInfo; // Set page_info for the next request
				}

				// Log the current number of orders fetched
				Log::info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders));
				$this->info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders)) . PHP_EOL;

			} while (isset($nextPageInfo));

			$allOrders = (array) $allOrders;

			// Now $allOrders contains all the orders within the specified date range

            // foreach ($orders as $order) {
                $this->importOrders($api, $allOrders);
            // }
        } catch (\Throwable $th) {
            Log::error("Error running job for importing orders: " . json_encode($th));
            $this->error("Error running job for importing orders: " . json_encode($th));
            abort(403, $th);
        }
    }

    public function importOrders($api, $orders){
		echo PHP_EOL;
        foreach ($orders as $order) {
            // Default values in case properties are missing
            $location = null;
            $date = null;
            $day = null;

            if (isset($order['line_items'][0]['properties'][1])) {
                $location = $order['line_items'][0]['properties'][1]['value'];
            }

            if (isset($order['line_items'][0]['properties'][2])) {
                $date = date("Y-m-d", strtotime($order['line_items'][0]['properties'][2]['value']));
            }

            if (isset($order['line_items'][0]['properties'][3])) {
                $day = $order['line_items'][0]['properties'][3]['value'];
            }

            $arr = Orders::updateOrCreate(['number' => $order['order_number']], [
                'order_id' => $order['id'],
                'number' => $order['order_number'],
                'location' => $location,
                'date' => $date,
                'day' => $day,
                'total_price' => $order['total_price'],
                'email' => $order['email'],
                'financial_status' => $order['financial_status'],
                'fulfillment_status' => $order['fulfillment_status'],
                'cancel_reason' => $order['cancel_reason'],
                'gateway' => implode($order['payment_gateway_names']),
                'note' => $order['note'],
                'order_status_url' => $order['order_status_url'],
                'line_items' => json_encode($order['line_items']),
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'cancelled_at' => $order['cancelled_at'],
            ]);

            Log::info("Order: {$order['order_number']} has been imported successfully");
            $this->info("Order: {$order['order_number']} has been imported successfully") . PHP_EOL;

            $this->importOrdersMetafields($api, $order);
        }
    }

    public function importOrdersMetafields($api, $order) {
        try {
            // Fetch metafields from the API
            $metafieldsResponse = $api->rest('GET', "/admin/orders/{$order['id']}/metafields.json");

            if (isset($metafieldsResponse['body']) && isset($metafieldsResponse['body']['metafields'])) {
                $metafields = (array) $metafieldsResponse['body']['metafields'];
            }

            if (isset($metafields['container'])) {
                $metafields = $metafields['container'];
            } else {
                $metafields = [];
            }

            // List of required metafields
            $requiredMetafields = [
                'status', 'pick_up_date', 'location', 'right_items_removed',
                'wrong_items_removed', 'time_of_pick_up', 'door_open_time',
                'image_before', 'image_after'
            ];

            // Fetch existing metafields for the order
            $existingMetafields = Metafields::where('order_id', $order['id'])
                ->whereIn('key', $requiredMetafields)
                ->get()
                ->keyBy('key');

            // Initialize metafieldValues with existing values
            $metafieldValues = [];
            foreach ($requiredMetafields as $key) {
                $metafieldData = $existingMetafields->get($key);
                $metafieldValues[$key] = $metafieldData ? $metafieldData->value : null;
            }

            // Process metafields from API response
            if (isset($metafields)) {
                foreach ($metafields as $field) {
                    if (in_array($field['key'], $requiredMetafields)) {
                        $metafieldValues[$field['key']] = $field['value'];

                        // Update/Create existing metafields with actual values
                        Metafields::updateOrCreate(
                            ['order_id' => $order['id'], 'key' => $field['key']],
                            [
                                'order_id' => $order['id'],
                                'order_number' => $order['order_number'],
                                'metafield_id' => $field['id'] ?? null,
                                'key' => $field['key'],
                                'value' => $field['value'],
                                'created_at' => $field['created_at'] ?? now(),
                                'updated_at' => $field['updated_at'] ?? now(),
                            ]
                        );

                        Log::info("Metafield {$field['key']} for order: {$order['order_number']} has been imported successfully");
                        $this->info("Metafield {$field['key']} for order: {$order['order_number']} has been imported successfully");
                    }
                }
            }

            // Ensure records with NULL values are updated if necessary
            foreach ($requiredMetafields as $key) {
                if ($metafieldValues[$key] === null) {
                    // If the key is not found in the API response, ensure it's inserted with a NULL value
                    Metafields::updateOrCreate(
                        ['order_id' => $order['id'], 'key' => $key],
                        [
                            'order_id' => $order['id'],
                            'order_number' => $order['order_number'],
                            'key' => $key,
                            'value' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    Log::info("Metafield {$key} for order: {$order['order_number']} was missing in API response and has been set to null");
                    $this->info("Metafield {$key} for order: {$order['order_number']} was missing in API response and has been set to null");
                }
            }

            /*

            // Clean up obsolete records with NULL values using temporary table approach
            DB::statement('
                CREATE TEMPORARY TABLE temp_keep_ids AS
                SELECT id
                FROM metafields m1
                WHERE value IS NOT NULL
                AND (order_id IS NOT NULL OR order_number IS NOT NULL)
                AND id = (
                    SELECT MAX(id)
                    FROM metafields m2
                    WHERE m2.order_id = m1.order_id
                    AND m2.`key` = m1.`key`
                )
            ');

            DB::statement('
                DELETE FROM metafields
                WHERE id NOT IN (
                    SELECT id
                    FROM temp_keep_ids
                )
            ');

            DB::statement('DROP TEMPORARY TABLE temp_keep_ids');
*/

            Log::info("Obsolete records have been cleaned up successfully.");
            $this->info("Obsolete records have been cleaned up successfully.");

        } catch (\Exception $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }

        echo PHP_EOL;
    }


}
