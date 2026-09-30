#!/usr/bin/env bash
# Regenerate backend/app/schema_snapshot.json from the migrations, on a throwaway database.
# Run after adding or changing a migration; a test fails until the committed snapshot matches what the migrations produce.
set -euo pipefail
cd "$(dirname "$0")/.."
ID="cs-snap-$$"; NET="$ID-net"; PASS="$(python3 -c 'import secrets; print(secrets.token_urlsafe(12))')"
cleanup() { docker rm -f -v "$ID-pg" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker build -q --target test -t cybersathy-api-test backend/ >/dev/null
docker network create "$NET" >/dev/null
docker run -d --name "$ID-pg" --network "$NET" -e POSTGRES_PASSWORD="$PASS" timescale/timescaledb:2.17.2-pg16 >/dev/null
for _ in $(seq 1 60); do docker exec "$ID-pg" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
# See tools/test-backend.sh: the container's own readiness can be true slightly before its DNS record is queryable
# by another container on this network.
for _ in $(seq 1 30); do
  docker run --rm --network "$NET" cybersathy-api-test python3 -c "import socket; socket.gethostbyname('$ID-pg')" >/dev/null 2>&1 && break
  sleep 1
done
run() { docker run --rm --network "$NET" -e POSTGRES_HOST="$ID-pg" -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD="$PASS" -e POSTGRES_DB=postgres \
  -e REDIS_URL=redis://unused:6379/0 -v "$PWD/backend/app:/app/app" cybersathy-api-test python -m app.cli "$@"; }
run db upgrade >/dev/null 2>&1
run db snapshot --write
