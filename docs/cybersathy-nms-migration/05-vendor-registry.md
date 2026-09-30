# Plan 5: Vendor Registry

> **Phase:** 2 · **Depends on:** 2 · **Status:** Done

## Goal
Create a structured vendor registry for CyberSathy-NMS.

## Current Source / Reference
Vendor knowledge exists in `vendor/meklis/switcher-core/configs/models`, `configs/oids`, and module classes.

## Target Design
Vendor support is registry-driven.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Create `vendors`, `vendor_model_families`, and `device_capabilities`.
- Import BDCOM, C-Data, Huawei, ZTE, VSolution, GCOM, MikroTik, D-Link, Cisco, Juniper, DCN, Raisecom, Eltex, Edgecore, HP/Aruba, Arista, Dell, Alcatel, Allied Telesis, TP-Link, UBNT, and Generic SNMP.
- Define supported device types and safe discovery policy per vendor.
- Give each vendor a `safe_discovery_policy` (allowed OIDs, timeout, retries) and a `polling_disabled` kill switch.
- Setting the kill switch stops new jobs for that vendor and lets running jobs finish.
- Record which device types each vendor supports and which plan implements them.

## Database/API Impact
Add `GET /api/v1/vendors` and vendor capability endpoints.

## Frontend Impact
Device forms and model pages use vendor registry data.

## Security / Access Rules
Vendor profile editing requires system/vendor permissions.

## Acceptance Checks
- Vendor list API works.
- Each vendor has device type support.
- Each vendor has a safe discovery policy.
- The kill switch stops new jobs for a vendor within one scheduler tick.
- No vendor is `enabled` without a safe-discovery policy.

## Risks
- Treating all BDCOM devices as one family and mixing switch/OLT behavior.

## Definition of Done
Vendor list, capabilities, discovery policy and kill switch are in place and tested.

## Rollback
The registry is additive data; disable or delete rows.

## Implementation notes (2026-09-28)
`vendors`, `vendor_model_families`, `capabilities`, `family_capabilities` (the original `device_capabilities` became these two). 22 vendors, 23 families, 26 capabilities are seeded. BDCOM switches and OLTs are separate families.
- Safe discovery can name only sysDescr, sysObjectID, sysUpTime and sysName. A database function rejects anything else, and the API cannot change the OIDs.
- A capability cannot be `supported` without a fixture reference (database rule). Everything is seeded `unverified`. Nothing in this plan enables polling.
- A high-risk capability (`fdb_full`, `private_enterprise_walk`) can never be enabled by default (database rule).
- **Kill switches** per vendor and per family. Switching off needs a reason, is audited, and `polling_allowed(vendor, family)` fails closed for anything unknown. Turning off BDCOM OLTs leaves BDCOM switches running.
- Permissions: `vendors.view` (ISP roles), `vendors.polling.toggle` (ISP Admin, ISP NOC), `vendors.manage` (Super Admin).
- Re-seeding never reverts an operator's kill switch; it does restore the code-owned risk facts.
