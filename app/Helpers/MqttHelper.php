<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

/**
 * MqttHelper — thin wrapper around the php-mqtt/laravel-client facade.
 *
 * Responsibilities:
 * 1. Build deterministic MQTT topic strings from human-readable location names
 *    (e.g. "Standort 1" -> "dev/location/standort_1/orders/new").
 * 2. Provide a safe publish() that NEVER throws — MQTT failures are logged
 *    but must not break the Shopify webhook pipeline.
 *
 * Topic structure (agreed with RPi firmware):
 *   {env}/location/{slug}/orders/new        <- Server publishes new orders
 *   {env}/location/{slug}/orders/cancelled  <- Server publishes cancelled orders
 *   {env}/location/{slug}/orders/updated    <- Server publishes changed orders
 *   {env}/location/{slug}/orders/fulfilled  <- RPi publishes pickup confirmations
 *   {env}/location/{slug}/orders/sync       <- RPi requests and server responds
 *   {env}/location/{slug}/pi/status         <- RPi publishes heartbeat/status
 *   {env}/location/{slug}/pi/check          <- Server publishes legacy status checks
 *   {env}/location/{slug}/pi/command        <- Server publishes device commands
 *
 * The {env} prefix (e.g. "dev" or "live") is read from MQTT_TOPIC_ENV
 * in .env so dev and live messages never mix on the same broker.
 */
class MqttHelper
{
    /**
     * Get the environment prefix for all MQTT topics.
     *
     * This keeps dev and production topics completely separate even when
     * both servers share the same Mosquitto broker. The value comes from
     * MQTT_TOPIC_ENV in .env, falling back to APP_ENV if not set.
     *
     * Examples:
     *   MQTT_TOPIC_ENV=dev         -> topics start with "dev/"
     *   MQTT_TOPIC_ENV=live        -> topics start with "live/"
     *
     * @return string e.g. "dev" or "live"
     */
    public static function topicEnv(): string
    {
        return env('MQTT_TOPIC_ENV', env('APP_ENV', 'live'));
    }

    /**
     * Convert a human-readable location name into a URL/topic-safe slug.
     * Example: "Standort 1" -> "standort_1"
     *
     * Rules: lowercase, spaces become underscores, only a-z 0-9 and underscores kept.
     *
     * @param  string  $locationName  e.g. "Standort 1"
     * @return string e.g. "standort_1"
     */
    public static function locationToTopicSlug(string $locationName): string
    {
        // First transliterate common German/European special characters
        // so they become readable ASCII equivalents instead of being stripped.
        // e.g. "ä" -> "ae", "ö" -> "oe", "ü" -> "ue", "ß" -> "ss"
        $translitMap = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'é' => 'e',  'è' => 'e',  'ê' => 'e',  'ë' => 'e',
            'á' => 'a',  'à' => 'a',  'â' => 'a',
            'í' => 'i',  'ì' => 'i',  'î' => 'i',
            'ó' => 'o',  'ò' => 'o',  'ô' => 'o',
            'ú' => 'u',  'ù' => 'u',  'û' => 'u',
            'ñ' => 'n',  'ç' => 'c',
        ];
        $slug = strtr($locationName, $translitMap);

