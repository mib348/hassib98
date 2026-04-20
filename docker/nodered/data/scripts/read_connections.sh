#!/bin/sh
# =============================================================
# read_connections.sh
# Reads the Mosquitto broker log via Docker Engine API.
# Node-RED's exec node calls this script every 60 seconds.
# It uses the Docker unix socket (mounted at /var/run/docker.sock)
# to exec 'tail' inside the mosquitto container and stream
# the last 5000 lines of the log file.
# =============================================================

# Try the shared volume first (fastest, no Docker API overhead)
LOG_FILE="/mosquitto-logs/mosquitto.log"
if [ -f "$LOG_FILE" ]; then
  tail -5000 "$LOG_FILE"
  exit 0
fi

# Fallback: use Docker Engine API via the mounted unix socket
SOCK="/var/run/docker.sock"
if [ ! -S "$SOCK" ]; then
  echo "ERROR: Neither log volume nor docker socket available"
  exit 1
fi

# Step 1: Create an exec instance inside the 'mosquitto' container
EXEC_JSON=$(curl -s --unix-socket "$SOCK" \
  -H "Content-Type: application/json" \
  -d '{"Cmd":["tail","-5000","/mosquitto/log/mosquitto.log"],"AttachStdout":true,"Tty":true}' \
  "http://localhost/v1.41/containers/mosquitto/exec")

# Step 2: Extract the exec ID from {"Id":"abc123..."}
EXEC_ID=$(echo "$EXEC_JSON" | sed 's/.*"Id":"//;s/".*//')

if [ -z "$EXEC_ID" ]; then
  echo "ERROR: Could not create exec instance"
  exit 1
fi

# Step 3: Start exec and stream the log output to stdout
curl -s --unix-socket "$SOCK" \
  -H "Content-Type: application/json" \
  -d '{"Detach":false,"Tty":true}' \
  "http://localhost/v1.41/exec/$EXEC_ID/start"
