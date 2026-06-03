<?php

namespace App\Jobs;

use App\Helpers\MqttHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use stdClass;

class OrdersUpdatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain.
     *
     * @var string
     */
    public $shopDomain;

    /**
     * Shopify webhook data, decoded by the Shopify package.
     *
     * @var stdClass|array
     */
    public $data;

    public function __construct($shopDomain, $data)
    {
        ini_set('max_execution_time', 0);

        $this->shopDomain = $shopDomain;
        $this->data = $data;

        Log::info('Constructor Order Updated Webhook: '.json_encode($this->data));
    }

    public function fail($error): void
    {
        Log::error('Handler Order Updated Webhook Job Fail: '.json_encode($error));
    }

    public function handle(): void
    {
        try {
            $orderData = json_decode(json_encode($this->data), true) ?: [];

            // Cancellations have their own ORDERS_CANCELLED webhook and
            // OrdersCancelledJob. If Shopify also sends an orders/updated
            // payload with cancellation fields, skip it here so the RPi gets
            // one clear "order.cancelled" message from the cancelled topic.
            if (! empty($orderData['cancelled_at']) || ! empty($orderData['cancel_reason'])) {
                Log::info('MQTT: Skipped cancelled order in updated webhook', [
                    'shop' => $this->shopDomain,
                    'order_id' => $orderData['id'] ?? null,
                    'order_number' => $orderData['order_number'] ?? null,
                ]);

                return;
            }

            $published = MqttHelper::publishOrderEventPayloads('updated', $orderData);

            Log::info('MQTT: Order updated webhook processed', [
                'shop' => $this->shopDomain,
                'event_action' => 'updated',
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'published_locations' => $published,
            ]);
        } catch (\Throwable $e) {
            // Keep the queue worker alive. One malformed webhook must not stop
            // later MQTT order updates from being delivered to RPi devices.
            Log::error('MQTT: Order updated webhook failed', [
                'shop' => $this->shopDomain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Order Updated Job failed: '.json_encode($exception));
    }
}
