#!/bin/sh
set -eu

# Fail fast when required inputs are missing so a broken deployment is obvious.
: "${MQTT_USER:?MQTT_USER is required}"
: "${MQTT_PASS:?MQTT_PASS is required}"
: "${MQTT_TLS_CERT_FILE:?MQTT_TLS_CERT_FILE is required}"
: "${MQTT_TLS_KEY_FILE:?MQTT_TLS_KEY_FILE is required}"

RUNTIME_DIR="${MQTT_RUNTIME_DIR:-/mosquitto/runtime}"
PASSWD_FILE="$RUNTIME_DIR/passwd"
CERT_DIR="$RUNTIME_DIR/certs"

mkdir -p "$RUNTIME_DIR" "$CERT_DIR" /mosquitto/data /mosquitto/log
touch /mosquitto/log/mosquitto.log

chown -R mosquitto:mosquitto /mosquitto/data /mosquitto/log "$RUNTIME_DIR"
chmod 750 /mosquitto/data /mosquitto/log "$RUNTIME_DIR" "$CERT_DIR"
chmod 640 /mosquitto/log/mosquitto.log

# Seed the password file only once. After that, operators can update the file
# in docker/mosquitto/runtime and reload Mosquitto without rebuilding the stack.
if [ ! -s "$PASSWD_FILE" ]; then
  mosquitto_passwd -b -c "$PASSWD_FILE" "$MQTT_USER" "$MQTT_PASS"
fi

chown mosquitto:mosquitto "$PASSWD_FILE"
chmod 600 "$PASSWD_FILE"

/bin/sh /mosquitto/config/bin/reload.sh --sync-only

exec mosquitto -c /mosquitto/config/mosquitto.conf
