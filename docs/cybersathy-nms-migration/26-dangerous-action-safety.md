# Plan 26: Dangerous Action Safety

> **Phase:** 7 · **Depends on:** 4, 36 · **Status:** Partial (backend layer built; no action executors until Plan 38, no confirmation modal yet)

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

## Implementation notes (2026-10-05)

Built in the backend: `app/actions/` (catalogue and safety layer), `app/api/actions.py`, and migration
`20261001_0021` (tables `action_confirmations` and `action_results`).

**Which actions are dangerous** (`app/actions/catalogue.py`). Each action has its own permission, a target kind
(device, interface or ONU) and a per-request cap:

| Action | Target | Cap |
|---|---|---|
| Switch reboot | device | 1 |
| Save config | device | 10 |
| Enable/disable port | interface | 1 |
| Clear counters | interface | 48 |
| ONU reboot | ONU | 64 |
| ONU reset, deregister (delete), disable | ONU | 16 |
| Macro run | device | 20 |

Every action also needs the global `dangerous_actions.execute` gate.

**Two steps, always** (`app/actions/safety.py`):

1. **`POST /api/v1/actions/{action}/prepare`, the dry run.** It checks:
   - the permission and the gate;
   - the parameters (only the ones the action takes, with allowed values);
   - the cap (refused, never trimmed; duplicates collapse first);
   - that every target is inside the caller's scope (one target outside it refuses the whole batch).

   It returns a summary listing every target, and a confirmation token. Nothing is sent to a device.
2. **`POST /api/v1/actions/{action}/execute`**, with the token. The token is:
   - single-use;
   - valid for two minutes;
   - stored only as a hash;
   - bound to the user, the action, the exact target list and the parameters.

   Presenting it with anything different (another target, other parameters, another action) is refused and burns it.
   The reason isn't given, so a caller can't probe which part was wrong. Another user's attempt is refused without
   burning it. The burn commits on its own, outside any transaction, so a later error cannot roll it back.

   Permission and scope are checked again at execution. A target that left the caller's scope in between is recorded
   as `refused` and not run.

**Records.** Each target gets an `action_results` row (succeeded, failed or refused, with the reason) and an audit
entry with before/after context, secrets redacted. The dry run is audited too. The database itself caps a
confirmation's lifetime at 10 minutes and its batch at 500 targets, and requires a reason on every failure.

**Nothing runs unless switched on.** Since 2026-10-08 (Plan 38) execution is queued for a worker, which re-checks
permission, scope and the kill switch with fresh data. Device actions are off unless `DEVICE_ACTIONS_ENABLED` is set,
and only actions with a registered driver can be prepared (one so far: port up/down). `GET /api/v1/actions` reports
`available` per action.

Tests: 22 (`tests/test_action_safety.py`), with a fake executor registered only inside the tests. 21 mutations checked, all caught (two needed new tests, which were added).

Still to do:

- the shared confirmation modal in the frontend;
- a realtime notice when results arrive;
- Plan 38's executors, each of whose OIDs is re-derived from its MIB (see the Risks above for the legacy mislabels).
