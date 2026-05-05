# MQTT Deployment & Testing Guide

> Current public endpoint policy: external MQTT uses `mqtt.sushi.catering:443`,
> dashboard uses `https://mqtt.sushi.catering:8885/login` and
> `https://mqtt.sushi.catering:8885/ui`, and Laravel keeps using
> `127.0.0.1:1883`. See `docs/mqtt-sushi-catering-public-endpoints.md` for the
> aaPanel/Nginx proxy details.

## What We Built (Quick Recap)

```
Shopify Order Webhook
        |
        v
  OrdersCreateJob
        |
        v
  publishOrderToMqtt()  --->  MQTT Broker (Mosquitto)  --->  RPi Device
        |                          ^
        |                          |
        v                          |
  (DB already saved)          RPi sends fulfillment
                                   |
                                   v
                          mqtt:subscribe command  --->  Fulfillment DB + Shopify metafields
```

---

## STEP 1: Start the Mosquitto Broker on the Server

SSH into the Ubuntu server that hosts the Laravel project and run:

```bash
cd /path/to/laravelshopifypartnerapp    # wherever docker-compose.yml lives
docker compose up -d
```

Verify all 3 containers are running:

```bash
docker compose ps
```

You should see:
- `mosquitto` — healthy
- `mqtt-web-client` — running (Node-RED dashboard behind `mqtt.sushi.catering:8885`)

**If already running, skip this step.**

---

## STEP 2: Add MQTT Variables to `.env` on the Server

SSH into the server and edit the Laravel `.env` file:

```bash
nano /path/to/laravelshopifypartnerapp/.env
```

Add these lines at the bottom:

```env
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=laravel-server
MQTT_SUBSCRIBER_CLIENT_ID=laravel-subscriber
MQTT_AUTH_USERNAME=mqtt_12342026
MQTT_AUTH_PASSWORD=mqtt_12342026
MQTT_TLS_ENABLED=false
MQTT_TLS_CERT_DIR=/www/server/panel/vhost/cert/mqtt.sushi.catering
MQTT_TLS_LOCAL_PORT=9443
MQTT_DASHBOARD_LOCAL_PORT=1885
```

Save and exit (`Ctrl+X`, `Y`, `Enter`).

Then clear the config cache:

```bash
php artisan config:clear
```

---

## STEP 3: Pull the Code on the Server

However you deploy (git pull, rsync, etc.):

```bash
cd /path/to/laravelshopifypartnerapp
git pull origin development
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan cache:clear
```

Verify the command is registered:

```bash
php artisan list mqtt
```

You should see:

```
mqtt:subscribe   Subscribe to MQTT topics for RPi fulfillment confirmations (long-running)
```

---

## STEP 4: Test the MQTT Broker Connection

Before testing Laravel, verify the broker accepts connections.

**Option A: Use the Node-RED MQTT dashboard**

Open in browser: `https://mqtt.sushi.catering:8885/ui`

You should see the MQTT dashboard. If it loads, the dashboard proxy is working.

**Option B: From the server command line (inside the Mosquitto container)**

```bash
# Publish a test message
docker exec mosquitto mosquitto_pub \
  -h localhost -p 1883 \
  -u mqtt_12342026 -P mqtt_12342026 \
  -t "test/hello" \
  -m "Hello from server"
```

Watch it appear in the Node-RED dashboard.

---

## STEP 5: Test Publishing (Laravel -> Broker)

Open a terminal on the server and run this artisan tinker command:

```bash
php artisan tinker
```

Inside tinker, paste:

```php
use App\Helpers\MqttHelper;

// Test 1: Check topic slug generation
echo MqttHelper::locationToTopicSlug("Standort 1");
// Expected output: standort_1

echo MqttHelper::newOrderTopic("Standort 1");
// Expected output: location/standort_1/orders/new

// Test 2: Publish a fake order message to the broker
MqttHelper::publishNewOrder("Standort 1", [
    'order_id'     => 9999999999,
    'order_number' => 9999,
    'pick_up_date' => '26-02-2026',
    'location'     => 'Standort 1',
    'items'        => [
        ['product_id' => 123, 'title' => 'Test Sushi Box', 'quantity' => 2]
    ],
    'customer_name' => 'Test Kunde',
    'total_price'   => '19.90',
    'published_at'  => now()->toIso8601String(),
]);
```

**How to verify it worked:**

1. Open the dashboard: `https://mqtt.sushi.catering:8885/ui`
2. You should see a message appear on topic `location/standort_1/orders/new`
3. Also check Laravel logs: `tail -f storage/logs/laravel.log | grep MQTT`

You should see:
```
MQTT: Published new order to topic {"topic":"location/standort_1/orders/new","order_id":9999999999,...}
```

---

## STEP 6: Test Subscribing (Broker -> Laravel)

This tests the RPi fulfillment flow: RPi publishes a "fulfilled" message, and the `mqtt:subscribe` command processes it.

**Terminal 1 — Start the subscriber:**

```bash
php artisan mqtt:subscribe
```

