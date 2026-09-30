# Plan 23: Dashboard Structure

> **Phase:** 7 · **Depends on:** 4, 10, 20 · **Status:** Not started

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
