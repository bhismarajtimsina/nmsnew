# Plan 16: ZTE OLT Support

> **Phase:** 5 · **Depends on:** 14 · **Status:** Partial (no profile until ZTE MIBs or fixtures exist, K-25)

## Goal
Implement ZTE C-series OLT support.

## Current Source / Reference
Current ZTE profiles include C300/C600 OIDs for slots, GPON ONTs, unconfigured ONTs, optical values, and board resources.

## Target Design
ZTE support uses normalized OLT/ONU models with vendor-specific transforms.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support slot status, GPON ONT list, ONT phase state, ONT optical, unconfigured ONTs, board CPU/memory/temp, and SFP optical.
- Implement ZTE optical scale formulas.
- Normalize offline reasons.
- Fixtures for C300 and C600.
- ZTE optical conversion formulas tested against fixtures with known results, including negative and boundary values.
- Offline reasons map through one table with a test each.

## Database/API Impact
Store ZTE-specific raw values only when needed for diagnostics.

## Frontend Impact
ZTE OLTs use shared OLT dashboard components.

## Security / Access Rules
Actions are capability-gated per model.

## Acceptance Checks
- C300/C600 profiles load.
- Optical scaling correct.
- Offline reasons normalize.
- Optical conversion matches known values within tolerance.
- Boundary values do not wrap or clip.

## Risks
- Incorrect ZTE optical conversion can hide bad signal problems.

## Definition of Done
Fixture and scale tests pass.

## Rollback
Per-vendor kill switch.

## Implementation notes (2026-10-01)

Nothing here contacted a device. The source was the legacy switcher-core code (`Modules/ZTE/ModuleAbstract.php`,
`C300Series/*`, `C600Series/*`) and value maps (`configs/oids/zte/ZTE-C300_fw1.2.yml`, `ZTE-C300_fw2.1.yml`,
`ZTE-C600.yml`), read from `origin/main`'s tree without merging.

**No ZTE polling profile yet, on purpose.** The repository has no ZTE MIB (K-25), so no ZTE OID can be checked
offline. **To unblock:** add ZTE's MIBs to the repository, or have an operator capture C300 and C600 fixtures.

Built in `app/vendors/zte_olt.py`, all of it pure logic. The shared reason list moved to `app/vendors/common.py`,
which the Huawei module now re-exports.

- **Names.** C300 writes `gpon-olt_1/2/3` and `gpon-onu_1/2/3:4`; C600 writes `gpon_olt-1/2/3` and
  `gpon_onu-1/2/3:4`. Both parse. A name with the wrong kind for its ONT number, ONT 0, or an ONT past the PON size
  (GPON 128, EPON 64, ETTO cards 128) is refused. Legacy numeric ids are kept (1/2/3:4 → 10203004).
- **C300 indexes.** The 32-bit ifIndex is decoded by its layout: 1 (PON, ONT as a second index component), 3
  (8-port EPON, ONT inside), 9 (16-port EPON, ONT inside). The technology comes from the card in that shelf and slot,
  never from the layout, because layout 1 also addresses EPON ports. Encoding and decoding round-trip.
- **C600 indexes.** `<PON ifIndex>.<ONT id>` through the OLT's own ifIndex→ifName table, like Huawei; the optical
  tables carry a trailing `.1`, and any other trailing value is refused.
- **Optical conversion.**
  - GPON ONU rx/tx: raw 16-bit value, 0.002 dB steps from −30 dBm, values above 30000 counted negative, 65535 no
    reading. A value outside 0..65535 is refused, not wrapped.
  - OLT rx: value / 1000 dBm, for GPON and EPON alike.
  - GPON temperature: value / 256. GPON voltage: value × 0.02.
  - EPON rx, tx, temperature and voltage are shown as read, as legacy does (`EPON_OPTICAL_UNITS_CONFIRMED` stays
    False: no MIB or fixture says their unit).
  - Receive power (ONU rx and OLT rx) at or below −70 dBm or at or above 30 dBm is no reading. A genuinely bad signal
    such as −35 dBm is still shown.
- **Offline reasons:** three tables (C300 GPON, C600 GPON, C300 EPON last offline reason). Each code maps to ZTE's own
  label and to one of the ten common reasons; an unlisted code is `unknown` with the raw code visible.
- **Phase states:** per model, to `online`, `offline` (with a common reason), `registering` or `unknown`. An unlisted
  code is never read as online.

Legacy defects, and what this does instead:

- **The GPON power formula wraps at 30000, not 32767,** so raw 30001–32767 come out as −101 to −95 dBm. Legacy C300
  nulls rx outside −70..30 dBm; legacy C600 doesn't and would show −101 dBm. The C300 window now applies to both
  models (rx and OLT rx). Transmit power is not filtered, matching legacy.
- **Legacy C600 shows an OLT rx sentinel (−1 or −80 dBm) as 0 dBm,** which reads as a strong signal. Here it is no
  reading, for both models.
- **The C300 16-port EPON layout differs between legacy's encoder** (3-bit shelf, 5-bit slot) **and its decoder**
  (2-bit shelf, 4-bit slot, marked "temporarily" changed for testing). An index the two would read differently (shelf
  past 1, slot past 15) is refused.
- **GPON phase-state codes are off by one between the models** (C300 starts at 0, C600 at 1). Each model keeps its own
  table, and a test checks they are never read across.
- The console-derived reasons in legacy `OntReasons` (parsed from a telnet session's log) are not ported: Plan 16 reads
  SNMP only.

Tests: 134, covering names for both models, index round-trips and refusals, the power formula at and around each
boundary, every reason and phase state, and label parity with the legacy value maps. 37 mutations checked: 35 caught;
2 were equivalent, and the redundant code behind them was removed.

Still to do:

- the C300 and C600 profiles, once MIBs or fixtures exist;
- slot status, unconfigured ONTs, board CPU/memory/temperature and SFP optical, through the shared OLT/ONU tables;
- **fixtures for C300 and C600**, then the hardware sign-off. Not verified against a device.
