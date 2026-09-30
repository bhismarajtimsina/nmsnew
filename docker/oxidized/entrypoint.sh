#!/bin/bash
set -euo pipefail

CONFIG_DIR=/home/oxidized/.config/oxidized
PLACEHOLDER_PID=""
REAL_STARTED=0

mkdir -p "$CONFIG_DIR"
envsubst '$OXIDIZED_THREADS $OXIDIZED_INTERVAL $OXIDIZED_TIMEOUT' \
  < "$CONFIG_DIR/config.example" \
  > "$CONFIG_DIR/config"

start_placeholder() {
  if [[ -n "${PLACEHOLDER_PID}" ]] && kill -0 "${PLACEHOLDER_PID}" 2>/dev/null; then
    return
  fi

  python3 -u /opt/oxidized-placeholder.py &
  PLACEHOLDER_PID=$!
  echo "Oxidized placeholder started on :8888 with pid ${PLACEHOLDER_PID}"
}

stop_placeholder() {
  if [[ -n "${PLACEHOLDER_PID}" ]] && kill -0 "${PLACEHOLDER_PID}" 2>/dev/null; then
    kill "${PLACEHOLDER_PID}" 2>/dev/null || true
    wait "${PLACEHOLDER_PID}" 2>/dev/null || true
  fi
  PLACEHOLDER_PID=""
}

start_placeholder
echo "Sleep 10 seconds before starting..."
sleep 10

while true; do
  status_code=$(curl --write-out '%{http_code}' --silent --output /dev/null \
    http://wca-nginx/api/v1/component/oxidized/internal/devices-list || true)

  if [[ "${status_code}" == "200" ]]; then
    echo "Oxidized devices list is ready, starting real service..."
    stop_placeholder
    REAL_STARTED=1
    exec /sbin/my_init
  fi

  if [[ "${status_code}" == "404" ]]; then
    echo "No oxidized devices configured yet, keeping placeholder page online."
  else
    echo "Oxidized source not ready (HTTP ${status_code:-unknown}), keeping placeholder page online."
  fi

  sleep 30
done