You should see:
```
MQTT Subscriber starting...
Subscribed to: location/+/orders/fulfilled
```

It now sits and waits for messages (this is normal — it's a long-running process).

**Terminal 2 — Simulate an RPi fulfillment message:**

```bash
docker exec mosquitto mosquitto_pub \
  -h localhost -p 1883 \
  -u mqtt_12342026 -P mqtt_12342026 \
  -t "location/standort_1/orders/fulfilled" \
  -m '{
    "order_id": 9999999999,
    "order": 9999,
    "pick-up-date": "26-02-2026",
    "location": "Standort 1",
    "status": ["fulfilled"],
    "items-bought": ["Test Sushi Box (2)"],
    "right-items-removed": ["2"],
    "wrong-items-removed": ["0"],
    "time-of-pick-up": ["2026-02-26T14:35:00Z"],
    "door-open-time": [12],
    "image-before": ["img_before_9999.jpg"],
    "image-after": ["img_after_9999.jpg"]
  }'
```

**What to check in Terminal 1:**

You should see:
```
Received message on: location/standort_1/orders/fulfilled
Processed fulfillment for order #9999
```

**What to check in the database:**

```bash
php artisan tinker
```

```php
use App\Models\Fulfillment;
Fulfillment::where('order_id', 9999999999)->first();
```

You should see the fulfillment record with all the fields populated.

**What to check in logs:**

```bash
tail -20 storage/logs/laravel.log | grep "MQTT Subscriber"
```

You should see:
```
MQTT Subscriber: Fulfillment record saved {"order_id":9999999999,"order_number":9999}
MQTT Subscriber: Fulfillment processed successfully {"order_id":9999999999,...,"metafields_count":10}
```

> **Note:** The Shopify metafield sync will fail for order_id 9999999999 because it's fake.
> That's expected. In the logs you'll see "Failed to update Shopify metafield" errors — that's fine for testing.
> With a real order ID it will work.

---

## STEP 7: Test with a Real Shopify Order (End-to-End)

This is the full flow: place a real order on Shopify, watch MQTT deliver it.

**Terminal 1 — Watch MQTT messages in real time:**

Open the dashboard: `https://mqtt.sushi.catering:8885/ui`

**Terminal 2 — Watch Laravel logs:**

```bash
tail -f storage/logs/laravel.log | grep MQTT
```

**Now place a test order on the Shopify store** (pick a location like "Standort 1").

**What you should see:**

1. In the Laravel logs:
   ```
   MQTT: Published new order to topic {"topic":"location/standort_1/orders/new","order_id":...}
   ```

2. In the Node-RED dashboard: a JSON message appears on `dev/location/standort_1/orders/new` with the order details.

3. The RPi device (if connected) would receive this message instantly instead of needing to poll.

---

## STEP 8: Set Up Supervisor (Production)

The `mqtt:subscribe` command needs to run 24/7. Supervisor keeps it alive.

SSH into the server:

```bash
# Install supervisor if not already installed
sudo apt install supervisor

# Create config file
sudo nano /etc/supervisor/conf.d/mqtt-subscriber.conf
```

Paste this (adjust the path):

```ini
[program:mqtt-subscriber]
process_name=%(program_name)s
command=php /path/to/laravelshopifypartnerapp/artisan mqtt:subscribe
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/laravelshopifypartnerapp/storage/logs/mqtt-subscriber.log
stopwaitsecs=10
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mqtt-subscriber
```

Check it's running:

```bash
sudo supervisorctl status mqtt-subscriber
```

Should show `RUNNING`.

---

## STEP 9: Clean Up Test Data

After testing, remove the fake fulfillment record:

```bash
php artisan tinker
```

```php
use App\Models\Fulfillment;
Fulfillment::where('order_id', 9999999999)->delete();
```

---

## Quick Troubleshooting

| Problem | Check |
|---------|-------|
| "Connection refused" | Is Mosquitto running? `docker compose ps` |
| "TLS handshake failed" | For Laravel, use `MQTT_PORT=1883` and `MQTT_TLS_ENABLED=false`; for external clients, verify `mqtt.sushi.catering:443` routes to `127.0.0.1:9443` |
| "Not authorized" | Check username/password match in `.env` and `docker-compose.yml` |
| Tinker publish works but no message in dashboard | Check Node-RED is connected to the internal broker |
| Subscriber doesn't receive messages | Make sure subscriber is running. Check topic name matches exactly |
| Shopify metafield errors on test data | Expected — use a real order_id for metafield sync testing |

---

## Ports Reference

| Port | Protocol | Purpose |
|------|----------|---------|
| 443 | MQTT+TLS | Public external MQTT via `mqtt.sushi.catering` |
| 9443 | MQTT+TLS | Local Nginx stream target for Mosquitto TLS |
| 1883 | MQTT plain | Local Laravel/internal broker access only |
| 1885 | HTTP | Local Node-RED target |
| 8885 | HTTPS | Public dashboard at `mqtt.sushi.catering:8885` |
