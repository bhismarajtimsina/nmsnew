# Plan 30: Reports

> **Phase:** 7 · **Depends on:** 10, 20 · **Status:** Not started

## Goal
Add scheduled and on-demand reports.

## Current Source / Reference
Current schedule reports exist in PHP system schedule/report APIs.

## Target Design
Report worker reads scoped data and produces report artifacts.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add reports for device availability, ONU offline summary, bad optical signal, interface errors, high utilization, and poller failures.
- Add report jobs and result storage.
- Add download/view APIs.
- Build reports from continuous aggregates and paginated queries, not raw scans.
- Schedule reports through Plan 37.
- Set artifact retention and size limits.

## Database/API Impact
Add report jobs, schedules, and artifacts metadata.

## Frontend Impact
Reports page with scoped visibility.

## Security / Access Rules
Reseller reports include only assigned scope.

## Acceptance Checks
- Report job runs.
- Output stored.
- User can download/view report.
- Reseller reports scoped.
- A large report completes within the DB budget and does not degrade dashboards.
- Reseller reports contain only assigned scope.

## Risks
- Large reports can overload DB if not paginated/aggregated.

## Definition of Done
Reports run, store, and download with scope enforced.

## Rollback
Reports are additive.
