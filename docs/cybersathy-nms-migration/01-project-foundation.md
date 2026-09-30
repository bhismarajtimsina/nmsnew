# Plan 1: Project Foundation

> **Phase:** 1 · **Depends on:** 35 · **Status:** Done · **Owns:** F-04, F-05, F-06, F-07, F-08, F-09, F-12

## Goal
Create the CyberSathy-NMS base runtime without changing the existing production behavior.

## Current Source / Reference
Current Docker services live in `docker-compose.yml`, with Nginx, RoadRunner, Redis, MySQL, WebSocket, scheduler, and monitoring services.

## Target Design
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add Docker services: `cybersathy-nginx`, `cybersathy-api`, `cybersathy-postgres`, `cybersathy-redis`, `cybersathy-frontend`.
- Add FastAPI endpoints: `GET /health`, `GET /ready`, `GET /metrics`.
- Add `.env` config loading.
- Add structured JSON logging.
- Configure Nginx routes: `/` to frontend, `/api/v1` to FastAPI, `/ws` to FastAPI realtime.
- Fix F-04: `/ready` returns only `ok` or `error` per check and writes the exception to the log; `/health` returns status only, not the environment.
- Fix F-05: `/metrics` is reachable only from the monitoring network, and the metric `path` label uses the route template (`/devices/{id}`), never the raw URL.
- Fix F-06: run Uvicorn with `--proxy-headers` and `--forwarded-allow-ips` set to the Nginx address, and test the recorded client IP end to end.
- Fix F-07: create an asyncpg pool at startup, hand out one connection per request through a dependency, close on shutdown.
- Fix F-08: an init step runs `alembic upgrade head` and the idempotent seed; the API refuses to start against a schema behind the code.
- Fix F-09: Redis with AOF `everysec`, a password, and no eviction of stream keys.
- Fix F-12: non-root container user, healthchecks, pinned image versions, and no default database password in code or compose (fail fast on missing variables).
- Harden `docker/cybersathy-nginx/default.conf`: request timeouts, WebSocket timeouts, a small default `client_max_body_size` (large only on the attachments route), rate limit on `/api/v1/auth`, security headers.

## Database/API Impact
Only connectivity checks are required in this phase. No schema migration is run.

## Frontend Impact
Frontend should open from Nginx but no UI migration is required yet.

## Security / Access Rules
Do not expose private debug data from health endpoints.

## Acceptance Checks
- Frontend opens.
- API health works.
- PostgreSQL connects.
- Redis connects.
- No RoadRunner service is used by the new foundation.
- `/ready` failure body contains no exception text.
- The client IP stored in `audit_logs` equals the caller's address behind Nginx.
- A test stream entry survives a Redis restart.
- The API container runs as non-root and reports healthy.
- Starting the API against an empty database applies migrations and seed, and starting it against an older schema refuses.

## Risks
- Accidentally changing current Docker routing before parity.

## Definition of Done
Defects F-04 to F-09 and F-12 are closed and covered by CI checks; `STATUS.md` updated.

## Rollback
The foundation runs beside the legacy stack on its own network and port. Stopping `docker-compose.cybersathy.yml` restores the previous state. No legacy routing changes before Plan 34.

## Implementation notes (2026-09-28)
Closed F-04 to F-09 and F-12. Verified by running the real Compose stack twice as a separate temporary project (the second time against the finished code): init applied all seven migrations and the seed, the API waited for it, and Nginx started only after the API was healthy. The drift check passed on the live database and re-running init changed nothing.
- `/ready` and `/health` reveal nothing; `/metrics` and `/ready` are not proxied by Nginx (Prometheus scrapes the API over the internal network); metric labels use route templates.
- The client address comes from `ProxyHeadersMiddleware` with an explicit trusted list (`FORWARDED_ALLOW_IPS`). A spoofed `X-Forwarded-For` from outside never reaches the database (checked live). Nginx overwrites the header with the real address.
- Connection pool created at startup; one connection per request.
- Redis: password, AOF `everysec`, `noeviction`. A stream entry survived a restart.
- API container: non-root, read-only filesystem, all capabilities dropped, `no-new-privileges`, health check. Compose refuses to start when a secret is empty.
- Nginx: request timeouts, 1 MB default body, security headers, login rate limit (4 attempts, then 429), one internal auth-gate location per proxied app.
- The CI workflow file exists but only GitHub-root workflows run; see Plan 35 (R-05).
