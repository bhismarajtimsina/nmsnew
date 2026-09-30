# Plan 27: Topology and Links

> **Phase:** 7 · **Depends on:** 10 · **Status:** Not started

## Goal
Build topology and link management.

## Current Source / Reference
Current links and topology components exist under `components/Links`, `components/Paths`, and frontend topology views.

## Target Design
Topology uses device/interface/link data and safe LLDP discovery.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add device graph API.
- Add link list API.
- Add tree view API.
- Use LLDP where safe.
- Update link state from interface status.
- Reach parity with `Links`, `Paths` (segments, states) and `AutoTopology` including link external names.
- Use LLDP only through bounded profiles.
- Compute link state from interface status and path state from segments; store thresholds in settings (`PATHS_DEGRADED_LATENCY_MS`).

## Database/API Impact
Create normalized link records and optional LLDP discovery history.

## Frontend Impact
Graph/tree pages use scoped topology APIs.

## Security / Access Rules
Reseller topology is limited to assigned scope and impacted context.

## Acceptance Checks
- Topology graph loads.
- Link status updates.
- Interface down affects link state.
- Reseller topology is scoped.
- Interface down changes link state within one cycle.
- Path state degrades at the configured latency.
- Reseller topology contains only assigned scope and impacted context.

## Risks
- LLDP gaps can produce incomplete topology.

## Definition of Done
Link and path parity verified; scope tests pass.

## Rollback
Legacy links stay authoritative until cutover.
