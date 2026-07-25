<?php

namespace App\Console\Commands;

use App\Helpers\MqttHelper;
use App\Models\Locations;
use Illuminate\Console\Command;

/**
 * MqttTopics — quick reference command that lists all locations and their MQTT topics.
 *
 * Run: php artisan mqtt:topics
 *
 * Shows a table with each location's name, slug, and full subscribe/publish topics.
 * Useful for telling the RPi client exactly which topic each device should use.
 */
class MqttTopics extends Command
{
    protected $signature = 'mqtt:topics';

    protected $description = 'List all locations with their MQTT topic names';

    public function handle(): int
    {
        $env = MqttHelper::topicEnv();
        $this->info("Environment prefix: {$env}");
        $this->newLine();

        // Fetch all active locations from the database, ordered by location_order
        $locations = Locations::where('is_active', 'Y')
            ->orderBy('location_order')
            ->get();

        if ($locations->isEmpty()) {
            $this->warn('No active locations found in the database.');
            return 0;
        }

        // Build a table showing each location's name, slug, and topics
        $rows = [];
        foreach ($locations as $location) {
            $name = $location->name;
            $slug = MqttHelper::locationToTopicSlug($name);
            $rows[] = [
                $name,
                $slug,
                $slug,
                MqttHelper::newOrderTopic($name),
                MqttHelper::cancelledOrderTopic($name),
                MqttHelper::updatedOrderTopic($name),
                $env.'/location/'.$slug.'/orders/fulfilled',
                MqttHelper::orderSyncTopic($name),
                MqttHelper::piStatusTopic($name),
            ];
        }

        $this->table(
            ['Location', 'Slug', 'MQTT ClientId', 'New Orders Topic', 'Cancelled Orders Topic', 'Updated Orders Topic', 'Fulfillment Topic (RPi publishes)', 'Order Sync Topic (bidirectional)', 'Pi Status Topic (RPi publishes)'],
            $rows
        );

        return 0;
    }
}