        // Lowercase the full name, replace spaces with underscores,
        // then strip anything that isn't alphanumeric or underscore
        $slug = strtolower($slug);
        $slug = str_replace(' ', '_', $slug);
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug);

        return $slug;
    }

    /**
     * Build one outbound order-event topic for a location.
     *
     * The action is the last MQTT topic segment. Keeping this method central
     * prevents "new", "cancelled", and "updated" topics from drifting apart.
     *
     * @param  string  $locationName  Human-readable location, e.g. "Standort 1"
     * @param  string  $action  One of: new, cancelled, updated
     * @return string e.g. "live/location/standort_1/orders/cancelled"
     */
    public static function orderEventTopic(string $locationName, string $action): string
    {
        self::guardOrderEventAction($action);

        return self::topicEnv().'/location/'.self::locationToTopicSlug($locationName).'/orders/'.$action;
    }

    /**
     * Build the topic where the server publishes NEW orders for a location.
     * RPi devices subscribe to this topic to receive orders in real time.
     *
     * @param  string  $locationName  e.g. "Standort 1"
     * @return string e.g. "dev/location/standort_1/orders/new"
     */
    public static function newOrderTopic(string $locationName): string
    {
        return self::orderEventTopic($locationName, 'new');
    }

    /**
     * Build the topic where the server publishes CANCELLED orders for a location.
     *
     * @param  string  $locationName  e.g. "Standort 1"
     * @return string e.g. "dev/location/standort_1/orders/cancelled"
     */
    public static function cancelledOrderTopic(string $locationName): string
    {
        return self::orderEventTopic($locationName, 'cancelled');
    }

    /**
     * Build the topic where the server publishes UPDATED orders for a location.
     *
     * @param  string  $locationName  e.g. "Standort 1"
     * @return string e.g. "dev/location/standort_1/orders/updated"
     */
    public static function updatedOrderTopic(string $locationName): string
    {
        return self::orderEventTopic($locationName, 'updated');
    }

    /**
     * Build the wildcard topic the server subscribes to for fulfillment confirmations.
     * The "+" is an MQTT single-level wildcard — matches any location slug.
     *
     * @return string e.g. "dev/location/+/orders/fulfilled"
     */
    public static function fulfillmentSubscriptionTopic(): string
    {
        return self::topicEnv().'/location/+/orders/fulfilled';
    }

    /**
     * Build the single bidirectional order synchronization topic.
     *
     * The Pi publishes an orders.sync.request event to this topic and Laravel
     * publishes the matching orders.sync.response event back to the same topic.
     * Both sides inspect the event field and ignore the opposite direction.
     *
     * @param  string  $locationName  Human name or existing slug
     * @return string e.g. "live/location/test_location/orders/sync"
     */
    public static function orderSyncTopic(string $locationName): string
    {
        return self::topicEnv().'/location/'.self::locationToTopicSlug($locationName).'/orders/sync';
    }

    /**
     * Build the wildcard topic Laravel listens to for order sync requests.
     *
     * @return string e.g. "live/location/+/orders/sync"
     */
    public static function orderSyncSubscriptionTopic(): string
    {
        return self::topicEnv().'/location/+/orders/sync';
    }

    /**
     * Build the topic where an RPi publishes its heartbeat/status.
     *
     * Each device publishes to its own location topic every 10 seconds. The
     * client should also connect with MQTT ClientId equal to the same slug
     * (for example "standort_1") so broker logs and heartbeat messages agree.
     *
     * @param  string  $locationName  e.g. "Standort 1"
     * @return string e.g. "dev/location/standort_1/pi/status"
     */
    public static function piStatusTopic(string $locationName): string
    {
        return self::topicEnv().'/location/'.self::locationToTopicSlug($locationName).'/pi/status';
    }

    /**
     * Build the wildcard topic Laravel subscribes to for current-env Pi status.
     *
     * The "+" segment matches exactly one location slug. This keeps Laravel
     * listening only to its configured environment prefix (dev or live) while
     * still accepting status from every location in that environment.
     *
     * @return string e.g. "dev/location/+/pi/status"
     */
    public static function piStatusSubscriptionTopic(): string
    {
        return self::topicEnv().'/location/+/pi/status';
    }

    /**
     * Build the topic where Laravel publishes commands to one Pi device.
     *
     * The device subscribes only to its own location topic. Commands are never
     * retained, so a device cannot accidentally execute an old restart after
     * reconnecting to MQTT.
     *
     * @param  string  $locationName  e.g. "Warehouse Location"
     * @return string e.g. "live/location/warehouse_location/pi/command"
     */
    public static function piCommandTopic(string $locationName): string
    {
        return self::topicEnv().'/location/'.self::locationToTopicSlug($locationName).'/pi/command';
    }

    /**
     * Build the legacy topic used by Check PI Response.
     *
     * This remains separate from /pi/command so checking whether a device can
     * answer does not accidentally run the bandwidth-heavy internet speed test.
     */
    public static function piCheckTopic(string $locationName): string
    {
        return self::topicEnv().'/location/'.self::locationToTopicSlug($locationName).'/pi/check';
    }

    /**
     * Convert one Shopify order webhook payload into per-location MQTT payloads.
     *
     * One Shopify order can contain products for more than one pickup location.
     * Each physical location has its own RPi, so MQTT sends one message per
     * location. The array key is the human location name used for topic building.
     *
     * @param  array  $orderData  Decoded Shopify order webhook payload
     * @param  array  $lineItems  The order's line_items array
     * @param  string  $action  One of: new, cancelled, updated
     * @return array<string,array<string,mixed>>
     */
    public static function buildOrderEventPayloads(array $orderData, array $lineItems, string $action): array
    {
        self::guardOrderEventAction($action);

        $itemsByLocation = [];

        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $location = self::lineItemPropertyValue($item, 'location');
            $pickUpDate = self::lineItemPropertyValue($item, 'date');
            $yesterdayItem = self::lineItemPropertyValue($item, 'yesterday_item', 'N');

            if (! is_string($location) && ! is_numeric($location)) {
                continue;
            }

            $location = trim((string) $location);

            // Delivery orders do not belong to a physical RPi pickup station.
            if ($location === '' || $location === 'Delivery') {
                continue;
            }

            // Yesterday items are picked up today even though inventory came
            // from yesterday's bucket. The RPi door logic expects today's date.
            if ($yesterdayItem === 'Y' && $pickUpDate) {
                $pickUpDate = Carbon::now('Europe/Berlin')->format('d-m-Y');
            }

            if (! isset($itemsByLocation[$location])) {
                $itemsByLocation[$location] = [
                    'pick_up_date' => $pickUpDate,
                    'items' => [],
                ];
            }

            $itemsByLocation[$location]['items'][] = [
                'product_id' => $item['product_id'] ?? null,
                'title' => $item['title'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
            ];
        }

        $payloads = [];

        foreach ($itemsByLocation as $locationName => $locationData) {
            $payload = [
                'event' => 'order.'.$action,
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'pick_up_date' => $locationData['pick_up_date'],
                'location' => $locationName,
                'location_slug' => self::locationToTopicSlug($locationName),
                'items' => $locationData['items'],
                'customer_name' => trim(
                    ($orderData['customer']['first_name'] ?? '').' '.
                    ($orderData['customer']['last_name'] ?? '')
                ),
                'total_price' => $orderData['total_price'] ?? '0.00',
                'published_at' => Carbon::now('Europe/Berlin')->toIso8601String(),
            ];

            if ($action === 'cancelled') {
                $payload['cancel_reason'] = $orderData['cancel_reason'] ?? null;
                $payload['cancelled_at'] = $orderData['cancelled_at'] ?? null;
            }

            if ($action === 'updated') {
                $payload['financial_status'] = $orderData['financial_status'] ?? null;
                $payload['fulfillment_status'] = $orderData['fulfillment_status'] ?? null;
                $payload['updated_at'] = $orderData['updated_at'] ?? null;
            }

            $payloads[$locationName] = $payload;
        }

        return $payloads;
    }

    /**
     * Publish every per-location payload for one Shopify order event.
     *
     * @param  string  $action  One of: new, cancelled, updated
     * @param  array  $orderData  Decoded Shopify order webhook payload
     * @param  array|null  $lineItems  Optional line_items override for existing callers
     * @return int Number of payloads successfully published
     */
    public static function publishOrderEventPayloads(string $action, array $orderData, ?array $lineItems = null): int
    {
        $lineItems = $lineItems ?? ($orderData['line_items'] ?? []);
        $published = 0;

        foreach (self::buildOrderEventPayloads($orderData, $lineItems, $action) as $locationName => $payload) {
            if (self::publishOrderEvent($locationName, $action, $payload)) {
                $published++;
            }
        }

        return $published;
    }

    /**
     * Publish a new-order message to the MQTT broker for a specific location.
     *
     * Uses QoS 1 (at least once delivery) so the RPi is guaranteed to receive
     * the message even if a brief network hiccup occurs.
     *
     * CRITICAL: This method NEVER throws. If MQTT is down, the order is already
     * safely stored in the database — the RPi can still fall back to REST polling.
     *
     * @param  string  $locationName  Human-readable location (e.g. "Standort 1")
     * @param  array  $payload  Associative array to be JSON-encoded and sent
     * @return bool true if published successfully, false on failure
     */
    public static function publishNewOrder(string $locationName, array $payload): bool
    {
        return self::publishOrderEvent($locationName, 'new', $payload);
    }

    /**
     * Publish one order event message to the MQTT broker for a specific location.
     *
     * CRITICAL: This method NEVER throws. Shopify webhook work must continue even
     * when MQTT is temporarily down; failures are logged for operational follow-up.
     *
     * @param  string  $locationName  Human-readable location (e.g. "Standort 1")
     * @param  string  $action  One of: new, cancelled, updated
     * @param  array  $payload  Associative array to be JSON-encoded and sent
     * @return bool true if published successfully, false on failure
     */
    public static function publishOrderEvent(string $locationName, string $action, array $payload): bool
    {
        try {
            $topic = self::orderEventTopic($locationName, $action);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Use the "default" MQTT connection (short-lived publisher)
            // QoS 1 = at least once delivery, retain = false (no need to store last message)
            MQTT::connection('default')->publish($topic, $json, 1, false);

            Log::info('MQTT: Published order event to topic', [
                'topic' => $topic,
                'event' => $payload['event'] ?? 'order.'.$action,
                'order_id' => $payload['order_id'] ?? null,
                'order_number' => $payload['order_number'] ?? null,
                'location' => $locationName,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Log the error but do NOT rethrow — MQTT failure must not break the webhook job
            Log::error('MQTT: Failed to publish order event', [
                'location' => $locationName,
                'event' => $payload['event'] ?? 'order.'.$action,
                'order_id' => $payload['order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish one order snapshot response on the same topic as its request.
     *
     * Retain stays false because every reconnect must receive fresh Shopify
     * data rather than an old snapshot left on the broker. QoS 1 provides
     * at-least-once delivery; request_id lets the Pi correlate the response.
     */
    public static function publishOrderSyncResponse(string $locationSlug, array $payload): bool
    {
        try {
            $topic = self::orderSyncTopic($locationSlug);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            MQTT::connection('default')->publish($topic, $json, 1, false);

            Log::info('MQTT: Published order sync response', [
                'topic' => $topic,
                'request_id' => $payload['request_id'] ?? null,
                'location_slug' => $locationSlug,
                'success' => $payload['success'] ?? false,
                'order_count' => $payload['order_count'] ?? 0,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT: Failed to publish order sync response', [
                'request_id' => $payload['request_id'] ?? null,
                'location_slug' => $locationSlug,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish one approved device command without throwing on broker failures.
     *
     * Only the three fields defined by the Pi client are sent. QoS 1 asks the
     * broker for at-least-once delivery, while retain=false prevents commands
     * such as restart_device from being replayed to a device after reconnect.
     */
    public static function publishPiCommand(string $locationName, string $command, ?string $timestamp = null): bool
    {
        self::guardPiCommand($command);

        try {
            $topic = self::piCommandTopic($locationName);
            $payload = [
                'event' => 'command',
                'command' => $command,
                'timestamp' => $timestamp ?? Carbon::now('Europe/Berlin')->toIso8601String(),
            ];
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            MQTT::connection('default')->publish($topic, $json, 1, false);

            Log::info('MQTT: Published Pi command', [
                'topic' => $topic,
                'location' => $locationName,
                'command' => $command,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT: Failed to publish Pi command', [
                'location' => $locationName,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish the original manual Pi check contract.
     *
     * QoS 1 gives at-least-once delivery. Retain must stay false because a
     * reconnecting device must never receive an old health-check request.
     */
    public static function publishPiCheck(string $locationName, array $payload = []): bool
    {
        try {
            $topic = self::piCheckTopic($locationName);
            $legacyPayload = [
                'event' => 'pi.check',
                'location' => $locationName,
                'location_slug' => self::locationToTopicSlug($locationName),
                'requested_at' => $payload['requested_at']
                    ?? $payload['timestamp']
                    ?? Carbon::now('Europe/Berlin')->toIso8601String(),
            ];
            $json = json_encode(
                $legacyPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            MQTT::connection('default')->publish($topic, $json, 1, false);

            Log::info('MQTT: Published legacy Pi check', [
                'topic' => $topic,
                'location' => $locationName,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT: Failed to publish legacy Pi check', [
                'location' => $locationName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function guardOrderEventAction(string $action): void
    {
        if (! in_array($action, ['new', 'cancelled', 'updated'], true)) {
            throw new \InvalidArgumentException("Unsupported MQTT order event action [{$action}].");
        }
    }

    /**
     * Keep the publisher closed to arbitrary command strings. Adding a future
     * device command requires an explicit server change instead of allowing a
     * browser request to publish unrestricted instructions to a Pi.
     */
    private static function guardPiCommand(string $command): void
    {
        if (! in_array($command, ['test_internet_connection', 'restart_device'], true)) {
            throw new \InvalidArgumentException("Unsupported MQTT Pi command [{$command}].");
        }
    }

    private static function lineItemPropertyValue(array $lineItem, string $name, $default = null)
    {
        foreach (($lineItem['properties'] ?? []) as $key => $property) {
            if (is_array($property) && ($property['name'] ?? null) === $name) {
                return $property['value'] ?? $default;
            }

            if (is_object($property) && ($property->name ?? null) === $name) {
                return $property->value ?? $default;
            }

            if ($key === $name) {
                return $property;
            }
        }

        return $default;
    }
}
