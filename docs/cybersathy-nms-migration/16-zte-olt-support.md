# Plan 16: ZTE OLT Support

> **Phase:** 5 · **Depends on:** 14 · **Status:** Not started

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
