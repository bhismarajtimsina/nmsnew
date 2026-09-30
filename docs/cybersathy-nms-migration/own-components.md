# Components We Build Ourselves

**Rule:** the new CyberSathy-NMS stack contains no code, container image, package, command or naming from the legacy project. Where the legacy system had a component, we build our own. The legacy stack keeps running untouched until cutover as a separate system; nothing is shared with it at runtime.

Open-source infrastructure with **official upstream images** is not part of that rule and is used as-is: Nginx, PostgreSQL + TimescaleDB, Redis, Prometheus, Alertmanager, Grafana, Loki, Alloy, and the standard exporters. The legacy compose uses forked Alertmanager, node-exporter and cAdvisor images; the new stack uses the upstream projects' own images (`prom/alertmanager`, `prom/node-exporter`, upstream cAdvisor), with versions pinned.

Recorded as decisions D-25 to D-27 in [decisions.md](decisions.md).

## 1. Naming and conventions

| Thing | Legacy | CyberSathy-NMS |
|---|---|---|
| Browser storage keys | `wca_auth_key`, `wca_user` | `cs_auth_key`, `cs_user` |
| Session cookie for proxied apps | `Token` | `cs_session` (HttpOnly, Secure, SameSite=Lax) |
| API credential header | `X-Auth-Key` | `Authorization: Bearer <token>` |
| Client IP header | `WCA-Real-Ip` | Standard `X-Forwarded-For`, trusted only from configured proxies |
| Containers and networks | `wca-*` | `cybersathy-*` |
| Admin CLI | `wca <command>` | `python -m app.cli <command>` |
| Metric prefix | mixed | `cybersathy_`, with one deliberate exception: `pinger_host_status` (§3.1) keeps its exact legacy name because a byte-for-byte-ported Alertmanager rule's PromQL already depends on it |
| Python package | n/a | `app` (backend), one module per service |

Legacy names appear in exactly one place: the compatibility shim ([Plan 41](41-legacy-api-compatibility.md)), which accepts `X-Auth-Key` and `Token` from callers that have not moved yet. Native endpoints never accept them. There is no bridge for the old browser keys: users sign in again at cutover.

## 2. What replaces what

| Legacy component | Replacement | Owner plan |
|---|---|---|
| RoadRunner + PHP API | FastAPI | 1, 41 |
| PHP/Swoole WebSocket server | FastAPI WebSocket | 22 |
| PHP schedule executor | `scheduler` service | 37 |
| Go ICMP pinger | `pinger` worker (section 3.1) | 12 |
| SNMP trap listener | `trap-receiver` worker (3.2) | 21 |
| Go external-apps auth proxy | Nginx `auth_request` against the API (3.3) | 36 |
| Web terminal (ttyd) | `console-gateway` + xterm.js (3.4) | 38 |
| Oxidized config backup | `config-backup` worker (3.5) | 39 |
| switcher-core vendor code and YAML | Our own vendor, OID and model registries (3.6) | 5, 6, 8 |
| `NotificationSenderService.php` (forked `send-notify` processes) | `notification-sender` worker (3.7) | 29 |
| Forked Alertmanager, node-exporter, cAdvisor images | Official upstream images | 31, 40 |
| `wca` CLI | `python -m app.cli` | 9, 36, 37, 40 |
| phpMyAdmin | none (D-12) | 39 |

## 3. Designs

### 3.1 `pinger` worker (ICMP up/down) - built (D-20)

- Python 3.12 asyncio, `icmplib`. Unprivileged ICMP (Linux datagram sockets, `privileged=False`) by default - no
  capability needed at all, not even `CAP_NET_RAW`; that flag exists only for a kernel whose `ping_group_range`
  disallows the unprivileged path.
- Targets: devices with `polling_enabled` and `polling_owner = cybersathy` (Plan 11).
- Current implementation checks targets one at a time in a plain loop, not a concurrent batch. A global
  packets-per-second cap, batching and jitter are a deferred performance refinement (Plan 12), not a correctness
  requirement of the debounce or the gauge below.
- State machine per device: `up` → `down` after N consecutive misses (default 3), `down` → `up` after 1 reply (both
  configurable, `app/pinger/check.py`). Only a real transition writes to the `device_ping_history` hypertable; every
  check updates `device_ping_status` and the gauge below.
