# Plan 14: BDCOM OLT Support

> **Phase:** 5 · **Depends on:** 11, 12 · **Status:** Not started

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
