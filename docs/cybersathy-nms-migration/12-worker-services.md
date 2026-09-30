# Plan 12: Worker Services

> **Phase:** 4 · **Depends on:** 1, 11 · **Status:** Partial · **Owns:** F-09 (with Plan 1)

## Goal
Move asynchronous work into dedicated Python workers.

## Current Source / Reference
Current background work runs via PHP scheduler, supervisor, poller service, trap listener, and WebSocket service.

## Target Design
CyberSathy-NMS uses Python workers with Redis Streams.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add poller, event, trap, notification, and report workers.
- Use streams: `polling.jobs`, `polling.results`, `events.raw`, `events.processed`, `traps.raw`, `notifications.jobs`.
- Add retry limits and dead-letter behavior.
- Design the streams: one consumer group per worker type, `XAUTOCLAIM` for jobs stuck past a timeout, `MAXLEN` per stream, dead-letter stream after the retry cap.
- Every job has an idempotency key; a duplicate enqueue is dropped. A per-device lock ensures one poll per device at a time.
- Sign jobs so workers accept only jobs produced by the API or scheduler.
- Build our own `pinger` worker ([own-components.md](own-components.md#31-pinger-worker-icmp-updown)): ICMP, an N-miss state machine, `device_ping_status` plus a history hypertable, and respect for `polling_owner` (D-20). **Correction (2026-09-29):** no event is created on a transition - reading the real legacy pinger pipeline found it does not create one either; the alarm is Alertmanager's already-ported `pinger_host_down` rule reading the `pinger_host_status` gauge this worker exports, exactly like every other Alertmanager-sourced alarm (Plan 20).
- Workers report heartbeats; the API exposes worker health and lag.

## Database/API Impact
Workers write results and events through repositories/services.

## Frontend Impact
UI can show worker health and job progress.

## Security / Access Rules
Workers trust only signed/validated jobs from API services.

## Acceptance Checks
- Job enqueue works.
- Worker consumes job.
- Result stored.
- Failed jobs retry with limit.
- Killing a worker mid-job leads to the job being reclaimed and completed exactly once.
- Enqueueing the same job twice results in one execution.
- After the retry cap a job lands in the dead-letter stream with the failure reason.
- Redis restart loses no acknowledged job.

## Risks
- Duplicate jobs or unbounded retries can amplify device load.

## Definition of Done
Streams, locks, reclaim, dead-letter and heartbeats pass the tests above.

## Rollback
Workers are new; disable them and the legacy poller service continues.

## Implementation notes (2026-09-28)
The queue framework and the first two workers are built (`app/workers/`), tested against a real Redis:
- **Idempotent publishing** (a job id is published once), **HMAC-signed jobs** (a worker acts only on messages the API or dispatcher produced; a message written to the stream by anyone else is dead-lettered unread), consumer groups, `MAXLEN` caps.
- **Bounded retries with growing backoff** (30 s, 60 s, 120 s by default); after three deliveries a job goes to `dead_letter_jobs` and is never run again. A job whose worker crashed is completed exactly once by another.
- **Locks:** one poll of a device at a time, a per-vendor concurrency cap, both expiring on their own.
- **Discovery worker:** consumes the `discovery.jobs` stream that Plan 9 fills. It claims a job atomically (a duplicate delivery does nothing), reads the OIDs from the database row and never from the message, re-checks the kill switches and device ownership at execution time, treats device output as untrusted (control characters stripped, lengths capped, sysObjectID validated), and never retries a failed discovery by itself.
- **Dispatcher:** publishes jobs that were queued in the database but never reached the stream (Redis was down), republishes lost ones, and fails a job after three attempts.
- **Heartbeats and dead letters** are visible at `/api/v1/workers` and `/api/v1/dead-letters`.
- **With the SNMP transport disabled** the worker heartbeats, dispatches, and consumes nothing. Checked live in the Compose stack: the consumer group was never even created, the job stayed queued, and the worker's only network connection was to the database.

**Update (2026-09-29):** the trap-receiver (Plan 21) and pinger (D-20) are also built, but neither is a Redis
Streams consumer like the two above - each is its own standalone process (`python -m app.traps`,
`python -m app.pinger`), because a UDP datagram stream and an active periodic network probe don't fit the
pull-based job-queue shape the dispatcher/discovery workers use. This is a clarification of the original
`traps.raw`/`events.raw` stream design above, not an extension of it: the events and trap pipelines Plan 20 and
Plan 21 actually built read a webhook and a UDP socket directly into PostgreSQL, with no Redis Streams stage between
them. The pinger's own state machine and its 4 mutation checks are covered in `app/pinger/check.py`'s tests; it
exports `pinger_host_status`, not a `cybersathy_`-prefixed metric (own-components.md §3.1, a deliberate exception).

**Update (2026-09-30):** the notification sender (Plan 29) is built the same way - `python -m app.notifications
run|health`, its own standalone process and its own Compose service, not a Streams consumer, because it self-paces
against `notifications` directly (poll, idle 3 seconds when nothing is due) exactly like
`NotificationSenderService.php`'s own long-running `service` command does. Its concurrency is an `asyncio.Semaphore`
capped at 20 in flight - the direct equivalent of legacy's `COUNT_PROCS = 20` forked OS processes, without forking a
process per message. See [29-notifications.md](29-notifications.md) for the full port.

Not done: the report worker (Plan 30). Worker metrics await Plan 31. The pinger's own packets-per-second cap and
concurrent batching are also not built - see own-components.md §3.1. The notification sender has no real channel to
hand a notification to yet (Telegram/email, `29-notifications.md`) - every send today fails cleanly and is recorded
that way rather than delivering anything.
