# Plan 15: Huawei OLT Support

> **Phase:** 5 · **Depends on:** 14 · **Status:** Partial (index parsing, optical scaling and down-reason normalization built; no polling profile until Huawei MIBs or fixtures exist)

## Goal
Implement Huawei OLT support for GPON and EPON.

## Current Source / Reference
Current Huawei OLT OID profile includes board/slot, ONT config/status, optical signal, last down reason, and autofind data.

## Target Design
Huawei OLT profiles normalize ONT state and optical metrics.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support board/slot status, PON count, ONT status, ONT config, optical signal, last down reason, and autofind ONTs.
- Normalize LOS, PowerOff, LOKI, and auth failure reasons.
- Add value scaling tests.
- Fixtures for GPON and EPON, including index formats that differ between them.
- Normalize down reasons (LOS, PowerOff, LOKI, auth failure) through one table with a test per reason.
- Value scaling tests use fixture values with known dBm results.

## Database/API Impact
Store Huawei-specific fields through normalized OLT/ONU tables.

## Frontend Impact
Huawei appears through the same OLT/ONU dashboard UX.

## Security / Access Rules
ONT actions require OLT/ONU permissions and assignment scope.

## Acceptance Checks
- Huawei ONT status parses.
- Optical values scale correctly.
- Down reasons normalize to common alarm reasons.
- GPON and EPON indexes each parse to the right board, PON and ONT.
- Every down reason maps to a common alarm reason.

## Risks
- EPON and GPON indexes differ and can be parsed incorrectly.

## Definition of Done
Fixture and scale tests pass.

## Rollback
Per-vendor kill switch.

## Implementation notes (2026-10-01)

Nothing here contacted a device. The source was the legacy switcher-core code (`Modules/HuaweiOLT/*`) and value maps
(`configs/oids/huawei/smartax.yml`), read from `origin/main`'s tree without merging.

**No Huawei polling profile yet, on purpose.** The repository has no Huawei MIB; the only MIBs anywhere are BDCOM's.
So no Huawei OID can be checked offline the way the BDCOM profiles are, and the safety policy keeps unchecked
vendor-private OIDs out of profiles. **To unblock:** add Huawei's MIBs (HUAWEI-XPON-MIB and the files it imports, from
Huawei's support site) to the repository, or have an operator capture fixtures.

Built in `app/vendors/huawei_olt.py`, all of it pure logic:

- **PON and ONT parsing.**
  - `GPON f/s/p` and `EPON f/s/p` port names, and `…:ont` ONT names.
  - ONT rows indexed `<PON ifIndex>.<ONT id>` are mapped through the OLT's own ifIndex→ifName table, so the board,
    slot and port are never decoded from the ifIndex number (its encoding differs between GPON and EPON boards).
  - ONT ids are checked against the per-PON maximum (GPON 128, EPON 64).
  - Rows that don't fit are refused, not guessed.
  - Legacy's numeric ids (GPON 0/1/7:1 → 200107001) are kept so imported rows can be matched.
- **Optical scaling, as legacy displays it today:**
  - receive and transmit power: value / 100 dBm;
  - power received at the OLT: value / 100 − 100;
  - voltage: value / 1000;
  - legacy's "no reading" rules: anything over 100 (including Huawei's 2147483647), receive power at or below
    −50 dBm, and distance −1.
- **Down reasons:** three tables (GPON last down cause, EPON last down cause, registration history). Each maps every
  code to Huawei's own label and to one of ten common reasons: `los`, `power_off`, `loki`, `auth_fail`,
  `signal_failure`, `admin_action`, `rogue_ont`, `deleted`, `none` and `unknown`. An unlisted code becomes `unknown`
  with the raw code visible, never dropped.

Legacy inconsistencies, kept visible:

- **EPON ONT receive and transmit power:** the legacy YAML comment says `(value − 10000) / 100`, but the code computes
  `value / 100`. The code's behaviour is the parity baseline, and `EPON_ONT_POWER_FORMULA_CONFIRMED` stays False until
  a fixture settles it.
- **Down cause 18:** Unknown in the GPON table, DeactivatedByRing in the EPON table.

Tests: 54, covering parsing for both technologies, scaling, invalid readings, every reason mapping to a common reason,
and label parity with the legacy value maps. 9 mutations checked, all caught.

Still to do:

- the Huawei profile, once MIBs or fixtures exist;
- board and slot status, autofind ONTs, and storage through the shared OLT/ONU tables;
- **fixtures for GPON and EPON**, then the hardware sign-off. Not verified against a device.
