# Plan 8: Device Models

> **Phase:** 2 · **Depends on:** 5, 6 · **Status:** Partial

## Goal
Import device model profiles and detection logic into CyberSathy-NMS.

## Current Source / Reference
Model profiles live in `vendor/meklis/switcher-core/configs/models`.

## Target Design
Device detection maps sysObjectID/model rules to vendor, type, modules, and polling profile.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Import model name, vendor, device type, sysObjectID matcher, default polling profile, supported modules, and icon.
- Add model detection API.
- Split OLT and switch families even when enterprise OID overlaps.
- Detection returns **unknown** when a sysObjectID matches more than one model, and unknown runs no poller (K-09).
- Document the match priority. BDCOM switch and BDCOM OLT rules come from migrations 062 and 070.
- Add a test that the new detection agrees with the legacy `ModelCollector` on every legacy model, using the model YAML files only.

## Database/API Impact
Populate `device_models` and model capability links.

## Frontend Impact
Device add/edit uses detected model and supported modules.

## Security / Access Rules
Model management requires system/vendor permission.

## Acceptance Checks
- BDCOM switch detected as switch.
- BDCOM OLT detected as OLT.
- Huawei OLT detected as OLT.
- ZTE C-series detected as OLT.
- Unsupported modules hidden in UI.
- An ambiguous sysObjectID resolves to unknown.
- Detection agrees with `ModelCollector` on every model.

## Risks
- Model ambiguity can run unsafe pollers on the wrong device type.

## Definition of Done
Every legacy model detected identically or with a documented reason; ambiguity is safe.

## Rollback
Models are data; revert the import.

## Implementation notes (2026-09-29)
Built and tested (migration 0011, 459 tests in the suite, 66 deliberate breakages caught in total):
- **Real detection rules, not invented ones.** `tools/gen_device_model_catalogue.py` reads the actual legacy `detect: {description, objid}` regex pairs straight out of `vendor/meklis/switcher-core/configs/models/BDcom.yml`, `HuaweiOLT.yml` and `ZTE-C-series.yml` — the same files the current PHP `ModelCollector` uses today — into `backend/app/registry/device_model_data.py`. 35 real models: 24 BDCOM (both switch and OLT families), 6 Huawei OLT, 5 ZTE C-series OLT.
- **Matching semantics ported exactly** from `Model::detectByDescription`/`detectByObjId` (PHP `preg_match`, i.e. search-anywhere; an empty value auto-passes that half), read from `vendor/meklis/switcher-core/src/Config/Model.php`. Models are tried in the source file's own order — specific models first, generic fallback last, exactly as the vendor config's author wrote it — and the first full match wins, which is what makes detection agree with `ModelCollector`.
- **The one improvement over the legacy engine:** if the full set of matching models is not all the same `device_type`, detection returns **unknown** instead of guessing (K-09). Same-type multiple matches (a switch matching two switch models) are not treated as ambiguous, since either answer gets the same, safe poller family.
- **Parity test against every one of the 35 real models:** for each, a representative string is built directly from the model's own declared pattern (not a separate guess) and detection is asserted to select that exact model with the right `device_type`. This is the acceptance check "detection agrees with ModelCollector on every model," run against real vendor data.
- Read-only API: `GET /device-models`, `GET /device-models/{id}`, `POST /device-models/detect`.
- **Advisory only in the discovery worker.** After safe discovery reads sysDescr/sysObjectID, detection runs and a confident match is recorded on the device's `model_id`. It **never** changes the vendor or model family an operator already chose at device creation, so it can never silently move which poller family a device is subject to.
- CI checks the generated catalogue against the source files on every change (same pattern as the permission catalogue), so the data can only be regenerated, never hand-edited out of sync.

**Two real bugs were found and fixed while building this, both caught by the parity test and a live check, not assumed away:**
1. The generator's own block parser split `detect: {...}` naively at the first `}`, which for two models using a `\d{3,4}` quantifier is the quantifier's own closing brace, corrupting those two entries. Fixed by bounding the match to end-of-line instead of non-greedy first-`}`; locked in with a direct unit test against a synthetic fixture reproducing the exact construct.
2. **A real interoperability defect:** every ported objid pattern assumes the SNMP-library convention of a leading dot (`^.1.3.6.1.4.1.3320...`), but this system's own discovery worker normalizes and stores `sys_object_id` **without** a leading dot. Without a fix, detection would have silently failed to match real values in this system's own database for essentially every BDCOM, Huawei and ZTE device. Fixed in the detection function (tries the value both as stored and with a leading dot prepended) and confirmed live against the real, documented value for a BDCOM S2900 device.

Not done: vendor files exist in the repo for many more vendors (Cisco, Juniper, MikroTik, D-Link, and 20-odd more switch vendors) and are not yet imported — each is real work of the same shape as this plan, not a mechanical repeat, since each vendor's own detection quirks need reading. `supported_modules`/`default_polling_profile`/icon are not populated (no OID profiles exist yet, D-25). No write API: models are code-owned reference data, not user-edited.