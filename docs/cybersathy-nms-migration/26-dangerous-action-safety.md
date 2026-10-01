# Plan 26: Dangerous Action Safety

> **Phase:** 7 · **Depends on:** 4, 36 · **Status:** Not started

## Goal
Add a shared safety layer for dangerous device and ONU actions.

## Current Source / Reference
Current actions exist across switch, OLT, macro, and switcher-core APIs.

## Target Design
Dangerous actions require permission, scope check, confirmation, audit log, and result event.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Mark reboot, save config, disable port, reboot ONU, reset ONU, delete ONU, bulk ONU actions, and macro execution as dangerous.
- Add confirmation token/summary flow.
- Add audit and result events.
- Define the confirmation token: single-use, short-lived, bound to user, target, action and parameters.
- Add caps for bulk requests and a dry-run summary listing every target.
- The implementation of every action is in [Plan 38](38-device-actions-and-console.md); this plan owns the shared layer.

## Database/API Impact
Use audit logs and action result records.

## Frontend Impact
Shared confirmation modal for dangerous actions.

## Security / Access Rules
Reseller can execute only inside assigned scope.

## Acceptance Checks
- Unauthorized user blocked.
- Confirmation required.
- Audit contains before/after context.
- A token cannot be reused, moved to another target, or used after expiry.
- A bulk request above the cap is refused.
- Audit records before and after context with secrets redacted.

## Risks
- **Legacy BDCOM action names do not say what they do (found 2026-10-01, Plan 14).** Checked against BDCOM's own
  NMS-GPON-MIB: legacy `ont.action.delete` (1.3.6.1.4.1.3320.10.3.2.1.2) is `gponOnuConfigActicate`, an activate
  control, and `ont.action.disable` (…10.3.2.1.3) is `gponOnuConfigEnable`. An action ported by its legacy name would do
  something other than its label says. Every action OID must be re-derived from the MIB object it targets, with the
  object's own name and description, before it is wired to a button; tests/test_bdcom_olt_profiles.py pins these two.
- **More legacy action mislabels (found 2026-10-01, Plan 17), from the legacy OID files alone (no MIB exists for
  these vendors in the repository, K-25):** C-Data EPON `ont.action.reboot` and `ont.action.resetOnu` are the same OID
  (…17409.2.3.4.1.1.17, value 1), so "reset" and "reboot" are one action; C-Data GPON's `ont.action.delete` sits in the
  EPON subtree (…17409.2.3.4.5.2.1.4); V-Solution V1600G `uni.control.set.adminStatus` is {0: Enabled, 1: Disabled},
  the reverse of V1600D, and its `ont.uni.adminStatus` map lists link speeds. None of these may be wired to a button on
  the strength of its legacy name. Legacy action names are kept out of polling by `app/vendors/legacy_actions.py`.
- Accidental action execution without confirmation can cause outages.

## Definition of Done
Shared layer complete and used by every Dangerous action.

## Rollback
Dangerous actions remain on the legacy system until Plan 38 is done.
