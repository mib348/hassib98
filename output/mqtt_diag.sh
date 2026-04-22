#!/bin/bash
# MQTT Diagnostic Script — run on the production server as root
# Usage: bash mqtt_diag.sh

echo "=========================================="
echo " MQTT DIAGNOSTIC REPORT"
echo " $(date)"
echo "=========================================="

echo ""
echo "=== 1. SUPERVISOR STATUS ==="
supervisorctl status

echo ""
echo "=== 2. DEV MQTT SUBSCRIBER LOG (last 30 lines) ==="
tail -30 /www/wwwroot/dev.sushi.catering/storage/logs/mqtt-subscriber.log 2>/dev/null || echo "File not found"

echo ""
echo "=== 3. APP MQTT SUBSCRIBER LOG (last 30 lines) ==="
tail -30 /www/wwwroot/app.sushi.catering/storage/logs/app.mqtt-subscriber.log 2>/dev/null || echo "File not found"

echo ""
echo "=== 4. DEV .ENV MQTT SETTINGS ==="
grep -i 'MQTT\|APP_ENV' /www/wwwroot/dev.sushi.catering/.env 2>/dev/null || echo ".env not found"

echo ""
echo "=== 5. APP .ENV MQTT SETTINGS ==="
grep -i 'MQTT\|APP_ENV' /www/wwwroot/app.sushi.catering/.env 2>/dev/null || echo ".env not found"

echo ""
echo "=== 6. DOCKER CONTAINERS ==="
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null || echo "Docker not available"

echo ""
echo "=== 7. MOSQUITTO LOG (last 50 lines) ==="
docker exec mosquitto tail -50 /mosquitto/log/mosquitto.log 2>/dev/null || echo "Cannot read mosquitto log"

echo ""
echo "=== 8. MOSQUITTO ACTIVE CONNECTIONS (from log) ==="
docker exec mosquitto tail -5000 /mosquitto/log/mosquitto.log 2>/dev/null | grep -E "New client connected|Client .* disconnected" | tail -30

echo ""
echo "=== 9. PORT 1883 LISTENING ==="
ss -tlnp | grep 1883

echo ""
echo "=== 10. PORT 8883 LISTENING ==="
ss -tlnp | grep 8883

echo ""
echo "=== 11. TEST MQTT CONNECTION (dev) ==="
cd /www/wwwroot/dev.sushi.catering && php artisan tinker --execute="
try {
    \$mqtt = \PhpMqtt\Client\Facades\MQTT::connection('subscriber');
    echo 'DEV: Connected OK as ' . env('MQTT_SUBSCRIBER_CLIENT_ID', 'laravel-subscriber') . PHP_EOL;
    \$mqtt->disconnect();
} catch (\Throwable \$e) {
    echo 'DEV: FAILED - ' . \$e->getMessage() . PHP_EOL;
}
" 2>&1

echo ""
echo "=== 12. TEST MQTT CONNECTION (app) ==="
cd /www/wwwroot/app.sushi.catering && php artisan tinker --execute="
try {
    \$mqtt = \PhpMqtt\Client\Facades\MQTT::connection('subscriber');
    echo 'APP: Connected OK as ' . env('MQTT_SUBSCRIBER_CLIENT_ID', 'laravel-subscriber') . PHP_EOL;
    \$mqtt->disconnect();
} catch (\Throwable \$e) {
    echo 'APP: FAILED - ' . \$e->getMessage() . PHP_EOL;
}
" 2>&1

echo ""
echo "=== 13. SUPERVISOR CONFIGS ==="
echo "--- dev-mqtt-subscriber.conf ---"
cat /etc/supervisor/conf.d/dev-mqtt-subscriber.conf 2>/dev/null || echo "Not found"
echo ""
echo "--- app.mqtt-subscriber.conf ---"
cat /etc/supervisor/conf.d/app.mqtt-subscriber.conf 2>/dev/null || echo "Not found"

echo ""
echo "=== 14. PHP-MQTT PACKAGE VERSIONS ==="
echo "DEV:"
grep '"php-mqtt/client"' /www/wwwroot/dev.sushi.catering/composer.lock 2>/dev/null | head -1
echo "APP:"
grep '"php-mqtt/client"' /www/wwwroot/app.sushi.catering/composer.lock 2>/dev/null | head -1

echo ""
echo "=========================================="
echo " END OF DIAGNOSTIC REPORT"
echo "=========================================="
