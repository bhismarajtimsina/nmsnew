# Plan 34: Cutover

> **Phase:** 8 · **Depends on:** all plans · **Status:** Not started

## Goal
Perform safe production cutover to CyberSathy-NMS.

## Current Source / Reference
Current PHP/RoadRunner system remains source of truth until parity.

## Target Design
Cutover moves traffic to Nginx/FastAPI/PostgreSQL runtime.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Deploy staging.
- Import production data.
- Run pollers in observe-only mode.
- Compare device count, interface count, OLT count, ONU count, poller results, and alarm count.
- Switch Nginx to new API.
- Stop/archive old PHP system after stability window.
- Follow [cutover-runbook.md](cutover-runbook.md); this plan owns the go/no-go gates and the rehearsal.
- Use `polling_owner` to move devices group by group; never run both systems at full rate on one device.
- Rehearse the whole cutover and the rollback on a production copy and record timings.
- Keep the legacy stack read-only and ready for rollback through the stability window, then archive (images, dump, encryption key) for at least 12 months.

## Database/API Impact
PostgreSQL becomes source of truth after cutover.

## Frontend Impact
Frontend should continue using `/api/v1`.

## Security / Access Rules
Do not loosen permissions during cutover.

## Acceptance Checks
- Users can login.
- Dashboards load.
- Polling works.
- Alerts work.
- No RoadRunner service running.
- Every row in [parity-inventory.md](parity-inventory.md) is dispositioned.
- Gate A metrics hold for 7 consecutive days.
- Rollback rehearsal restores service within the recorded time.
- No RoadRunner service is running after the stability window.

## Risks
- Cutting over before poller/event parity can hide outages.
- A silent parity gap discovered after PostgreSQL is authoritative. Mitigation: parity inventory, Gate A, reverse export.

## Definition of Done
Cutover complete, stability window passed, legacy archived, `STATUS.md` updated.

## Rollback
See the Rollback section of [cutover-runbook.md](cutover-runbook.md).
