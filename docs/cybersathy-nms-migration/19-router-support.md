# Plan 19: Router Support

> **Phase:** 5 · **Depends on:** 11, 12 · **Status:** Not started

## Goal
Implement MikroTik RouterOS and L3 router support.

## Current Source / Reference
Current RouterOS components and switcher-core modules support interfaces, resources, ARP, routes, BGP, leases, and queues.

## Target Design
Router support is profile-based and bounded.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support interfaces, resources, ARP, routes, BGP sessions, DHCP leases, and simple queues where available.
- Add bounds for ARP and lease tables.
- Normalize BGP states.
- Bound ARP, route and DHCP-lease tables with `max_rows` and a page strategy; log truncation as a metric.
- Normalize BGP states through one table.
- Decide RouterOS API versus SNMP per metric and record it.
- Fixtures for RouterOS and one L3 vendor.

## Database/API Impact
Add router/BGP/ARP history as needed.

## Frontend Impact
Router pages show resources, interfaces, BGP, routes, and leases.

## Security / Access Rules
Router actions require router permission and assigned scope.

## Acceptance Checks
- RouterOS resources load.
- BGP state normalizes.
- ARP/lease tables are bounded.
- A router with more rows than `max_rows` is truncated and flagged, not fully walked.
- BGP states map to the common set.

## Risks
- Router tables can be large and must not be walked without bounds.

## Definition of Done
Fixtures parse; bounds verified.

## Rollback
Per-vendor kill switch.
