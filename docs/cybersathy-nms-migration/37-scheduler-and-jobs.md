# Plan 37: Scheduler and Jobs

> **Phase:** 4 · **Depends on:** 2, 12 · **Status:** Partial · **Owns:** replacement of `wca-schedule-executor`

## Goal
Move the legacy cron system into a dedicated scheduler service with editable jobs, run history and single-leader safety.

## Current Source / Reference
- Tables `system_schedule` and `system_schedule_reports`; component migrations that insert their own jobs (Pinger, Analytics, AutoDiscovery, TrapService, Notifications); migrations 014, 016, 026, 027, 039, 052, 058–061.
- `src/Console/Schedule/*` (`ScheduleList`, `ScheduleExecutor`, `ScheduleExecOnce`), the `wca-schedule-executor` container, API `/system/schedule`, `/logs/schedule/reports`, `/schedule/keys`.
- Seeded jobs include log clearing (`logs:clear switcher-core 7`, `actions 30`, `collector 7`, `crontab-reports 7`), collector runs, Pinger status recalculation, analytics, OpenAPI doc generation, and session recalculation.

## Target Design
A `scheduler` service reads `schedule_jobs`, enqueues jobs into Redis Streams at the right time, and records each run. Only one scheduler instance acts at a time, enforced by a PostgreSQL advisory lock. Jobs are typed (`poll_group`, `report`, `retention`, `notify_digest`, `discovery`, `cleanup_sessions`, …), not free-form shell commands.

## Implementation Steps
- Create `schedule_jobs` (key, job_type, params JSON, crontab, enabled, editable, last_run_at, next_run_at) and `schedule_runs` (job, started, finished, status, output, error).
- Translate every legacy `system_schedule` row into a typed job. Rows whose command has no typed equivalent are listed in the import report and **not** imported.
- Implement leader election with `pg_try_advisory_lock`; a standby scheduler takes over within one interval.
- Misfire policy per job: `skip`, `run_once`, or `catch_up` with a cap. Default `skip` for pollers.
- Overlap policy: never start a job while the previous run is still running, unless the job type declares it safe.
- Jitter on poll jobs so devices are not hit on the same second.
- API: list, edit (crontab and enabled only for editable jobs), run once, run history; permissions `system.schedule.manage` and `system.schedule.reports.view`.
- Retention jobs implement the table in [data-model.md](data-model.md) and the log-clearing behavior above.
- Export scheduler metrics: last success time per job, run duration, misfires.

## Database/API Impact
`schedule_jobs`, `schedule_runs`; endpoints under `/api/v1/system/schedule` and `/api/v1/logs/schedule`, plus legacy shim routes (Plan 41).

## Frontend Impact
The existing schedule page moves to the typed client; shows next run, last result and a run-once button.

## Security / Access Rules
- Editing a schedule is audited. Job params are validated against a schema per job type; users cannot inject shell commands.
- `run once` is rate-limited and follows the same scope rules as the job's target.

## Acceptance Checks
- Every legacy job is either represented or listed as intentionally not imported.
- Killing the active scheduler hands over to the standby without duplicate runs.
- A job overrunning its interval is not started twice.
- Disabled jobs do not run; editing a crontab takes effect without a restart.
- Retention job removes only rows past their window and leaves the newest.

## Risks
- Duplicate job execution amplifies device load (K-10). Mitigation: advisory lock plus idempotency keys.
- Timezone confusion. Mitigation: cron evaluated in one declared zone (D-13).

## Definition of Done
Scheduler runs in staging for 7 days with no missed or duplicated run; legacy executor is not needed for any listed job.

## Rollback
The legacy executor keeps running until cutover. Per-job `polling_owner`-style switch: a job type can be turned off in the new scheduler and left on in the legacy one.

## Implementation notes (2026-09-28)
Built and tested (migration 0010, 395 tests in the suite, 60 deliberate breakages caught in total):
- **Own cron parser** (`app/scheduling/cron.py`): five fields, lists, ranges, steps, month/weekday names, the `@hourly`-style macros, evaluated in one declared time zone (`SCHEDULER_TIMEZONE`, default UTC). Tested against 46 known cases, including the traditional day-of-month/day-of-week OR rule, a schedule that can never fire, and both sides of a daylight-saving transition (Europe/Berlin: the spring gap is skipped, the autumn overlap runs once).
- **Single leader** via `pg_try_advisory_lock` on its own connection. If that session dies, the lock releases with it and a standby takes over within one tick; a standby never runs a job.
- **Typed jobs, no shell commands ever:** `poll_group`, `retention`, `cleanup_sessions`, each with a Pydantic model that forbids unknown fields. `retention` has a per-target minimum (30 to 90 days) that cannot be set lower even directly in the database. There is no field anywhere that holds a command line.
- **`poll_group`** re-checks the same device, vendor, family and circuit-breaker conditions as a manual poll, and spreads polls over a jitter window using a new **delayed-delivery** primitive in the queue (`publish_at`/`release_due`, a Redis sorted set drained by the dispatcher) so a cycle does not hit every device at the same instant. Each device gets a fixed offset inside the window from a hash of its id, so it lands at the same moment every cycle rather than drifting.
- **Misfire policies** `skip`, `run_once`, `catch_up` (capped); **overlap** `forbid` (default) or `allow`; a run stuck `running` past its limit plus 30 s is marked abandoned; a run that exceeds `max_runtime_seconds` is cancelled and recorded as an error. One failing job never stops the others or the scheduler.
- Seeded jobs: `sessions_cleanup` and the four retention jobs run enabled; `poll_switch_basic`, `poll_olt_basic`, `poll_router_basic` are seeded **disabled**, and the API refuses to enable a `poll_group` job unless an active profile with that name exists.
- API: `GET /system/schedule`, `GET /system/schedule/{key}/runs`, `PATCH /system/schedule/{key}`, `POST /system/schedule/{key}/run`.

**A real deployment bug was found and fixed here, not just a test gap.** The scheduler itself was fully tested by instantiating it directly, but the `cybersathy-worker` container's actual startup command never included the `scheduler` kind, so a deployed worker would heartbeat and dispatch but the scheduler would never tick — no schedule would ever fire, silently. This was only caught by running the real Compose stack: the schedule showed as enabled with a computed `next_run_at`, but nothing was ever produced. Fixed in `docker-compose.cybersathy.yml` and the CLI's own default, and a static test (`test_deployment.py`) now asserts the deployed command and the CLI default both list every kind in `app.workers.runner.KINDS`, so this class of bug cannot reappear unnoticed. This is recorded as defect F-16.

Re-verified live end to end after the fix: enabling `poll_switch_basic` at `* * * * *` produced two ticks over 75 seconds, each publishing a poll job that was released from the delayed set onto `polling.jobs`. With the SNMP transport still disabled, the worker's poller never attached to that stream, so the jobs sat there unconsumed and the worker's only network connections stayed to PostgreSQL and Redis. Nothing reached a device.

Not done: no admin UI for editing schedules (the API only); `run_now` has no confirmation flow beyond the one-per-minute limit; per-job metrics belong to Plan 31.