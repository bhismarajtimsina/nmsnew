# Plan 17: C-Data, VSOL, and GCOM OLT Support

> **Phase:** 5 · **Depends on:** 14 · **Status:** Not started

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
