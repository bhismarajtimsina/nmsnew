# Plan 11: Polling Engine

> **Phase:** 4 · **Depends on:** 6, 8, 9 · **Status:** Partial

## Goal
Build a safe profile-based polling engine.

## Current Source / Reference
Current polling logic is in `src/Infrastructure/Poller` and switcher-core modules.

## Target Design
Polling uses explicit profiles with bounds, timeouts, and per-vendor limits.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add profiles: safe discovery, switch basic, switch counters, switch errors, OLT basic, OLT ONU list, OLT optical, router basic.
- Enforce timeout, max rows, concurrency, retry, and backoff.
- Store poller execution history.
- Add the profiles the legacy pollers need and the first list misses: PON port loading, ONU identification, SFP optical, LLDP, bounded FDB (off by default), bounded ARP, sensors. See [parity-inventory.md](parity-inventory.md#3-pollers-srcinfrastructurepollerpollers).
- Set numeric defaults and keep them in `system_settings`: `max_rows` per profile, concurrency 1 per device, a per-vendor concurrency cap, interval jitter, retry cap with backoff, and a circuit breaker that pauses a device after N consecutive timeouts.
- Put all SNMP access behind a `Transport` interface. The fake transport counts requested rows and refuses unbounded requests.
- Choose the SNMP library with an **offline** spike against fixtures and a local simulator (D-17), never against devices.
- Enforce `polling_owner` (D-06): only the owner polls; both systems share a per-device request budget during overlap.
- Every poll result stores profile version, duration, row count and outcome.

## Database/API Impact
Add polling profile, job, result, and history tables.

## Frontend Impact
Poller status pages show profile, duration, result, and error.

## Security / Access Rules
User-triggered polling is scoped, rate-limited, and audited.

## Acceptance Checks
- No unbounded SNMP walk.
- Failed device does not block worker.
- Poller history is stored.
- Unsafe modules disabled by default.
- With the fake transport, no request exceeds a profile's `max_rows`.
- The circuit breaker opens after N timeouts and closes after the cool-down.
- A device owned by `legacy` is never polled by the new engine.

## Hardware sign-off
Rate budget behaves as configured on one low-risk device group. **Not verified against a device** until an operator runs it. See [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).

## Risks
- Unbounded walks can overload access switches or OLTs.
- Both systems polling one device during overlap doubles load (K-01). Mitigation: `polling_owner` and the shared budget.

## Definition of Done
Engine passes all fake-transport tests; owner flag enforced; hardware sign-off recorded or pending.

## Rollback
New pollers start disabled. Set `polling_owner=legacy` to return a device to the old poller immediately.

## Implementation notes (2026-09-28)
Built and tested with a fake transport (migration 0009, 293 tests in the suite, 49 deliberate breakages caught in total):
- **Transport interface** (`app/polling/transport.py`): the only door to a device. **This build has no real implementation.** The workers are wired to `DisabledTransport`, which refuses everything, so nothing built so far can reach a device. Choosing a real transport is D-17, still open; it will be decided by a spike against recorded fixtures and a simulator.
- **`BoundedTransport`** wraps any transport: a walk without both a row limit and a timeout is refused before anything is sent, a response longer than the limit is cut and flagged, timeouts are clamped to 200 ms to 30 s, retries to 0 to 3, and one request may name at most 64 OIDs.
- **Engine** (`app/polling/engine.py`): reads what it needs and releases the database connection before any network I/O; refuses, and records why, unless the device is owned by this system, has polling on, has an access profile, belongs to a vendor and family that are not switched off, and an active profile fits it; takes a per-device lock and a per-vendor slot; enforces a minimum interval between polls; stops at the **first** timeout so a dead device is asked once; scrubs credentials from any stored error; hands readings to a sink only on success; records every poll in `polling_results` (a compressed hypertable, 14 days).
- **Circuit breaker:** five consecutive failures open it for five minutes; the next attempt is a probe, and because the count stays at the threshold a failed probe opens it again at once.
- Scale transforms flag a value outside its physical range instead of trusting it.
- `POST /devices/{id}/poll` (rate-limited, audited, scoped, refuses at once with the reason), `GET /devices/{id}/poll-history`.

Not done: no OID profile has been authored (D-25), so there is nothing yet to poll; no scheduler triggers polls (Plan 37); the profiles the legacy pollers need (PON loading, SFP, LLDP, bounded FDB and ARP, sensors) are listed in [parity-inventory.md](parity-inventory.md) but not written; hardware sign-off is not verified against a device.
