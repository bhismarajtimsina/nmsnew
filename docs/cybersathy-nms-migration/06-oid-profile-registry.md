# Plan 6: OID Profile Registry

> **Phase:** 2 · **Depends on:** 5 · **Status:** Partial

## Goal
Move current YAML OID knowledge into structured CyberSathy-NMS registry data.

## Current Source / Reference
OID profiles live under `vendor/meklis/switcher-core/configs/oids`.

## Target Design
Polling uses curated OID profiles, not raw MIB parsing.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Create `oid_namespaces`, `oid_definitions`, `oid_profile_entries`, `oid_transform_rules`.
- Import YAML OID profiles.
- Store logical name, numeric OID, vendor, module, access type, value map, unit, scale rule, walk strategy, and safety level.
- Validate writable and walk OIDs.
- Reuse the existing offline tools as import validators: `tools/bdcom-oid-coverage.php` and `tools/bdcom-mib-oid-map.py`. The registry must reproduce what they report.
- Reject at import: writable or action OIDs in a polling entry; a walk without `max_rows` and `timeout`; a scale rule without a fixture-backed test.
- Version profiles. Every poll result stores the profile version that produced it.
- Scale and transform rules carry a unit and a valid physical range; values outside the range are flagged, not stored as facts.

## Database/API Impact
Add OID profile APIs under `/api/v1/oid-profiles`.

## Frontend Impact
Add read-only profile browser first; editing comes later.

## Security / Access Rules
Writable/action OIDs must be marked dangerous and excluded from polling profiles.

## Acceptance Checks
- All YAML OIDs import.
- Writable OIDs are marked dangerous.
- Walk OIDs have bounds.
- No unbounded profile is enabled by default.
- Importing a profile with a writable OID or an unbounded walk fails.
- Imported BDCOM OIDs match the output of `tools/bdcom-mib-oid-map.py`.
- All YAML OIDs import, and the import report lists anything skipped with a reason.

## Risks
- Incorrect scale rules can create wrong optical/signal values.

## Definition of Done
All profiles import, validators enforce the rules, fixture-backed scale tests exist for optical values.

## Rollback
Profiles are versioned; activate the previous version.

## Implementation notes (2026-09-28)
Built: `oid_definitions`, `oid_transform_rules`, `oid_profiles` (versioned), `oid_profile_entries`, and a read API (`/oid-profiles`). `oid_namespaces` was not needed: a definition carries its vendor and module.

Enforced by the database, not by convention:
- a writable or dangerous OID can be recorded but can never be an entry of a polling profile, and an OID already in a profile cannot later become writable;
- every walk has a row limit and a timeout;
- an active profile is immutable, only one version per name is active, and an empty profile cannot be activated;
- a scale transform needs a factor, a physical range and a fixture reference.

`app/registry/oid.py` implements the same rules for import tooling. A test runs 17 entry cases and 8 definition cases through both and fails if they disagree.

## Implementation notes (2026-09-29): the first two real, active profiles
Two real, vendor-neutral profiles are now authored and active: `system_basic` (6 entries: the RFC 1213 system group) and `interface_basic` (17 entries: the RFC 1213 interface table, one bounded walk per column). This is deliberately narrower than "authoring OIDs" in general — see the scope note below — but it is the first time the polling engine has something real to poll rather than a test-only fixture.

**Scope: this does not touch D-25.** D-25 reserves vendor-private OIDs for a fixture an operator captures, and nothing vendor-private was added here. `system_basic`/`interface_basic` use only IETF-standard MIB-II objects (RFC 1213): every compliant SNMP agent implements the same meaning at the same address, so there is nothing a device capture could confirm that the published standard does not already confirm. This is the same reasoning the safe-discovery OIDs (`sysDescr`, `sysObjectID`, `sysUpTime`, `sysName`) have rested on, unquestioned, since Plan 9 — this only extends that same category to the rest of the system group and to the interface table, never to a vendor branch. `app/registry/standard_oids.py` documents this explicitly, and every vendor-specific OID (BDCOM, Huawei, ZTE private branches) remains exactly as undone as before, still waiting on Plans 13 to 19's fixtures.

- Every numeric OID is re-derived, not typed from memory: `tests/test_standard_oids.py` resolves each one fresh from the real `RFC1213-MIB.my` file in this repository (via Plan 7's MIB library) and fails if it ever disagrees with the module.
- The import tooling asked for here is now real, for this category: Plan 7's `mib check` cross-references a declared definition against what an imported MIB actually assigns that name.
- **A second real, load-bearing bug was found while building this**: the merged, cross-file MIB name lookup (`ResolveResult.by_name`) gives the *wrong* answer for `ifIndex` and no answer at all for `ifOutOctets`, because BDCOM's own private MIBs (`BDCOM-IF-MIB.my`, `BDCOM-EPON-ONU.MIB`, `BDCOM-EPON-ONU-IF-STATS.my`) reuse those exact names for unrelated private table columns. `standard_oids.py` resolves from one explicitly named file instead of the ambiguous merged map, and this is now documented as a hard warning on `by_name` itself so a future author does not repeat it.
- **A third bug, this time a real test-isolation gap already present before this work**: `vendors` and `vendor_model_families` both hold a `polling_toggled_by` foreign key to `users`. Truncating `users` between tests was silently cascading all the way through to empty `oid_profiles`, `oid_profile_entries`, `oid_definitions` and `device_models` every time, invisible until a test needed the session-seeded profiles to still be there afterward. Fixed in the test harness's cleanup step, with a regression test that reproduces the cascade directly.
- Verified against the real polling engine (Plan 11) with a fake transport: polling `system_basic` reads every real standard object in one GET; polling `interface_basic` walks every real column with the real seeded bounds (512 rows, 8 s) and truncates correctly when a device returns more. Verified live through the actual Compose stack: both profiles active, 23 entries total, correct OIDs and bounds served through Nginx.

Still not done: every vendor-private OID (BDCOM, Huawei, ZTE), which needs the fixture Plans 13 to 19 provide.
