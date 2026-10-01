# Plan 17: C-Data, VSOL, and GCOM OLT Support

> **Phase:** 5 · **Depends on:** 14 · **Status:** Partial (no profiles until vendor MIBs or fixtures exist, K-25)

## Goal
Implement additional OLT vendor support.

## Current Source / Reference
Current profiles exist for C-Data FD series, VSolution V1600, and GCOM EL5610.

## Target Design
These vendors use the same normalized OLT/ONU pipeline.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add profiles for C-Data FD series, VSolution V1600, and GCOM EL5610.
- Support PON list, ONU list, ONU status, optical, resources, and unregistered ONUs where available.
- Keep action OIDs out of polling.
- Fixtures per firmware for C-Data FD series, VSolution V1600 and GCOM EL5610.
- Keep a per-model override table for firmware differences.
- Unsupported capabilities return an explicit `unsupported` state, not an error.

## Database/API Impact
Use normalized OLT/PON/ONU tables with vendor profile references.

## Frontend Impact
Shared OLT pages should show vendor-specific unsupported states cleanly.

## Security / Access Rules
Reseller scope applies identically across vendors.

## Acceptance Checks
- Each vendor has safe discovery.
- ONU list and optical values parse.
- Action OIDs do not run during polling.
- Each vendor has safe discovery.
- An unsupported capability yields `unsupported` in the API and UI.

## Risks
- Vendor firmware differences may require per-model overrides.

## Definition of Done
Fixtures parse for each vendor and firmware in use.

## Rollback
Per-vendor kill switch.

## Implementation notes (2026-10-01)

Nothing here contacted a device. The source was legacy switcher-core (`Modules/CData/*`, `Modules/VsolOlts/*`,
`Modules/GCOM/*`), its model files (`configs/models/C-Data.yml`, `VSolution.yml`, `gcom.yml`) and its OID files,
read from `origin/main`'s tree without merging.

**No profiles yet, on purpose.** The repository has no C-Data, V-Solution or GCOM MIB (K-25). **To unblock:** add
the vendors' MIBs, or have an operator capture fixtures per model and firmware.

Built, all of it pure logic:

- **Unsupported state** (`app/vendors/capabilities.py`). A per-model table of six capabilities (ONU status, ONU
  optical, PON optical, ONU reasons, UNI status, SFP optical), taken from which modules each legacy model defines,
  including the firmware rewrites. A legacy rewrite replaces the whole module list, so the FD16xx FW 3 models have
  exactly the modules their rewrite names; rewrites that change only the key and name inherit their parent's.
  Asking a model for something it lacks gives a result in the `unsupported` state with no data, never an error or an
  empty list. An unknown model or capability is a configuration error and raises.
- **Action OIDs kept out of polling** (`app/vendors/legacy_actions.py`). Legacy OID files have no ACCESS clause, so
  an entry imported without a MIB would default to read-only. Names with an action word in any part (split on dots,
  underscores and camelCase), or a legacy `#SNMPSET` comment, become read-write and dangerous, which the registry
  already refuses in every profile. Calibrated against all 910 distinct legacy names: 67 flagged, and no reason or
  status enum among them.
- **C-Data** (`app/vendors/cdata_olt.py`). The **per-model override table**: the optical family for each model and,
  per family, which fields exist and how each converts. Voltage is value / 10000 on FD11xx, / 100000 on FD12xx and
  FD16xx, and / 100 on FD17xx. FD11xx power is in 0.1 µW (10·log10(value) − 40 dBm); the others are 0.01 dBm. FD17xx's
  no-reading values are applied per field. A field the family doesn't report is refused, so the caller shows it as
  unsupported. Down reasons arrive as text (two tables).
- **V-Solution** (`app/vendors/vsol_olt.py`). ifName parsing (`EPON0/3:12`, `EPON3ONU12` and the GPON forms). ONU
  rows are mapped through the OLT's own names. The EPON power string `0.0123 mW (-19.10 dBm)` is parsed, and so are
  `45.2 C` and `3.3 V`. Status tables for V1600D and V1600G, and V1600D's deregistration reasons.
- **GCOM** (`app/vendors/gcom_olt.py`). The `<slot>.<port>.<onu>` index, optical values as numbers, ONU status, and
  last registration.
- Empty or unparsable readings are no reading, never 0 (legacy's `(float)` cast shows 0).

Legacy defects, and what this does instead:

- **C-Data FD17xx blanks the wrong field.** Its OLT-rx checks blank `rx`, the ONU's own receive power, and leave the
  bad OLT-rx value on screen. Here each check blanks the field it tested.
- **GCOM's legacy numeric id is not unique** (slot 0 port 10 equals slot 1 port 0). It is kept only for matching
  imported rows; the identity is the (slot, port, onu) triple.
- **V-Solution ONU rows carry no slot.** Names on a slot other than 0 are refused, since their rows couldn't be told
  apart.
- **V1600G's status map skips code 1.** It is unknown, never online.
- Action mislabels (C-Data reset and reboot sharing one OID, among others) are recorded in Plans 26 and 38.

Tests: 164, in `tests/test_plan17_olts.py`. 53 mutations checked, all caught (two needed new tests, which were added).

Still to do:

- profiles per vendor, once MIBs or fixtures exist;
- PON list, unregistered ONUs and resources, through the shared OLT/ONU tables, with `unsupported` shown in the API
  and UI;
- **fixtures per model and firmware**, then the hardware sign-off. Not verified against a device.
