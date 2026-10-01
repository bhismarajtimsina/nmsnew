# Plan 23: Dashboard Structure

> **Phase:** 7 · **Depends on:** 4, 10, 20 · **Status:** Partial (backend built; frontend page and latency measurement to do)

## Goal
Create role-based dashboard structure for ISP and reseller workflows.

## Current Source / Reference
Current dashboard widgets exist in PHP API and Vue dashboard pages.

## Target Design
CyberSathy-NMS has Admin NOC, Admin OLT/ONU, Reseller, Monitoring, and Alarm dashboards.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Build ISP NOC dashboard focused on devices, switches, routers, interfaces, links, errors, traffic, and poller health.
- Build OLT dashboard focused on OLTs, PONs, ONUs, optical signal, LOS/offline.
- Build reseller dashboard focused on assigned scope.
- Port role dashboards from migration 068; `tools/build-role-dashboards.py` already exists as reference.
- Cover the widget parity list: error-calling-by-device, system-stat, ont-statuses, ont-offline-split, ports-down, poller-health, pon-ports, latest-system-actions.
- Back widgets with continuous aggregates, not raw scans (Plan 40).
- Widgets take scope from the scoped-repository layer only.

## Database/API Impact
Dashboard APIs aggregate from normalized device, interface, OLT/ONU, event, and poller tables.

## Frontend Impact
Role decides default dashboard and menu order.

## Security / Access Rules
Reseller cannot access NOC-wide data.

## Acceptance Checks
- Role decides default dashboard.
- Admin can access all dashboards.
- Reseller cannot access NOC-wide data.
- Every legacy widget has a native equivalent or a documented removal.
- Dashboard p95 latency is at or below legacy under the same data volume.
- A reseller's widgets never include out-of-scope counts.

## Risks
- Dashboard queries can become expensive without aggregation.

## Definition of Done
Widget parity checked; latency and scope tests pass.

## Rollback
Frontend can call the legacy dashboard endpoints through the shim.

## Implementation notes (2026-10-01)

Built in the backend. The legacy dashboards were read from `origin/main` (migration 068, `config/default_dashboard.json`,
the `Dashboard/Widgets` actions and `ConsoleWidgetsStorage`) without merging.

**Dashboards and access** (`app/services/dashboards.py`). Five dashboards: Network operations (NOC), OLTs and ONUs,
Monitoring, Alarms, and My subscribers (reseller). `GET /api/v1/dashboards` returns the ones the caller may open, the
widgets they may see on each, and their default.

- **The role decides the default.** Every ISP role opens on NOC and every reseller role on My subscribers. A role an
  administrator created falls back by scope.
- **An admin can open every dashboard.**
- **A reseller never reaches NOC-wide data**, and this is enforced in code, not left to permission settings. NOC,
  OLT and Monitoring need a role that sees everything, and so do the two NOC-wide widgets (latest system actions,
  last user activity). A reseller role given those permissions by mistake is still refused, with 403.

**Live widgets** (`app/api/dashboards.py`, `app/repositories/dashboards.py`). Each one is computed through the scope
fragments, so a reseller's numbers count only their scope:

- device reachability;
- open events by severity and by name (system events only for roles that see everything);
- latest open events (the existing `/events` list);
- ports down (enabled ports not up, longest down first);
- poller health over a window, including truncated polls;
- polling errors and non-responses by device;
- system summary (devices, interfaces and groups scoped; users and roles only for a role that sees everything and may
  view users);
- latest system actions (no before/after payloads);
- last user activity.

**Pending widgets** are listed with their reason and no endpoint, rather than left off. Seven ONU widgets wait for
OLT/ONU storage (Plan 14), and busiest links waits for Plan 27.

**Legacy parity.** Every legacy widget and every `/dashboard/widget/*` endpoint maps to a native widget; none is
removed. A test checks Plan 23's parity list.

**Legacy defect, not copied:** legacy `system-stat` counts every device, interface, user and role for any caller, so a
reseller's dashboard showed network-wide totals.

Tests: 21 (`tests/test_dashboards.py`). 18 mutations checked, all caught (one needed a new test, which was added).

Still to do:

- the dashboard page in the new frontend, reading `GET /api/v1/dashboards`;
- the p95 latency comparison against legacy, at production data volume;
- continuous aggregates behind the heavier widgets (Plan 40);
- the ONU widgets once Plan 14's storage exists.
