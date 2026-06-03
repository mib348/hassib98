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

class OrdersCancelledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain.
     *
     * @var string
     */
    public $shopDomain;

    /**
     * Shopify order/cancelled webhook payload.
     *
     * @var stdClass|array
     */
    public $data;

    public function __construct($shopDomain, $data)
    {
        ini_set('max_execution_time', 0);

        $this->shopDomain = $shopDomain;
        $this->data = $data;

        Log::info('Constructor Order Cancelled Webhook: '.json_encode($this->data));
    }

    public function fail($error): void
    {
        Log::error('Handler Order Cancelled Webhook Job Fail: '.json_encode($error));
    }

    public function handle(): void
    {
        try {
            $orderData = json_decode(json_encode($this->data), true) ?: [];

            // Shopify sends the full order in this webhook. The helper reads
            // each line item's location/date properties, builds one message per
            // physical pickup location, then publishes to:
            // {env}/location/{location_slug}/orders/cancelled
            $published = MqttHelper::publishOrderEventPayloads('cancelled', $orderData);

            Log::info('MQTT: Order cancelled webhook processed', [
                'shop' => $this->shopDomain,
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'published_locations' => $published,
            ]);
        } catch (\Throwable $e) {
            // The queue worker must keep processing later webhooks even if one
            // cancellation payload is malformed or MQTT is temporarily down.
            Log::error('MQTT: Order cancelled webhook failed', [
                'shop' => $this->shopDomain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Order Cancelled Job failed: '.json_encode($exception));
    }
}
