<?php

namespace App\Jobs;

use App\Services\MqttOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Load and publish one authoritative Shopify order snapshot for a Pi.
 *
 * Shopify GraphQL can take several seconds or require multiple pages. Running
 * that work in a queue job keeps the long-running MQTT subscriber available
 * for heartbeats, fulfillment events, and requests from other locations.
 */
class SyncPiOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function __construct(
        public string $locationSlug,
        public string $clientId,
        public string $requestId
    ) {}

    public function handle(MqttOrderSyncService $service): void
    {
        $service->synchronizeAndPublish(
            $this->locationSlug,
            $this->clientId,
            $this->requestId
        );
    }
}
