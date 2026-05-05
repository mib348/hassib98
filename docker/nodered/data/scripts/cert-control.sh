#!/bin/sh
set -eu

# This helper keeps every certificate-related action in one place so the
# dashboard buttons stay simple and the operator can inspect one script when
# something needs to be debugged.

CERT_DIR="${MQTT_CERT_DIR:-/www/server/panel/vhost/cert/mqtt.sushi.catering}"
CERT_FILE="$CERT_DIR/fullchain.pem"
FALLBACK_CERT_FILE="/tls/fullchain.pem"
DOCKER_SOCKET="${DOCKER_SOCKET_PATH:-/var/run/docker.sock}"
RENEW_COMMAND="${AAPANEL_RENEW_COMMAND:-/www/server/panel/pyenv/bin/python -u /www/server/panel/class/acme_v2.py --renew=1}"

print_kv() {
  key="$1"
  shift
  value="$*"

  # Flatten newlines so Node-RED can parse the output line-by-line without
  # having to deal with multi-line shell values.
  value="$(printf '%s' "$value" | tr '\r\n' ' ' | sed 's/[[:space:]]\+/ /g; s/^ //; s/ $//')"
  printf '%s=%s\n' "$key" "$value"
}

date_to_epoch() {
  date_value="$1"

  # The OpenSSL certificate timestamps are stable, but shell `date -d` parsing is
  # not stable across container images. Node.js is already present in the Node-RED
  # container, so we use it as the portable date parser for certificate checks.
  node -e "const value = process.argv[1]; const epoch = Date.parse(value); if (Number.isNaN(epoch)) { process.exit(1); } process.stdout.write(String(Math.floor(epoch / 1000)));" "$date_value"
}

resolve_certificate_file() {
  if [ -s "$CERT_FILE" ]; then
    RESOLVED_CERT_FILE="$CERT_FILE"
    CERT_SOURCE_DIR="$CERT_DIR"
    CERT_STATUS_REASON="Using the configured certificate directory."
    return 0
  fi

  if [ -s "$FALLBACK_CERT_FILE" ]; then
    RESOLVED_CERT_FILE="$FALLBACK_CERT_FILE"
    CERT_SOURCE_DIR="/tls"
    CERT_STATUS_REASON="Configured certificate path was empty, so the mounted /tls certificate is being used."
    return 0
  fi

  RESOLVED_CERT_FILE="$CERT_FILE"
  CERT_SOURCE_DIR="$CERT_DIR"
  CERT_STATUS_REASON="No readable certificate file was found at $CERT_FILE or $FALLBACK_CERT_FILE."
  return 1
}

run_status() {
  checked_at="$(date '+%Y-%m-%d %H:%M:%S %Z')"
  domain="$(basename "$CERT_DIR")"

  if ! resolve_certificate_file; then
    print_kv state missing
    print_kv checked_at "$checked_at"
    print_kv cert_path "$RESOLVED_CERT_FILE"
    print_kv configured_path "$CERT_FILE"
    print_kv source_dir "$CERT_SOURCE_DIR"
    print_kv primary_domain "$domain"
    print_kv alt_names "-"
    print_kv issuer "Certificate file not found"
    print_kv subject "-"
    print_kv valid_from "-"
    print_kv valid_to "-"
    print_kv days_left "-1"
    print_kv status_reason "$CERT_STATUS_REASON"
    exit 0
  fi

  valid_from="$(openssl x509 -noout -startdate -in "$RESOLVED_CERT_FILE" | cut -d= -f2-)"
  valid_to="$(openssl x509 -noout -enddate -in "$RESOLVED_CERT_FILE" | cut -d= -f2-)"
  issuer="$(openssl x509 -noout -issuer -in "$RESOLVED_CERT_FILE" | sed 's/^issuer=//')"
  subject="$(openssl x509 -noout -subject -in "$RESOLVED_CERT_FILE" | sed 's/^subject=//')"
  alt_names="$(openssl x509 -in "$RESOLVED_CERT_FILE" -noout -ext subjectAltName 2>/dev/null | tail -n +2 | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g; s/DNS://g; s/, /,/g; s/,/, /g; s/^ //; s/ $//')"
  primary_domain="$(printf '%s' "$alt_names" | awk -F',' '{gsub(/^ /, "", $1); print $1}')"

  if [ -z "$primary_domain" ]; then
    primary_domain="$domain"
  fi

  now_epoch="$(date +%s)"
  if expiry_epoch="$(date_to_epoch "$valid_to")"; then
    days_left="$(( (expiry_epoch - now_epoch) / 86400 ))"
  else
    days_left="-"
    CERT_STATUS_REASON="$CERT_STATUS_REASON The certificate was loaded, but its expiry timestamp could not be converted into epoch time inside the dashboard container."
  fi

  state="healthy"
  if [ "$days_left" != "-" ]; then
    if [ "$days_left" -lt 0 ]; then
      state="expired"
    elif [ "$days_left" -le 7 ]; then
      state="critical"
    elif [ "$days_left" -le 21 ]; then
      state="warning"
    fi
  fi

  print_kv state "$state"
  print_kv checked_at "$checked_at"
  print_kv cert_path "$RESOLVED_CERT_FILE"
  print_kv configured_path "$CERT_FILE"
  print_kv source_dir "$CERT_SOURCE_DIR"
  print_kv primary_domain "$primary_domain"
  print_kv alt_names "${alt_names:--}"
  print_kv issuer "$issuer"
  print_kv subject "$subject"
  print_kv valid_from "$valid_from"
  print_kv valid_to "$valid_to"
  print_kv days_left "$days_left"
  print_kv status_reason "$CERT_STATUS_REASON"
}

