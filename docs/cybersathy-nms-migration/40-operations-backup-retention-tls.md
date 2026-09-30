# Plan 40: Operations, Backup, Retention and TLS

> **Phase:** 4 (baseline) and 8 (final) · **Depends on:** 1, 2 · **Status:** Partial

## Goal
Make the new stack operable: backed up and restorable, sized, retained, encrypted in transit, and observable.

## Current Source / Reference
Legacy MySQL data directory under `var/docker/mysql`, container log rotation in `docker-compose.yml`, console commands `System\*`, `Cache\*`, `Supervisor\*`, `Logs\*`. The new compose file has no backup, no TLS, no healthchecks and no retention.

## Target Design
Every stateful component has a backup, a restore procedure that has been rehearsed, and a capacity model. Retention and compression are set per hypertable. TLS terminates at Nginx. Health is observable by Prometheus.

## Implementation Steps
1. **PostgreSQL backup:** nightly base backup plus continuous WAL archiving to a separate volume or host. Keep 14 daily and 8 weekly. Store the credential-encryption key **separately** from the backup, in a different location with different access.
2. **Restore drill:** monthly, into a scratch instance, followed by an automated integrity check (row counts, a login, a sample scoped query). A backup that has not been restored is not a backup.
3. **Redis:** AOF `everysec`, `maxmemory` with a policy that never evicts stream keys, stream `MAXLEN` per stream, and alerts on length and pending entries (defect F-09).
4. **Retention and compression:** apply the table in [data-model.md](data-model.md) as migrations plus a retention job (Plan 37). Every hypertable has a policy; a CI check fails if a hypertable has none.
5. **Capacity model:** a spreadsheet or script computing rows per day = devices × interfaces × poll frequency, ONUs × optical polls, events per day. Recorded with assumptions; re-run before each phase enabling more polling. Used to size disk, chunk intervals and continuous aggregates.
6. **TLS:** terminate at Nginx with modern ciphers and HSTS; internal traffic stays on the private Docker network; database and Redis are never published on host ports in production. Certificate renewal automated and monitored.
7. **Nginx hardening:** request timeouts, sane `client_max_body_size` per location (large only on the attachments route), security headers, rate limits on `/api/v1/auth`, restrict `/metrics` to the monitoring network, and add WebSocket timeouts.
8. **Containers:** run as non-root, add healthchecks, pin image versions (official upstream images only, no `meklis/*` images), resource limits, log rotation (as in the legacy compose), read-only root filesystem where possible.
9. **Configuration on start:** an `init` step runs `alembic upgrade head` and idempotent seed, and the API refuses to start against a schema behind the code (defect F-08).
10. **Runbooks:** start/stop, restore, rotate secrets, rotate the encryption key, add a device group to polling, disable a vendor's polling quickly, handle a poller storm.
11. **Alerting on the platform itself:** API error rate and latency, worker lag, stream pending count, scheduler last-success age, disk and chunk growth, backup age, certificate expiry.

## Database/API Impact
Retention and compression policies; no business schema change.

## Frontend Impact
A system-status page (ISP/admin only) showing service health, backup age and worker lag.

## Security / Access Rules
- Backups are encrypted and access-controlled. The encryption key and the backup are never stored together.
- Monitoring endpoints are not reachable from the internet.

## Acceptance Checks
- A restore drill succeeds and the integrity check passes; time-to-restore is recorded.
- Killing Redis and restarting it loses no acknowledged jobs.
- Every hypertable has retention and compression policies.
- The API refuses to start with a schema behind the code.
- Port scan of the host shows only Nginx (and any explicitly approved monitoring port).
- Certificate expiry alert fires in a test with a short-lived certificate.

## Risks
- Untested backups (K-12 adjacent). Mitigation: mandatory restore drill.
- Storing the encryption key next to the backup defeats encryption.
- Retention set before capacity is understood deletes data people need. Mitigation: model first, apply with a dry-run report.

## Definition of Done
Backup, restore drill, retention, TLS, healthchecks and platform alerts are in place in staging, with the runbooks reviewed by the person who will be on call.

## Rollback
Policies are individually reversible. Never remove a backup policy without a replacement.

## Implementation notes (2026-09-28)
Done: non-root read-only containers, health checks, pinned images, Redis persistence and password, Nginx timeouts, body limits, headers and login rate limit, `/metrics` and `/ready` not public, migrations and seed on start, the API refusing a schema behind the code, `audit_logs` retention and compression.
Not done: backups and restore drill, TLS, capacity model, retention for other tables (they do not exist yet), platform alerts, runbooks.
