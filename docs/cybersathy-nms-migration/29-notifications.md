# Plan 29: Notifications

> **Phase:** 6 · **Depends on:** 20 · **Status:** Partial

## Goal
Create notification worker and rules.

## Current Source / Reference
`components/Notifications/`: `Controllers/EventListener.php` (an internal pub/sub observer on `event:*`),
`Controllers/NotificationGenerators/EventGenerator.php` (matches an event to its config and its eligible contacts,
producing queued `c_notifications` rows - never a direct channel send), `Controllers/NotificationSender.php` +
`Console/NotificationSender.php` (the actual sender, one OS subprocess per notification, up to 20 concurrent),
`Controllers/Channels/{Telegram,Mail}.php`, and the schema in `components/Notifications/migrations/`.

## Target Design
Notifications are generated from scoped events/alarms, matching the real legacy pipeline read end to end (not
assumed - see the corrections below, found the same way the trap and pinger plans' assumptions were checked and
corrected). An event is turned into one queued row per eligible contact at generation time; a separate step decides
later, at send time, whether to actually deliver it (an event that resolved during its own delay window cancels the
still-queued alert).
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add Telegram and email channels. **Correction:** legacy has no webhook channel and no working SMS/phone channel -
  `NotificationContact` still has `PHONE`/`PHONE_FOR_TELEGRAM` types, but `EventGenerator::getContacts` explicitly
  skips both; they have never delivered anything. A webhook channel would be a genuinely new capability, not a port.
- Add notification rules by event name, contact severity subscription, and per-event-config ignored devices.
  **Correction:** legacy does not rule-match by "scope" or "role" directly - eligibility is which *users* can see
  the event (device-group scope, or the `notifications.send_global` permission for a device-less event -
  `AbstractNotificationGenerator::getUsers`), then each of those users' own contacts filter further by severity and
  a per-contact ignore-list.
- ~~Retry failures and log results~~. **Correction:** legacy never retries a failed send - `Console/NotificationSender.php`
  marks it `FAILED` and stops. Log it, as legacy does; do not invent a retry legacy never had.
- ~~Deduplicate and group messages; add storm control (max messages per channel per minute, digest after that)~~.
  **Correction:** does not exist. The sender's only bound is 20 concurrent OS subprocesses
  (`NotificationSenderService::COUNT_PROCS`) - a concurrency cap, not a rate limit, and no digest/grouping logic
  anywhere in the component. The real noise control is upstream of sending entirely: `delay_before_send_seconds`
  per event (a blip that resolves inside its own delay window is canceled before ever being sent - confirmed
  `pinger_host_down` ships with a real 60-second delay in production) and per-contact severity/ignore filtering.
- Keep parity with `NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE`: confirmed real (`.env`, empty/off in production) and
  precisely understood - a stricter mode that also cancels a "resolved" notification with no previous "alert", or
  whose previous alert never actually sent. Off by default, same as production.
- Support user self-service contacts (`notifications.contacts.self`) and global send with its own permission
  (`notifications.send_global`) - both confirmed real and already in this system's permission catalogue (from the
  legacy route-key mapping pass), needing no new permission.
