# Plan 10: Interface Inventory

> **Phase:** 3 · **Depends on:** 9 · **Status:** Partial

## Goal
Implement interface inventory and status storage.

## Current Source / Reference
Current interface APIs are under `/api/v1/device-interface` with polling modules for interface list/status/counters.

## Target Design
CyberSathy-NMS stores normalized interface records and status history.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Store ifIndex, name, alias, type, admin status, oper status, speed, MAC, and parent relation.
- Add interface list/detail APIs.
- Write status changes to history.
- Preserve parent and child relations for PON, ONU, VLAN and LACP interfaces, resolved in a second pass on import.
- Port interface marks and tags (`/interface-marks`) and the favorite and tagged lists.
- Create `interface_status_history` as a hypertable (D-01) with retention and a daily-uptime aggregate.

## Database/API Impact
Use relational `interfaces` plus TimescaleDB interface history tables.

## Frontend Impact
Switch, router, OLT, PON, and interface pages use the same inventory model.

## Security / Access Rules
Reseller sees only interfaces in assigned scope.

## Acceptance Checks
- Switch interfaces load.
- OLT PON interfaces load.
- Interface detail loads.
- Status changes are stored historically.
- Parent chains survive the import.
- Marks and tags survive the import.
- A status change writes exactly one history row.

## Risks
- Losing parent/child relationships for PON, ONU, VLAN, and LACP interfaces.

## Definition of Done
Interface inventory, marks and history work for switch, router and OLT interfaces.

## Rollback
Additive tables; the legacy interface data stays authoritative until cutover.

## Implementation notes (2026-09-29)

Built: migration `20260929_0014` (`interface_status_history` hypertable on `changed_at`, 30-day compression, 1-year
retention, per D-01; `interface_marks` and `interface_tags`, normalizing the legacy single polymorphic
`device_interfaces_tags(type, value)` table per `src/Storage/Devices/DeviceInterfaceTagStorage.php` into two proper
tables); `app/services/interface_status.py` (`record_interface_status`: updates an interface's current status and
appends exactly one history row per real change, never one per changed field); scoped repository functions and
routes in `app/repositories/interfaces.py` / `app/api/devices.py` (`GET/PUT /interfaces/{id}/marks|favorite|tags`,
`GET /interfaces/{id}/history`, `GET /interfaces/tags` for autocomplete, `favorite`/`tag` filters on `GET
/interfaces`). Marks and tags are global to the interface, not per user - the same shape the legacy storage class
used (no `user_id` column anywhere in it) - confirmed by reading the real legacy source, not assumed. Tag
sanitization (strip space/hyphen/quote/comma/hash/semicolon, then trim) matches `DeviceInterfaceTagStorage::setTags`
exactly. A new `interfaces.mark` permission gates the write side (favoriting/tagging); the legacy system required no
permission at all for it, but every role that operates rather than only reads the network holds it here.

Missing: parent/child relation resolution for PON, ONU, VLAN and LACP interfaces (needs vendor-specific parsing -
ifStackTable equivalents - not built until Plans 13-19 have fixtures); the daily-uptime aggregate (deferred rather
than guessed at, since there is no real flap data yet to validate it against); nothing calls
`record_interface_status` yet, because no OID profile decodes a poll result into an interface row (that is Plan
11's and Plans 13-19's job) - the writer is tested directly against the schema instead.

Tests: 14 (`test_interface_inventory.py`). 6 mutations checked (the dedup/change-detection in
`record_interface_status`, `set_favorite`'s scope guard, tag sanitization, the `interfaces.mark` permission gate,
the favorite/tag list filters) - all caught. See STATUS.md for an unrelated environment issue (the host's disk
filling up from leaked test-container volumes) that briefly blocked this round's test runs and was fixed in the
test tooling itself, not in this plan's code.
