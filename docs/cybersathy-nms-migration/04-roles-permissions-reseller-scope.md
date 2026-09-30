# Plan 4: Roles, Permissions, and Reseller Scope

> **Phase:** 1 · **Depends on:** 2, 3 · **Status:** Partial · **Owns:** F-02, F-14

## Goal
Implement real ISP/reseller access control for CyberSathy-NMS.

## Current Source / Reference
Current roles and permissions exist in PHP API and migrations, including recent ISP/reseller scope additions.

## Target Design
Access is enforced by FastAPI services and repositories.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add roles: Super Admin, ISP Admin, ISP NOC, ISP Support, Reseller Admin, Reseller Operator, Reseller Viewer.
- Add permission groups: devices, interfaces, olts, onus, switches, routers, events, logs, system, dangerous_actions.
- Add mixed assignment support for device, group, OLT, PON, ONU, customer, interface, and link scopes.
- Build visibility cache tables for fast scoped reads.
- Import roles through [permission-mapping.md](permission-mapping.md) and generate a per-role diff report (added and removed codes). A non-empty diff needs a human sign-off before the import is accepted.
- Build the **scoped-repository layer**: every query on scoped tables goes through it. A CI guard fails if a router or service touches those tables directly (D-11).
- Add `permissions.is_dangerous`, `resellers`, `reseller_users`, and the remaining scope tables (OLT, PON, ONU, customer, link).
- Visibility cache: define rebuild triggers (assignment changes) and a periodic full rebuild. If the cache is stale or missing, **fail closed**.
- Every route declares its permission through a dependency. A CI test enumerates all routes under `/api/v1` and fails on any without one.
- Create the seven planned roles and map the legacy ISP and reseller roles from migrations 063 and 066.

## Database/API Impact
Add reseller assignment and visibility cache tables. Every list/detail API must enforce scope in backend.

## Frontend Impact
Menus and dashboards depend on role and scope.

## Security / Access Rules
Reseller may have full assigned-scope control but never outside assigned scope.

## Acceptance Checks
- ISP Admin sees everything.
- ISP NOC sees all operational network data.
- Reseller sees assigned scope only.
- Backend rejects direct unauthorized API access.
- Dangerous actions require permission.
- A route without a declared permission fails CI.
- After an assignment is removed, access is denied within the documented cache bound.
- A leak matrix runs every list and detail endpoint as ISP Admin, ISP NOC, reseller in scope, reseller out of scope, and unauthenticated.

## Risks
- Data leakage if scope is enforced only in frontend.
- The visibility cache lags after an assignment change and grants stale access. Mitigation: bounded lag, fail closed, test.

## Definition of Done
Scoped-repository layer and route guard in CI; leak matrix green; role diff report signed off.

## Rollback
Role and scope tables are new. The legacy role system stays authoritative until cutover.

## Implementation notes (2026-09-28)
Built and tested:
- 105 permissions generated from [permission-mapping.md](permission-mapping.md) and seven roles derived from the legacy ISP and reseller role definitions (`tools/gen_permission_catalogue.py`). Invariants: a role holding a dangerous permission holds the global gate; resellers never see everything and hold none of the administrative permissions; Reseller Viewer < Operator < Admin.
- **Scoped repositories** (`app/repositories/`): the only place scope is written. A group assignment includes child groups; an interface assignment grants the interface but not its device; an unknown scope mode is treated as restricted; a missing assignment means no rows.
- Guards in CI: every route declares `public`, `authenticated` or a permission, and the public and authenticated-only sets are exactly the reviewed lists; no router contains SQL against scoped tables; every repository read applies the visibility predicate.
- A leak matrix runs list, detail and filter endpoints as eight kinds of user against one data set. A foreign object and a missing one return the identical answer.
- Users cannot hand out a role more powerful than their own; the last Super Admin cannot be removed; disabling a user revokes their sessions and tokens.
- Resellers and scope assignment (`PUT /access/users/{id}/scopes`), audited.

Decision D-11 (spike result): scope is computed by query each time, so a removed assignment takes effect immediately and there is no cache to go stale. The visibility-cache tables from the original plan were **not** created. Row-level security as defense in depth remains open.

Not done: scope tables for OLT, PON, ONU, customer and link (those entities do not exist yet); importing legacy roles and the diff report for it (needs legacy data, Plan 32).
