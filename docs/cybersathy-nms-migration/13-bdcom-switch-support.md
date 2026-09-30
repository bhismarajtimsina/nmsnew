# Plan 13: BDCOM Switch Support

> **Phase:** 5 · **Depends on:** 11, 12, 6, 8 · **Status:** Not started

## Goal
Implement safe BDCOM switch support separate from BDCOM OLT support.

## Current Source / Reference
Current BDCOM switch OIDs and modules exist under switcher-core BDCOM configs and `BDcom/Switches`.

## Target Design
BDCOM switches have their own profile.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support system, resources, interfaces, interface status, counters, errors, private BDCOM LLDP tree, and SFP DDM if present.
- Disable full FDB walk, console FDB, and full private enterprise walk by default.
- Implement bounded MAC lookup only.
- Add a fixture set per BDCOM switch model listed in migrations 062 and 070, captured by an operator per [the policy](safety-and-verification-policy.md#3-fixtures).
- Bound the private BDCOM LLDP tree with `max_rows` and a timeout; keep full FDB, console FDB and full private-enterprise walks disabled.
- MAC lookup is a bounded query for one MAC, permission-gated (`search.mac`), rate-limited, and audited.
- Run the existing offline tools (`bdcom-oid-coverage.php`, `bdcom-mib-oid-map.py`, `bdcom-trap-audit.py`) in CI.

## Database/API Impact
Store switch interfaces, counters, errors, LLDP neighbors, and SFP readings.

## Frontend Impact
Switch detail pages show status, interfaces, errors, LLDP, and optical data.

## Security / Access Rules
FDB/MAC lookup is permission-gated and bounded.

## Acceptance Checks
- Adding BDCOM switch does not overload/reboot switch.
- OLT behavior untouched.
- MAC lookup works only as bounded lookup.
- Every fixture parses.
- The BDCOM switch profile contains no full-table walk.
- MAC lookup issues one bounded request with the fake transport.

## Hardware sign-off
Adding and polling a BDCOM switch does not overload or reboot it. **Not verified against a device.** See [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).

## Risks
- Mixing BDCOM switch and OLT profiles can trigger unsafe OLT/Switch polling.

## Definition of Done
Fixtures parse, profile bounds verified, offline tools green; hardware sign-off recorded or pending.

## Rollback
Profile can be disabled per vendor with the Plan 5 kill switch.
