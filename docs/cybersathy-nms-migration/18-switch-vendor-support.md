# Plan 18: Switch Vendor Support

> **Phase:** 5 · **Depends on:** 13 · **Status:** Not started

## Goal
Add safe switch support across common vendors.

## Current Source / Reference
Current switch configs include D-Link, Cisco, Juniper, DCN, Raisecom, Eltex, Edgecore, HP/Aruba, TP-Link, UBNT, Arista, Dell, Alcatel, and Allied Telesis.

## Target Design
Switch polling uses common modules with vendor capability flags.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support system, interfaces, counters, errors, LLDP, VLAN summary, SFP optical, and RMON where available.
- Hide unsupported modules.
- Keep FDB disabled by default unless explicitly configured.
- A capability table per vendor decides which modules the UI shows.
- FDB stays disabled unless explicitly configured and bounded.
- Fixtures for each vendor listed in the plan.

## Database/API Impact
Store switch counters/errors/status in normalized interface history tables.

## Frontend Impact
Switch detail page adapts to capabilities.

## Security / Access Rules
Switch actions require switch permissions and device scope.

## Acceptance Checks
- Each switch vendor has safe basic polling.
- Unsupported modules hidden.
- Full FDB disabled unless explicitly configured.
- Unsupported modules are hidden.
- No vendor profile enables a full FDB walk by default.

## Hardware sign-off
Basic polling of each switch vendor within the rate budget. **Not verified against a device.** See [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).

## Risks
- Full table polling on access switches can cause device load.

## Definition of Done
Fixture tests pass for every vendor.

## Rollback
Per-vendor kill switch.