docker_reload_mosquitto() {
  [ -S "$DOCKER_SOCKET" ] || {
    echo "ERROR: Docker socket is not mounted at $DOCKER_SOCKET" >&2
    return 1
  }

  create_payload='{"AttachStdout":true,"AttachStderr":true,"Tty":false,"Cmd":["/bin/sh","/mosquitto/config/bin/reload.sh"]}'
  create_response="$(curl --silent --show-error --unix-socket "$DOCKER_SOCKET" -H 'Content-Type: application/json' -d "$create_payload" http://localhost/containers/mosquitto/exec)"
  exec_id="$(printf '%s' "$create_response" | sed -n 's/.*"Id":"\([^"]*\)".*/\1/p')"

  [ -n "$exec_id" ] || {
    echo "ERROR: Could not create a Docker exec session for the mosquitto container." >&2
    echo "$create_response" >&2
    return 1
  }

  exec_output="$(curl --silent --show-error --unix-socket "$DOCKER_SOCKET" -H 'Content-Type: application/json' -d '{"Detach":false,"Tty":false}' "http://localhost/exec/$exec_id/start")"
  inspect_output="$(curl --silent --show-error --unix-socket "$DOCKER_SOCKET" "http://localhost/exec/$exec_id/json")"
  exit_code="$(printf '%s' "$inspect_output" | sed -n 's/.*"ExitCode":\([-0-9]*\).*/\1/p')"

  printf '%s\n' "$exec_output"

  [ "${exit_code:-1}" = "0" ] || {
    echo "ERROR: Mosquitto certificate reload failed." >&2
    return 1
  }
}

run_reload() {
  echo "Reloading the Mosquitto certificate from the mounted aaPanel files..."
  docker_reload_mosquitto
  echo "Mosquitto reload completed."
  echo "--- Certificate status after reload ---"
  run_status
}

run_renew() {
  panel_script="/www/server/panel/class/acme_v2.py"

  [ -f "$panel_script" ] || {
    echo "ERROR: aaPanel ACME script was not found at $panel_script" >&2
    exit 1
  }

  echo "Running aaPanel renewal command..."
  echo "$RENEW_COMMAND"
  renew_log="$(mktemp)"

  if sh -c "$RENEW_COMMAND" >"$renew_log" 2>&1; then
    renew_status=0
  else
    renew_status=$?
  fi

  cat "$renew_log"
  rm -f "$renew_log"

  [ "$renew_status" = "0" ] || {
    echo "ERROR: aaPanel renewal command failed." >&2
    exit "$renew_status"
  }

  echo "--- Reloading Mosquitto with the latest certificate files ---"
  docker_reload_mosquitto
  echo "--- Certificate status after renew and reload ---"
  run_status
}

case "${1:-status}" in
  status)
    run_status
    ;;
  reload)
    run_reload
    ;;
  renew)
    run_renew
    ;;
  *)
    echo "Usage: sh /data/scripts/cert-control.sh [status|reload|renew]" >&2
    exit 1
    ;;
esac
