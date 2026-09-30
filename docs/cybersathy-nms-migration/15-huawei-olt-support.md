# Plan 15: Huawei OLT Support

> **Phase:** 5 · **Depends on:** 14 · **Status:** Not started

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
