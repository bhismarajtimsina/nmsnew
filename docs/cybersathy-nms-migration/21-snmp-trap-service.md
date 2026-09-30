# Plan 21: SNMP Trap Service

> **Phase:** 6 · **Depends on:** 12, 20 · **Status:** Partial

## Goal
Implement SNMP trap receive/decode pipeline.

## Current Source / Reference
Current trap profiles exist for global, BDCOM, C-Data, and Huawei.

## Target Design
Trap worker decodes traps into `trap_history`, a straight port of the legacy `c_trap_logs`. **Correction (2026-09-29,
after reading the real legacy pipeline):** the legacy system never turned a trap into an alarm - `components/TrapService/`
only ever logs to `c_trap_logs` (no severity, status or dedup column exists on it, and nothing in that component
touches `c_events`; see the Implementation notes below). "Decodes traps into normalized events" was this plan's own
original, unverified assumption, not something legacy actually does. Full parity is a trap producing a `trap_history`
row, not an `events` row. A future decision to add real-time trap-driven alerting as a genuinely new capability would
need its own review (D-27's principle: no new feature slips in as an assumed default), not a silent addition here.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Import trap profiles.
- Build trap receiver worker.
- Decode known traps.
- Store unknown traps safely.
- Link traps to device and scope when possible.
- Accept traps only from known device IPs; rate-limit per source; sample unknown traps.
- Decide SNMP versions supported (v1, v2c, v3) and document it.
- Build our own `trap-receiver` worker ([own-components.md](own-components.md#32-trap-receiver-worker)). At cutover it takes over the legacy listener's address and port so no device needs reconfiguring; until then the legacy listener serves only the legacy system (D-19).
- Validate trap OIDs with `tools/bdcom-trap-audit.py` and replay recorded trap PDUs in tests.

## Database/API Impact
Add trap profile and trap history tables.

## Frontend Impact
Trap events appear in event and alarm pages.

## Security / Access Rules
Trap ingestion should not trust payload text as instructions.

## Acceptance Checks
- Trap accepted.
- Vendor decoded.
- Trap stored in `trap_history`, linked to its device (not an `events` row - see Target Design's correction).
- Unknown trap searchable.
- A trap from an unknown source is dropped and counted.
- A flood from one source is rate-limited without affecting others.
- Recorded PDUs decode to the expected trap_history rows.

## Risks
- Unknown traps can be noisy; rate limiting is required.

## Definition of Done
Decoding, allow-list and rate limits tested with recorded PDUs.

## Rollback
Legacy listener stays authoritative until parity.

## Implementation notes (2026-09-29)

Built and verified offline first (this work needs no database, so it was also confirmed directly with a host-level
`pytest` while an unrelated environment issue was blocking the Docker-based harness - see STATUS.md - then reran
inside the normal `tools/test-backend.sh` once that was fixed, with the same result):

- `tools/gen_trap_profile_catalogue.py` + `backend/app/registry/trap_profile_data.py`: the real 28 trap definitions
  from `vendor/meklis/switcher-core/configs/traps/{global,bdcom,cdata,huawei}.yml` (2/18/4/4), read with real YAML
  parsing, OIDs stripped of the legacy leading dot to match this registry's convention (D-8's leading-dot bug).
- `tools/bdcom-trap-audit.py` was broken (pointed at a scratchpad path from an earlier, unrelated session) and is
  now self-contained: it rebuilds its own OID map from `NMS_BDCOM_MIBS`/`BDCOM_MIBS`/`bdcom` on every run, the same
  resolver `tools/bdcom-mib-oid-map.py` uses. Run against the new catalogue: all 18 BDCOM trap OIDs resolve to
  something in the real MIBs (0 unresolved), including reproducing the documented `OnuSfpParameterAlarm` /
  `OnuSfpParameterAlarmNotification` off-by-one the source YAML already explains.
- `app/traps/decode.py`: a pure `decode_trap(data: bytes) -> DecodedTrap` function for SNMP v1 Trap-PDU and v2c
  SNMPv2-Trap-PDU (D-17's trap-decoding half; `pysnmp-lextudio` chosen over the actively-in-progress `pysnmp` 7.x
  rewrite, which is missing the protocol engine `pysnmp.proto.api` needs). No network I/O: it only parses bytes
  already received, so it needed no device and no live poller to write or verify. v1's `enterpriseSpecific` OID
  (`enterprise + ".0." + specific`, RFC 3584 §3.1) and v2c's `snmpTrapOID.0` varbind are both handled; a malformed
  message, a non-trap PDU, and a v2c trap missing its OID varbind are all rejected with `TrapDecodeError`, never an
  unhandled exception on untrusted input.
- 13 tests (`test_trap_decode.py`, `test_trap_profiles.py`), decoding synthetic-but-real PDUs built with the same
  library against the real catalogue's actual OIDs (not an operator-captured fixture - none exists yet, per the
  safety policy). 4 mutations checked (the version-decode stage's own trailing-bytes rejection - found to make an
  explicit duplicate check genuinely unreachable, so that check was removed rather than kept as dead code; the
  non-trap-PDU rejection; the RFC 3584 OID formula; the `snmpTrapOID.0` varbind lookup) - all caught.

Once the harness was healthy again, the database half followed the same offline-verified pieces:

- Migration `20260929_0015`: `trap_profiles` (code-owned vendor knowledge, refreshed on every seed exactly like
  `device_models` - re-seeding overwrites a hand-edit, confirmed by a test that tampers with a row and re-seeds) and
  `trap_history`, a TimescaleDB hypertable on `received_at` (D-01). **Retention corrected to 30 days** (3-day
  compression) after reading the real legacy pipeline: `c_trap_logs` is purged by a cron job
  (`trapservice_clear_old_logs`, every 3 hours, >30 days old) - matching that exactly, not inventing a longer window,
  since `trap_history` is a straight port of that same rolling diagnostic log, not a new alarm store. No separate
  `unknown_traps` table (data-model.md updated): an unrecognized OID is a `trap_history` row with a null
  `trap_profile_id`, filterable the same way an open incident with no device is in Plan 20.
- `app/traps/ingest.py` (`record_trap`): resolves the sending device by source IP against `management_ip` - the
  plan's own rule, "accept traps only from known device IPs" - and matches the decoded OID against `trap_profiles`
  (globally unique across vendors, confirmed before relying on it: no OID lookup needs a vendor first). A trap from
  an unrecognized source is dropped before it reaches a single query; one from a known device but an unrecognized
  OID is still stored, satisfying "unknown trap searchable".
- Scoped read API: `GET /trap-history` (filterable by `device_id` and `known`), `GET /trap-profiles`, gated by a new
  `traps.view` permission granted through the same `devices.view` `EXPAND` that already covers `interfaces.view` -
  anyone who can see a device's interfaces can see its trap history.
- 10 more tests (`test_trap_ingest.py`, `test_traps_api.py`, plus one re-seed test added to `test_trap_profiles.py`)
  and 4 more mutations checked (the unknown-source drop, the device-visibility scope guard, the `traps.view`
  permission gate, and the re-seed-overwrites-a-tamper behavior) - all caught.

The UDP listener itself is now built too: `app/traps/listener.py` (`serve`, `TrapProtocol`), run as its own process
via `python -m app.traps run|health` and its own Compose service (`cybersathy-trap-receiver`, UDP 1162 by default -
not the standard 162, which stays the legacy listener's until cutover hands it over, D-19). It only ever receives:
`datagram_received` hands each packet to `decode_trap` then `record_trap`, tracking every in-flight packet as an
asyncio task so a graceful shutdown (or a test) can wait for them to finish, and never lets one bad packet or a
transient database error take the process down. Exercised end to end over loopback - both ends of every packet in
`test_trap_listener.py` are the test process itself on `127.0.0.1`, so nothing here needed or touched a device: a
real trap decodes and is stored, an unregistered source is dropped and counted, garbage bytes are counted as
malformed without killing the listener (confirmed by sending a real trap right after and having it handled
normally), and an unrecognized OID from a registered device is accepted but counted separately from a known one.
4 more tests, 2 more mutations (the accepted/dropped counter branch collapsed to always-drop; the malformed-packet
try/except removed, which reproduced the actual internal pyasn1 exception that would otherwise propagate) - both
caught.

Still not built: credential validation against the sending device's access profile (own-components.md §3.2 also
wants the community, or the SNMPv3 credentials, checked - right now only the source IP is) and per-source rate
limiting and a global cap (Redis-based, the same shape as the polling engine's per-device lock). These are the
natural next step; nothing about them is blocked on anything left over from this round.

**Not a gap, checked against the real legacy behavior (2026-09-29):** a trap never becomes an `events` row in either
system. A dedicated research pass read the actual legacy trap pipeline (`components/TrapService/Controllers/Controller.php`,
`components/TrapService/Models/TrapLog.php`, the `c_trap_logs` schema) end to end: it resolves the device, decodes
the trap through the same vendor YAML this plan already ports, and unconditionally inserts a log row - no severity
field exists anywhere in it, no dedup/fingerprint check, and no pairing logic (nothing closes a `linkDown` on a
matching `linkUp`, unlike Alertmanager's fingerprint-based resolve in Plan 20). `trap_history` matching that exactly
*is* full parity, not a partial implementation of something legacy does. Real-time trap-driven alerting would be a
genuinely new capability on top of this, not a missing port of one - see the Target Design correction above.
