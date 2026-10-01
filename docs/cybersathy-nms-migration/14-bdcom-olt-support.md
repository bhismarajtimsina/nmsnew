# Plan 14: BDCOM OLT Support

> **Phase:** 5 · **Depends on:** 11, 12 · **Status:** Partial (GPON and EPON profiles drafted and MIB-checked, GPON/EPON mapping and ONU identity built; storage, UI, fixtures and hardware sign-off pending)

## Goal
Implement BDCOM OLT support separate from BDCOM switches.

## Current Source / Reference
BDCOM OLT modules include GP3600, 3310 families, PON/ONU optical, ONU list/status, unregistered ONUs, and resource modules.

## Target Design
BDCOM OLT polling uses OLT-specific PON/ONU profiles.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support PON ports, ONU list, ONU status, ONU optical RX/TX, ONU distance, ONU vendor/model/firmware, unregistered ONUs, PON optical, and OLT resources.
- Normalize GPON/EPON differences.
- Keep action OIDs outside polling profiles.
- Fixtures for GP3600 and 3310 families, GPON and EPON.
- ONU identity is the serial or MAC, never `ifIndex` (indexes change).
- Include PON port loading and ONU identification profiles (see Plan 11).
- Normalize GPON and EPON differences in one mapping table with tests.

## Database/API Impact
Store OLT, PON, ONU, optical history, and unregistered ONU data.

## Frontend Impact
OLT pages show PONs, ONUs, optical, unregistered ONUs, and events.

## Security / Access Rules
Reseller sees only assigned OLT/PON/ONU/customer scope.

## Acceptance Checks
- BDCOM OLT detail loads.
- ONU list loads.
- Optical history stores.
- Unregistered ONUs appear.
- ONU identity survives a simulated `ifIndex` renumber.
- Optical values pass the range check from Plan 6.

## Hardware sign-off
ONU list and optical polling within the rate budget on one OLT. **Not verified against a device.** See [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).

## Risks
- Large ONU tables can overload devices if polled too aggressively.

## Definition of Done
Fixtures parse for each family; identity and optical tests pass.

## Rollback
Per-vendor kill switch; legacy poller keeps ownership until enabled.

## Implementation notes (2026-10-01)

Nothing here contacted a device. The source was the legacy `configs/oids/bdcom/gp3600.yml` (GPON) and `3310c.yml`
(EPON), read from `origin/main`'s tree without merging, checked against BDCOM's MIBs. GPON objects come from
`bdcom/NMS-GPON-MIB`; EPON and the shared system objects from `NMS_BDCOM_MIBS/`.

**Two profiles** (`app/registry/bdcom_olt_oids.py`, seeded by `_seed_bdcom_olt_profiles`). Both are for vendor `bdcom`,
family `bdcom-olt`, and both are **drafts**: nothing polls them.

- **`bdcom_olt_gpon`, 26 entries:**
  - shared: identity, power status, per-card CPU, memory and temperature;
  - PON ports: active and inactive ONU counts, optical temperature, voltage, bias and transmit power;
  - ONU power as the OLT receives it;
  - ONUs: serial, vendor, version, equipment id, both firmware images, uptime, distance, deactivation reason, receive
    and transmit power.
- **`bdcom_olt_epon`, 26 entries:**
  - the same shared set;
  - PON ports: link status, optical transmit power, temperature, voltage and bias, splitting ratio;
  - ONU power as the OLT receives it;
  - ONUs: MAC, vendor, model, hardware and firmware versions, status, distance, and optical receive power, transmit
    power, temperature and voltage.

Bounds: 16 rows for cards, 64 for PON ports, 4096 for ONU tables (under the 5000-row hard cap), with timeouts of 8 s,
or 15 s for ONU walks. Each test run re-checks every OID against its MIB file, plus its `read-only` access and its
get or walk shape. Tests also confirm that the GPON profile never reads EPON objects and the EPON profile never reads
GPON objects.

**GPON/EPON mapping** (`NORMALIZED`): one table maps each shared meaning (ONU identity, vendor, model, firmware,
distance, receive and transmit power, PON optics) to each technology's definition. A meaning one technology lacks maps
to `None`. A test checks that every name exists in the right profile.

**ONU identity** (`app/services/onu_identity.py`):

- One poll's column rows are joined on their index, and the result is keyed by the ONU's serial (GPON) or MAC (EPON),
  never by the index.
- A row with no identity is dropped. A duplicate identity keeps the first row, so it never overwrites silently.
- A test renumbers the indexes between two polls and gets the same ONUs with the same values (Plan 14's acceptance
  check).

**Left out:**

- **Every writable object:** ONU reboot, enable and activate, description, bind type, IP, bandwidth, UNI settings,
  ONU profiles, config save.
- **Per-UNI tables:** several UNIs per ONU exceeds the walk cap, so they need a bounded per-ONU read.
- **Legacy names with no MIB object in the repository.** They wait for a fixture.

**Legacy names the MIB contradicts:**

- GPON `ont.action.delete` is really an *activate* control, and `ont.action.disable` is an *enable* control. This is
  recorded as a risk in Plans 26 and 38, so actions are never ported by their legacy names.
- GPON `profile.onu.flow.uni_type` is a T-CONT bandwidth-profile id.
- EPON `pon.portCountOnu` is `llidSequenceNo`, not an ONU count.

**GPON units:** the MIB states none for ONU receive and transmit power or distance, so those are stored without a unit
until a fixture fixes the scale. EPON's units come from its MIB (0.1 dBm, 0.1 dB).

Still to do:

- tables for OLT, PON, ONU, optical history and unregistered ONUs, with reseller scope;
- the poll-result writer that uses `onus_by_identity`;
- value transforms and range checks (Plan 6), once fixtures fix the GPON scales;
- unregistered ONUs (GPON objects for them are not yet identified in the MIB);
- assigning each device model its profile (`device_models.default_polling_profile`);
- the OLT pages;
- **fixtures per family, captured by an operator**, then the hardware sign-off. Not verified against a device.

Tests: 63 (52 per-OID checks plus profile, mapping, identity, seeding and poll checks). 9 mutations checked, all
caught.
