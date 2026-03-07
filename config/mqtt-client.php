<?php

declare(strict_types=1);

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;

return [

    /*
    |--------------------------------------------------------------------------
    | Default MQTT Connection
    |--------------------------------------------------------------------------
    |
    | This setting defines the default MQTT connection returned when requesting
    | a connection without name from the facade.
    |
    */

    'default_connection' => 'default',

    /*
    |--------------------------------------------------------------------------
    | MQTT Connections
    |--------------------------------------------------------------------------
    |
    | Two named connections:
    | - "default"    : Used by OrdersCreateJob to PUBLISH new orders to RPi devices.
    |                  Short-lived: connect -> publish -> disconnect.
    | - "subscriber" : Used by the mqtt:subscribe artisan command to LISTEN for
    |                  RPi fulfillment confirmations. Long-lived with auto-reconnect.
    |
    */

    'connections' => [

        // =====================================================================
        // DEFAULT connection — used for publishing (OrdersCreateJob)
        // Connects, sends one or more messages, then disconnects.
        // =====================================================================
        'default' => [

            'host' => env('MQTT_HOST'),
            'port' => (int) env('MQTT_PORT', 1883),

            'protocol' => MqttClient::MQTT_3_1,

            // Unique client ID so the broker can identify the Laravel publisher
            'client_id' => env('MQTT_CLIENT_ID', 'laravel-server'),

            // Clean session = true: broker won't queue messages while we're offline
            // (we only publish, we don't subscribe on this connection)
            'use_clean_session' => true,

            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),
            'log_channel' => env('MQTT_LOG_CHANNEL', null),

            'repository' => MemoryRepository::class,

            'connection_settings' => [

                'tls' => [
                    'enabled' => env('MQTT_TLS_ENABLED', false),
                    'allow_self_signed_certificate' => env('MQTT_TLS_ALLOW_SELF_SIGNED_CERT', false),
                    'verify_peer' => env('MQTT_TLS_VERIFY_PEER', true),
                    'verify_peer_name' => env('MQTT_TLS_VERIFY_PEER_NAME', true),
                    'ca_file' => env('MQTT_TLS_CA_FILE'),
                    'ca_path' => env('MQTT_TLS_CA_PATH'),
                    'client_certificate_file' => env('MQTT_TLS_CLIENT_CERT_FILE'),
                    'client_certificate_key_file' => env('MQTT_TLS_CLIENT_CERT_KEY_FILE'),
                    'client_certificate_key_passphrase' => env('MQTT_TLS_CLIENT_CERT_KEY_PASSPHRASE'),
                    'alpn' => env('MQTT_TLS_ALPN'),
                ],

                'auth' => [
                    'username' => env('MQTT_AUTH_USERNAME'),
                    'password' => env('MQTT_AUTH_PASSWORD'),
                ],

                'last_will' => [
                    'topic' => env('MQTT_LAST_WILL_TOPIC'),
                    'message' => env('MQTT_LAST_WILL_MESSAGE'),
                    'quality_of_service' => env('MQTT_LAST_WILL_QUALITY_OF_SERVICE', 0),
                    'retain' => env('MQTT_LAST_WILL_RETAIN', false),
                ],

                'connect_timeout' => env('MQTT_CONNECT_TIMEOUT', 60),
                'socket_timeout' => env('MQTT_SOCKET_TIMEOUT', 5),
                'resend_timeout' => env('MQTT_RESEND_TIMEOUT', 10),

                'keep_alive_interval' => env('MQTT_KEEP_ALIVE_INTERVAL', 10),

                'auto_reconnect' => [
                    'enabled' => false,
                    'max_reconnect_attempts' => 3,
                    'delay_between_reconnect_attempts' => 0,
                ],
            ],
        ],

        // =====================================================================
        // SUBSCRIBER connection — used by php artisan mqtt:subscribe
        // Long-running process that listens for RPi fulfillment messages.
        // Auto-reconnect is enabled so Supervisor doesn't need to restart often.
        // =====================================================================
        'subscriber' => [

            'host' => env('MQTT_HOST'),
            'port' => (int) env('MQTT_PORT', 1883),

            'protocol' => MqttClient::MQTT_3_1,

            // Different client ID so the broker treats this as a separate session
            // (MQTT brokers kick the old connection if two clients share the same ID)
            'client_id' => env('MQTT_SUBSCRIBER_CLIENT_ID', 'laravel-subscriber'),

            // Clean session = false: broker will queue messages if subscriber
            // temporarily disconnects and will deliver them on reconnect
            'use_clean_session' => false,

            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),
            'log_channel' => env('MQTT_LOG_CHANNEL', null),

            'repository' => MemoryRepository::class,

            'connection_settings' => [

                'tls' => [
                    'enabled' => env('MQTT_TLS_ENABLED', false),
                    'allow_self_signed_certificate' => env('MQTT_TLS_ALLOW_SELF_SIGNED_CERT', false),
                    'verify_peer' => env('MQTT_TLS_VERIFY_PEER', true),
                    'verify_peer_name' => env('MQTT_TLS_VERIFY_PEER_NAME', true),
                    'ca_file' => env('MQTT_TLS_CA_FILE'),
                    'ca_path' => env('MQTT_TLS_CA_PATH'),
                    'client_certificate_file' => env('MQTT_TLS_CLIENT_CERT_FILE'),
                    'client_certificate_key_file' => env('MQTT_TLS_CLIENT_CERT_KEY_FILE'),
                    'client_certificate_key_passphrase' => env('MQTT_TLS_CLIENT_CERT_KEY_PASSPHRASE'),
                    'alpn' => env('MQTT_TLS_ALPN'),
                ],

                'auth' => [
                    'username' => env('MQTT_AUTH_USERNAME'),
                    'password' => env('MQTT_AUTH_PASSWORD'),
                ],

                'last_will' => [
                    'topic' => env('MQTT_LAST_WILL_TOPIC'),
                    'message' => env('MQTT_LAST_WILL_MESSAGE'),
                    'quality_of_service' => env('MQTT_LAST_WILL_QUALITY_OF_SERVICE', 0),
                    'retain' => env('MQTT_LAST_WILL_RETAIN', false),
                ],

                'connect_timeout' => env('MQTT_CONNECT_TIMEOUT', 60),
                'socket_timeout' => env('MQTT_SOCKET_TIMEOUT', 5),
                'resend_timeout' => env('MQTT_RESEND_TIMEOUT', 10),

                'keep_alive_interval' => env('MQTT_KEEP_ALIVE_INTERVAL', 10),

                // Auto-reconnect enabled with unlimited retries for the subscriber
                // so the long-running process recovers from broker restarts/network blips
                'auto_reconnect' => [
                    'enabled' => true,
                    'max_reconnect_attempts' => 0, // 0 = unlimited retries
                    'delay_between_reconnect_attempts' => 5, // wait 5 seconds between attempts
                ],
            ],
        ],

    ],

];
