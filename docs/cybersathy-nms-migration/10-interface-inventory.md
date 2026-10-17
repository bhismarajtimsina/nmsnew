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
than guessed at, since there is no real flap data yet to validate it against). ~~Nothing calls
`record_interface_status`.~~ Done 2026-10-17; see the next section.

## Implementation notes (2026-10-17): interfaces from the interface_basic poll

The polling sink now decodes the RFC 1213 interface table (`app/services/interface_poll.py`) and brings the device's
interfaces in line with it. The inventory is written first, so an interface new on a poll gets its first traffic
sample on that same poll (Plan 27).

- **New rows:** an ifIndex the inventory lacks becomes an interface named by its ifDescr. A row without one is not
  created, since it would have no name.
- **Known rows:** a known interface takes the polled name, type, speed and MAC address. A value missing from the
  poll, or not readable, keeps the stored one. So does a speed at ifSpeed's ceiling, since the real speed of such a
  port is in ifHighSpeed, which is not read yet.
- **Status:** goes through `record_interface_status`, only when it differs. The history gets one row per real change,
  and a poll that read one status keeps the other.
- **Absent interfaces:** an interface the poll did not return is left alone. A walk can be cut at its row cap, and
  history and marks hang off the row.
- **Never touched:** alias, the protected flag and the legacy id.
- **Names:** ifType and status names come from `RFC1213-MIB.my` in this repository, and a test re-reads the file:
  - RFC 2863's four extra operational states (unknown, dormant, notPresent, lowerLayerDown) are written from the RFC,
    because IF-MIB is not in the repository. They use the names link state already reads.
  - ifType codes beyond RFC 1213's 32 are kept as their number, because IANAifType-MIB is not in the repository either.

With this, link state (Plan 27) follows real interface status once polling runs.

Tests: 9 in `tests/test_interface_poll.py`. 25 mutations checked, all caught; the first run's survivor (an unknown
operational-status code) was closed with a new case. Not verified against a device: the build's SNMP transport is the
disabled one (D-17).

Tests: 14 (`test_interface_inventory.py`). 6 mutations checked (the dedup/change-detection in
`record_interface_status`, `set_favorite`'s scope guard, tag sanitization, the `interfaces.mark` permission gate,
the favorite/tag list filters) - all caught. See STATUS.md for an unrelated environment issue (the host's disk
filling up from leaked test-container volumes) that briefly blocked this round's test runs and was fixed in the
test tooling itself, not in this plan's code.
