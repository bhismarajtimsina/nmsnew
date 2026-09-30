# Plan 28: Maps

> **Phase:** 7 · **Depends on:** 4, 10 · **Status:** Not started

## Goal
Implement scoped map views.

## Current Source / Reference
Current map APIs exist under `/api/v1/maps` and frontend map pages.

## Target Design
CyberSathy-NMS maps show devices, OLTs, ONUs/customer locations, links, and alarms.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add map APIs for devices, links, OLTs, ONUs/customers, and alarms.
- Add filters by type, group, severity, vendor, and scope.
- Respect reseller assignments.
- Store coordinates in `locations` with indexes; revisit PostGIS only if a concrete query needs it.
- Customer and ONU locations are scoped like any other customer data.
- Port `MAP_COORDINATES`, tile layer and the four legacy map endpoints.

## Database/API Impact
Add location fields/indexes where needed.

## Frontend Impact
Map filters and role-based visibility.

## Security / Access Rules
Location and customer data must be scoped for resellers.

## Acceptance Checks
- Admin sees all allowed devices.
- Reseller sees assigned scope only.
- Map filters work.
- A reseller cannot fetch another reseller's customer locations by direct API call or by bounding-box query.

## Risks
- Customer location leakage to unrelated resellers.

## Definition of Done
Map endpoints and filters pass scope tests.

## Rollback
Legacy `/maps` endpoints stay available through the shim.