- ~~Redact message content by scope: a reseller message never names out-of-scope objects~~. **Correction:** no such
  content-redaction feature exists in the legacy Notifications component. The real protection is at recipient
  selection (a user only becomes eligible through `getUsers`' device-group scope), not text filtering inside a
  composed message. If per-message content redaction is wanted, it is a new safety property to design, not a port.

## Database/API Impact
`notification_contacts`, `notification_event_config`, `notification_event_ignored_devices`, `notifications` (queue
and history in one table, matching legacy's single `c_notifications` - no separate deliveries/failures split).

## Frontend Impact
Notification configuration pages.

## Security / Access Rules
A user is only ever notified about an event on a device inside their own scope, or a device-less event if their
role holds `notifications.send_global` - the same rule enforced at generation time in the real legacy code.

## Acceptance Checks
- Rule matches event.
- Message sent.
- ~~Failure retried and logged~~ → Failure logged (not retried - matches legacy, see the correction above).
- Reseller notifications scoped (by recipient eligibility, not message redaction).
- A 500-alarm outage produces a bounded number of messages, **through the event's own delay/dedup, not a rate
  limiter legacy never had.**
- ~~A failed delivery is retried with backoff and recorded~~ → recorded as `failed`, not retried.
- A reseller message reaches only users whose scope includes the event's device.

## Risks
- Notification floods during outages: legacy's own answer is the per-event delay window and per-contact filtering,
  not a rate limiter - if that proves insufficient at this system's scale, a real storm control would be a new
  safety feature to design deliberately, not a missing port.

## Definition of Done
Rule matching, the delay/cancel timing, self-service and global-send permission gating, and (once built) delivery
and its failure logging are verified.

## Rollback
Legacy notifications keep running until cutover; new side starts with delivery disabled.

## Implementation notes (2026-09-29)

Before writing any code, the real legacy pipeline was read end to end (see the corrections above) - the same
discipline that already corrected the trap and pinger plans' unverified assumptions this round. Built and verified
offline:

- Migration `20260929_0017`: `notification_contacts` (email/telegram_id only - see the correction on dead contact
  types), `notification_event_config` (code-owned defaults, operator-editable and never overwritten by a re-seed,
  the same guarantee `alarm_rules` and `trap_profiles` have - confirmed by a test that tampers with a row and
  re-seeds), `notification_event_ignored_devices`, and `notifications`, a TimescaleDB hypertable on `created_at`
  (D-01) with 30-day retention - matching a real legacy cron (`clear_old_notifications`, daily, 30 days) exactly,
  the same approach already taken for `trap_history`. Two FKs the legacy schema itself does not have
  (`notifications.event_id`, `notifications.previous_notification_id`) were deliberately left unconstrained rather
  than added: `events` and `notifications` are both hypertables, and TimescaleDB refuses any unique constraint that
  excludes the partitioning column - discovered directly when `create_hypertable('notifications', ...)` itself
  failed against a unique index on `id` alone, added for exactly this purpose beforehand.
- `app/registry/notification_event_config_data.py`: the real 7 event configs from
  `components/Notifications/migrations/01_init/up.sql` and `02_added_new_notifications/up.sql`, including the real
  `pinger_host_down` 60-second delay. Three rows (added by the second migration's `INSERT IGNORE` with no explicit
  id) have no recoverable legacy id and are left `None` rather than guessed.
- `app/notifications/generate.py`: pure decision logic, no database, no channel - `wants_event` (per-contact
  severity and ignore-list match), `build_drafts` (config enabled/ignored-device/send-resolved gates, the delay
  before an alert vs. immediate for a resolved), `resolved_send_at` (always exactly 10 seconds after the paired
  alert's own send time, even if that lands in the past - confirmed this is unconditional in the real code, not
  "whichever is later"), and `is_send_canceled` (ported from `NotificationSender::isSendCanceled`, including the
  `NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE` stricter mode, off by default). A real legacy bug is deliberately **not**
  reproduced: `EventGenerator::getContacts` reads a contact param key (`ignore_events`) that
  `NotificationContact::setParams` never actually populates (it only ever writes `ignore_notifications`) - the
  per-contact ignore-list has therefore never worked in production. This implements the evidently intended
  behavior, not the shipped no-op.
- 20 tests (`test_notification_generate.py`, `test_notification_event_config.py`), 4 mutations checked (the
  event-already-resolved alert cancellation, the unconditional 10-second resolved offset, the ignored-devices
  filter, and the re-seed-overwrites-an-edit protection) - all caught.

The repository layer and the sender are also built now:

- `app/notifications/pipeline.py`: `get_event_config` (loads a config plus its ignored-device set),
  `eligible_contacts` (mirrors `AbstractNotificationGenerator::getUsers` exactly - a device-scoped event reaches
  users scoped to that device directly, through its group or an ancestor group, or whose role sees everything; a
  device-less event reaches only `notifications.send_global` holders), and `queue_for_event` (reads a real event
  row, resolves its config and contacts, and inserts one `notifications` row per draft `build_drafts` produces,
  pairing a resolved notification with its previous alert's real `send_at` from the database).
- `app/notifications/sender.py`: `get_due` (queued rows at or before now) and `process_one` (applies
  `is_send_canceled` against the event's real `resolved_at` and the previous notification's real status, then hands
  a surviving notification to a `Channel` - a `Protocol`, so a real one can be plugged in later without changing
  this code - and records `sent` or `failed`, never retrying a failure, matching
  `Console/NotificationSender.php` exactly).
- A real test-isolation gap was found and fixed the same way the earlier `oid_profiles`/`device_models` cascade gap
  was: `notification_event_config` has no FK to any table `conftest.py`'s `clean` fixture truncates, so
  `test_notification_event_config.py`'s own re-seed-protection test (which deliberately tampers with a seeded row
  and confirms a re-seed doesn't undo it) was leaving `interface_is_down` permanently disabled for the rest of the
  test session, silently breaking a later, unrelated test. Fixed by snapshotting and restoring
  `notification_event_config` in `clean()`, the same way `role_permissions` and role `scope_mode` already are.
- 14 more tests (`test_notification_pipeline.py`, `test_notification_sender.py`) and 4 more mutations checked (the
  scope-all/device-group eligibility query, the unconfigured-event skip, the sender's cancellation check, and the
  failure-vs-sent status distinction) - all caught. 34 notification tests in total.

Deliberately still not built: the Telegram/email channels themselves, which need real external credentials and
network calls this environment cannot safely fabricate or test against (unlike the UDP trap listener or the ICMP
pinger, there is no loopback equivalent for "send a real Telegram message" or "deliver real mail" - a channel
implementation would need mocking the Telegram Bot API and SMTP client, or a local test SMTP server, neither of
which was built). An API surface for managing contacts and event config (the legacy
`Api/{Get,Set}ContactsByUserAction.php`, `Api/UpdateEventsConfiguration.php` equivalents) is also not built yet. The
`ActionGenerator` path (audit-style notifications - "device added", "user logged in", a distinct legacy feature from
event/alarm notifications) remains out of scope entirely.

## Implementation notes, continued (2026-09-30): the standalone sender process

Read `components/Notifications/Console/NotificationSenderService.php` directly before building this, the same
discipline as every other plan this round - it is the actual long-running `service` command (`wca
notifications:service`), distinct from `NotificationSender.php`'s one-shot `send-notify <id>` that `process_one`
already ports. Two real things found there that weren't in the original plan doc:

- **The concurrency model.** Legacy forks a real OS process per notification
  (`new BackgroundProcess("wca notifications:send-notify {$id}")`), capped at `COUNT_PROCS = 20`, because PHP has no
  cheap concurrency primitive of its own - a `$processes[$id]` map tracks what's in flight so the same notification
  is never dispatched twice, and the loop blocks (`goto CHECK_STATE`) once 20 are running. `app/notifications/
  service.py`'s `run_cycle` ports the same cap and the same never-twice guarantee with an `asyncio.Semaphore` and an
  `in_flight` set the caller keeps across cycles, instead of forking a process - same behavior, no process-per-
  message overhead. One deliberate shape difference: legacy's loop can start polling for freshly-queued
  notifications again as soon as it's done *launching* the current batch (it doesn't wait for all 20 to finish);
  `run_cycle` waits for its whole batch to complete before the caller polls again. Simpler, and every correctness
  property still holds (the cap, no double-dispatch, per-item failure isolation, no retry) - just a stricter,
  non-overlapping cadence, not a behavior gap.
- **A pre-send guard** - "Notification was ignored, event or action not exist" - runs before a notification is even
  dispatched. Half of it doesn't apply here: this system never generates an action-linked notification
  (`ActionGenerator`'s audit path, "device added", "user logged in", is out of scope, as already noted above), so a
  real row here always carries an `event_id`. Ported as: a notification with a null `event_id` is canceled rather
  than sent (now the first check inside `process_one`, not a separate service-loop check - this system has no
  second notification kind for it to distinguish between).

Also read `Controllers/NotificationSender.php` in full for `allowedToSendByUplinkChecking` - the `check_uplink`
column's actual consumer. **Not ported**: it needs a device-link/topology graph (`links` component,
`$links->getByDevice(...)`) to know which devices are "upstream" of an alarming one, and this system has no
device-link/topology feature at all yet (Plans 13-19 and the product-surface plans are still Not started). Legacy
itself returns "allowed" unconditionally when the `links` component isn't installed - the exact state this system is
in - so building a function that's contractually always `true` today would be dead code, not a real port. Left as
an explicit gap rather than faked; `check_uplink` is already `false` on all 7 seeded event configs, so nothing today
would exercise it even if built.

Built: `app/notifications/service.py` (`run_cycle`), `app/notifications/__main__.py` (`python -m app.notifications
run|health`, the same `run`/`health` CLI shape as the trap receiver and pinger), `UnconfiguredChannel` in
`app/notifications/sender.py` (what `run()` hands to `run_cycle` until a real channel exists - every send fails
cleanly and is recorded that way, exactly like a real channel outage would be, rather than the process pretending
to succeed or refusing to start), and its own Compose service (`cybersathy-notification-sender`, `docker-
compose.cybersathy.yml`, the same hardening as every other standalone process here - `cap_drop: ALL`, read-only,
non-root).

5 more tests (`test_notification_sender.py`'s new no-event-guard case, plus 4 in the new `test_notification_service.py`),
3 more mutations checked (the in-flight skip removed, the concurrency cap disabled, the missing-event guard removed)
- all caught. The concurrency-cap mutation surfaced a real bug in the *test itself*, not the source: on a broken
(too-high) cap, the test's own blocked sends never got released before the assertion failed, which then hung
`pool.close()` forever on their still-checked-out connections instead of failing cleanly - fixed by always releasing
them before awaiting the task, regardless of whether the assertion passes. 39 notification tests in total.
