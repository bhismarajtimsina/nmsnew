# Plan 39: External Integrations and Auxiliary Components

> **Phase:** 7 · **Depends on:** 4, 9, 36 · **Status:** Not started · **Owns:** the components no other plan covers

## Goal
Carry over the integrations and small components that make the NMS useful day to day, so the cutover does not silently remove them.

## Current Source / Reference
`UsersideIntegration`, `MikBillIntegration`, `NoDenyPlus`, `SensorDevices`, `Attachments`, `QrGenerator`, and the config-backup, pinger and auth-proxy pieces the legacy stack gets from `Oxidized`, `Pinger` and its external-apps proxy. Related `.env` parameters are in [env-parameter-mapping.md](env-parameter-mapping.md).

## Target Design
Each integration is an adapter behind an `Integration` interface with its own config (secrets encrypted), health check, rate limit and audit trail. Adapters run in workers, not in request handlers, and never block the API. Integrations that write back to an external system are Dangerous by default and off until enabled.

## Implementation Steps
- **Userside:** sync devices in (name, IP, group) and push comments and stat exclusion. `USERSIDE_DELETE_NOT_EXISTED_DEVICES` becomes a Dangerous setting, off by default, with a dry-run report before any deletion.
- **MikBill:** global search field, ONT coordinate writing (`MIKBILL_SET_ONT_COORDINATES`), diagnostic-interface lookups.
- **NoDeny:** database comparison of descriptions and topology, read-only against the external database; credentials in the secret store.
- **Config backup:** our own `config-backup` worker ([own-components.md](own-components.md#35-config-backup-worker)) replaces Oxidized: versioned, encrypted, diffable, disabled by default, scheduled through Plan 37. `config_backups.view` gates the UI.
- **Pinger:** our own `pinger` worker (Plan 12, D-20). The legacy recalculation job is not carried over; state is computed by the worker.
- **Proxied apps:** Nginx `auth_request` against `GET /api/v1/auth/verify` (Plan 36, D-21). Grafana, Prometheus and Alertmanager stay reachable only through it.
- **Sensor devices:** read, configure, and relay-mode actions (Dangerous, Plan 38).
- **Attachments:** upload, view, delete with size and type limits, virus-scan hook, storage on a mounted volume, metadata in PostgreSQL.
- **QR generator:** device and bulk labels; bulk is bounded.
- **NetFlow:** the upstream open-source collector stays optional; whether its dashboards carry over is decided in Plan 31.
- **Swagger:** replaced by FastAPI docs at `/api/v1/docs`, restricted to authenticated users in production.
- **phpMyAdmin:** dropped; D-12 records the replacement decision.

## Database/API Impact
`integration_configs` (secrets encrypted), `attachments`, `sensor_devices`, `sensor_readings` (hypertable), `config_backups`, `config_backup_runs`. Endpoints under `/api/v1/integrations/*`, `/api/v1/attachments`, `/api/v1/sensors`, `/api/v1/config-backups`, `/api/v1/qr`, `/api/v1/pinger`.

## Frontend Impact
Integration settings page (per integration: enabled, health, last sync, error); existing pages for attachments, QR, sensors and config backups move to typed clients.

## Security / Access Rules
- Integration secrets are write-only and never shown after entry.
- Outbound calls use an allow-list of hosts from the integration config; redirects are not followed to other hosts.
- Inbound webhooks verify a shared secret and are rate-limited.
- Attachments are never executed or served inline as HTML; content type is enforced.

## Acceptance Checks
- Each integration has a health check and a documented "disabled" state that leaves the rest of the system working.
- A failing external system does not slow the API (timeouts, circuit breaker).
- Userside deletion is refused without an explicit setting and produces a dry-run report first.
- Attachment upload rejects oversized and disallowed types.
- A request to a proxied app without a valid `cs_session` never reaches the upstream, and a valid one opens Grafana with the user's identity from `X-Auth-User`.
- The `pinger` worker reports up/down transitions after the configured number of missed replies, verified with a fake ICMP transport and a loopback test.

## Risks
- Two-way sync deleting devices in the NMS because an external list is incomplete. Mitigation: dry-run and off by default.
- Integration credentials leaking through logs or exports.
- Legacy quirks in integrations are undocumented; contract tests from recorded traffic are needed before replacing behavior.

## Definition of Done
Every integration in [parity-inventory.md](parity-inventory.md) is `Migrated`, `Kept as-is` or `Dropped` with a reason, and its checks pass.

## Rollback
Each adapter can be disabled independently; the legacy component keeps serving until cutover.
