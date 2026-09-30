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
- Accidental action execution without confirmation can cause outages.

## Definition of Done
Shared layer complete and used by every Dangerous action.

## Rollback
Dangerous actions remain on the legacy system until Plan 38 is done.