- **No event or alarm is created here, by design, matching the legacy system exactly:** reading the real legacy
  pipeline (`components/Pinger/Controllers/Controller.php`) confirmed the Go pinger's own verdict never becomes a
  `c_events` row either - it only updates `c_pinger_statuses` and a Prometheus gauge. The alarm is Alertmanager's
  already-ported `pinger_host_down` rule (`pinger_host_status <= 0`, D-24), reached through Plan 20's webhook. This
  worker reproduces that exactly: it exports a gauge literally named `pinger_host_status` (**not**
  `cybersathy_pinger_host_status` - a deliberate, documented exception to the usual metric prefix below, since
  renaming it would mean editing a legacy-ported rule kept byte-for-byte otherwise) with `ip`, `device_id` and `name`
  labels, and decides no alarm itself.
- Runs on the management network only and holds no device credentials.
- **Verification:** unit tests for the debounce with no ICMP at all, a fake `async_ping` for the "down" and
  error-handling cases, and one real loopback integration test (127.0.0.1) for the "up" path. No test pings any
  other address, real or reserved: `icmplib` sends with `sendto()`, which Plan 33's connect()-based network guard
  does not intercept, so this is enforced by discipline in the tests themselves, not by that guard.

### 3.2 `trap-receiver` worker

- Python asyncio SNMP notification receiver (`pysnmp`), UDP on a configured port, published on the host through Nginx-independent port mapping.
- Accepts a trap only when the source address matches a device in the allow-list (cached from `devices`, refreshed on change). Community or SNMPv3 credentials are validated against that device's access profile. Everything else is dropped and counted.
- Per-source rate limit and a global cap; unknown traps are sampled and stored, never trusted as instructions.
- Decodes through the trap profiles (Plan 21) into `trap_history`, with device links when known. **Not** into
  `events`: the legacy trap pipeline (`components/TrapService/`) never generated an alarm from a trap either - no
  severity, dedup or resolve concept exists there - so `trap_history` is full parity with `c_trap_logs`, a rolling
  diagnostic log, not a new alarm bridge. (Corrected 2026-09-29; this bullet previously assumed "normalized events"
  without having verified the real legacy behavior.)
- **Verification:** replay recorded PDUs from fixtures; validate trap OIDs with `tools/bdcom-trap-audit.py`.

### 3.3 Auth gateway for proxied apps (Grafana, Prometheus, Alertmanager, config-backup viewer)

- No extra service. Nginx `auth_request` sends each request to `GET /api/v1/auth/verify?app=<name>`.
- The API checks the `cs_session` cookie (or a Bearer token), the user's `external_apps.<name>.view` permission and their scope, and answers 200 with `X-Auth-User` and `X-Auth-Role`, or 401/403.
- Nginx forwards those headers. Grafana runs in its auth-proxy mode and trusts them **only** from the Nginx network. Prometheus and Alertmanager have no login of their own, so they are reachable **only** through this gate.
- Session cookies are scoped per path, short-lived, and revoked with the session.
- **Verification:** contract tests with a stub upstream; a test proving a request without a valid session never reaches the upstream.

### 3.4 `console-gateway`

- A separate FastAPI service with a WebSocket endpoint. Transports: `asyncssh` and `telnetlib3` (D-18). The browser terminal is xterm.js in the Vue frontend.
- Flow: the API issues a single-use session ticket after permission, scope and confirmation checks (Plan 26). The gateway redeems it, fetches and decrypts the device credential itself, and opens the session. The API never sees the plaintext credential.
- Full transcript stored with actor and device, secrets typed at password prompts redacted, idle timeout, per-device concurrent-session limit, and an admin kill switch.
- Never a shell on the host; connections only to devices in the inventory.
- **Verification:** local SSH and telnet test servers in CI.

### 3.5 `config-backup` worker

