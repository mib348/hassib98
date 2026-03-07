<?php

namespace App\Helpers;

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
 *   {env}/location/{slug}/orders/new        <- Server publishes, RPi subscribes
 *   {env}/location/{slug}/orders/fulfilled  <- RPi publishes, Server subscribes
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
     * @return string  e.g. "dev" or "live"
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
     * @param  string $locationName  e.g. "Standort 1"
     * @return string                e.g. "standort_1"
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
     * Build the topic where the server publishes NEW orders for a location.
     * RPi devices subscribe to this topic to receive orders in real time.
     *
     * @param  string $locationName  e.g. "Standort 1"
     * @return string                e.g. "dev/location/standort_1/orders/new"
     */
    public static function newOrderTopic(string $locationName): string
    {
        return self::topicEnv() . '/location/' . self::locationToTopicSlug($locationName) . '/orders/new';
    }

    /**
     * Build the wildcard topic the server subscribes to for fulfillment confirmations.
     * The "+" is an MQTT single-level wildcard — matches any location slug.
     *
     * @return string  e.g. "dev/location/+/orders/fulfilled"
     */
    public static function fulfillmentSubscriptionTopic(): string
    {
        return self::topicEnv() . '/location/+/orders/fulfilled';
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
     * @param  string $locationName  Human-readable location (e.g. "Standort 1")
     * @param  array  $payload       Associative array to be JSON-encoded and sent
     * @return bool                  true if published successfully, false on failure
     */
    public static function publishNewOrder(string $locationName, array $payload): bool
    {
        try {
            $topic = self::newOrderTopic($locationName);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Use the "default" MQTT connection (short-lived publisher)
            // QoS 1 = at least once delivery, retain = false (no need to store last message)
            MQTT::connection('default')->publish($topic, $json, 1, false);

            Log::info('MQTT: Published new order to topic', [
                'topic' => $topic,
                'order_id' => $payload['order_id'] ?? null,
                'order_number' => $payload['order_number'] ?? null,
                'location' => $locationName,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Log the error but do NOT rethrow — MQTT failure must not break the webhook job
            Log::error('MQTT: Failed to publish new order', [
                'location' => $locationName,
                'order_id' => $payload['order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
