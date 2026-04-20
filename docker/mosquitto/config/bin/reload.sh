#!/bin/sh
set -eu

# This helper keeps certificate rotation and config reloads predictable:
# 1. copy the latest aaPanel certificate into a path Mosquitto can read
# 2. signal Mosquitto so password/config/cert changes are reloaded

RUNTIME_DIR="${MQTT_RUNTIME_DIR:-/mosquitto/runtime}"
CERT_SOURCE="${MQTT_TLS_CERT_FILE:?MQTT_TLS_CERT_FILE is required}"
KEY_SOURCE="${MQTT_TLS_KEY_FILE:?MQTT_TLS_KEY_FILE is required}"
CERT_DIR="$RUNTIME_DIR/certs"
CERT_TARGET="$CERT_DIR/server.crt"
KEY_TARGET="$CERT_DIR/server.key"

[ -s "$CERT_SOURCE" ] || { echo "ERROR: TLS cert file not found: $CERT_SOURCE"; exit 1; }
[ -s "$KEY_SOURCE" ] || { echo "ERROR: TLS key file not found: $KEY_SOURCE"; exit 1; }

mkdir -p "$CERT_DIR"
install -m 644 "$CERT_SOURCE" "$CERT_TARGET"
install -m 640 "$KEY_SOURCE" "$KEY_TARGET"
chown mosquitto:mosquitto "$CERT_TARGET" "$KEY_TARGET"

if [ "${1:-}" = "--sync-only" ]; then
  exit 0
fi

kill -HUP "$(pidof mosquitto)"
echo "Mosquitto certificates and configuration reloaded."