- Per-vendor command sets (running configuration) run through the same driver interface as the console gateway. Scheduled by Plan 37, one session per device, concurrency cap, off-peak window.
- Stores versions in PostgreSQL: `config_backups` (device, taken_at, sha256, size, encrypted content) and `config_backup_runs` (status, error, duration). Unchanged configs are deduplicated by hash. Retention keeps the last N versions plus one per month.
- Change detection raises a `config.changed` event; the UI shows a diff between any two versions.
- Configs contain secrets, so content is encrypted at rest, viewing needs `config_backups.view`, and every view is audited.
- **Disabled by default.** It reads from devices through the CLI, so enabling it per device group is an operator step under [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).
- **Verification:** recorded transcripts and a fake transport.

### 3.6 Vendor, OID and model registries

- The registries in Plans 5, 6 and 8 are **our own data**, with our own schema, validators and tests. There is no runtime dependency on switcher-core, its PHP modules or its YAML.
- The legacy YAML and PHP modules are read **once, as a reference**, to learn what a device family exposes. Every OID we keep is re-derived from the vendor's MIB with `tools/bdcom-mib-oid-map.py`-style tooling and confirmed by a fixture. What cannot be confirmed offline is not enabled.
- Results are entries in our tables (`oid_definitions`, `oid_profile_entries`, `device_models`), each with a source note, a safety level, bounds and a version.

### 3.7 `notification-sender` worker - built (Plan 29)

- Python asyncio, no OS process forking. Polls `notifications` for rows due to send (`app/notifications/sender.py`'s
  `get_due`), idles 3 seconds when nothing is found, and dispatches the rest concurrently through an
  `asyncio.Semaphore` capped at 20 in flight - the direct equivalent of legacy's own `COUNT_PROCS = 20` forked
  `wca notifications:send-notify <id>` processes (`NotificationSenderService.php`), same cap, same
  never-dispatch-the-same-notification-twice guarantee (an `in_flight` id set standing in for legacy's own
  `$processes` map), without a process per message.
- Each concurrent send acquires its own pooled database connection - two sends sharing one `asyncpg.Connection`
  concurrently would corrupt each other's wire protocol state, the same reason each forked legacy process needed its
  own connection too.
- A notification with no linked event is canceled rather than sent - ported from
  `NotificationSenderService.php`'s own pre-send guard, minus its "or action" half: this system has no
  action-linked notification path at all (`ActionGenerator`'s audit events - "device added", "user logged in" -
  are out of scope, [29-notifications.md](29-notifications.md)), so every real row here always carries an event.
- **Not ported:** `allowedToSendByUplinkChecking` (the `check_uplink` column's consumer) - it needs a device-link/
  topology graph this system doesn't have at all yet. Legacy itself returns "allowed" unconditionally when its
  `links` component isn't installed, which is this system's actual state, so there is nothing to build yet that
  wouldn't be dead code; `check_uplink` is `false` on every seeded event config regardless.
- **No real channel yet.** `UnconfiguredChannel` is what the process hands `run_cycle` until Telegram/email exist -
  every send fails cleanly and is recorded that way (status `failed`, never retried), the same as a genuine channel
  outage would be, rather than the process pretending to succeed or refusing to start at all.
- **Verification:** a real-database integration suite (`test_notification_sender.py`, `test_notification_service.py`)
  - dispatch, cancellation, status transitions, the in-flight skip, and the concurrency cap under a deliberately slow
  fake channel holding several sends open at once.

## 4. Third-party pieces we keep (upstream, official images)

| Piece | Why |
|---|---|
| Nginx | Reverse proxy and auth gate |
| PostgreSQL + TimescaleDB | Database |
| Redis | Streams and cache |
| Prometheus, Alertmanager | Metrics and metric-based alarm engine (D-24) |
| Grafana, Loki, Alloy | Dashboards and logs |
| SNMP exporter, Blackbox exporter, node-exporter, cAdvisor | Standard exporters, official images |
| goflow2 (NetFlow/sFlow collector) | Upstream open-source project; optional, decided in Plan 31 |

## 5. Consequences for the plan

- Plans 12, 21, 36, 38, 39, 3, 24, 25 and 41 change as described above. Their status stays **Not started** unless noted in [STATUS.md](STATUS.md).
- Four new services join the runtime (`pinger`, `trap-receiver`, `console-gateway`, `config-backup`). Each is new code on the critical path, so each ships behind an ownership switch (as with `polling_owner`) and runs in observe mode first (risk K-21).
- The backend code written before this decision still uses the old names ([STATUS.md](STATUS.md), defect F-15).
