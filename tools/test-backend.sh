#!/usr/bin/env bash
# Run the backend test suite against a throwaway PostgreSQL (TimescaleDB) and Redis on a private Docker network.
#
#   tools/test-backend.sh                 run everything
#   tools/test-backend.sh tests/test_auth.py -k lockout      pass pytest arguments through
#
# Touches nothing outside its own containers, and the tests themselves refuse any connection except loopback and those two.
# It never contacts a device.
set -euo pipefail

cd "$(dirname "$0")/.."
ID="cs-test-$$"
NET="$ID-net"
PASS="$(python3 -c 'import secrets; print(secrets.token_urlsafe(12))')"

cleanup() {
  # -v: also remove each container's anonymous volume. Without it, every run leaks one - 296 had accumulated across
  # this project's testing and, with dangling images and build cache, filled the disk (see STATUS.md).
  docker rm -f -v "$ID-pg" "$ID-redis" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker build -q --target test -t cybersathy-api-test backend/ >/dev/null
REPO_MOUNT=(-v "$PWD/docker-compose.cybersathy.yml:/repo/docker-compose.cybersathy.yml:ro" -v "$PWD/tools:/repo/tools:ro" \
  -v "$PWD/BDCOM_MIBS:/repo/BDCOM_MIBS:ro" -v "$PWD/NMS_BDCOM_MIBS:/repo/NMS_BDCOM_MIBS:ro")
docker network create "$NET" >/dev/null
docker run -d --name "$ID-pg" --network "$NET" -e POSTGRES_PASSWORD="$PASS" timescale/timescaledb:2.17.2-pg16 >/dev/null
docker run -d --name "$ID-redis" --network "$NET" redis:7.4-alpine >/dev/null

for _ in $(seq 1 60); do
  docker exec "$ID-pg" pg_isready -U postgres >/dev/null 2>&1 && docker exec "$ID-redis" redis-cli ping >/dev/null 2>&1 && break
  sleep 1
done

# A container's own readiness (pg_isready via `docker exec`, which bypasses the bridge network entirely) can be true
# slightly before its DNS record is queryable by another container on this network. Wait for that too, or the test
# container's first connection attempt fails with "Temporary failure in name resolution" on an otherwise-healthy pair.
for _ in $(seq 1 30); do
  docker run --rm --network "$NET" cybersathy-api-test python3 -c "
import socket
socket.gethostbyname('$ID-pg')
socket.gethostbyname('$ID-redis')
" >/dev/null 2>&1 && break
  sleep 1
done

docker run --rm --network "$NET" \
  -e POSTGRES_HOST="$ID-pg" -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD="$PASS" -e POSTGRES_DB=postgres \
  -e REDIS_URL="redis://$ID-redis:6379/0" -e TEST_ALLOWED_HOSTS="$ID-pg,$ID-redis" \
  -e FORWARDED_ALLOW_IPS=172.16.0.0/12 \
  "${REPO_MOUNT[@]}" \
  cybersathy-api-test pytest -q -p no:cacheprovider "${@:-tests}"
