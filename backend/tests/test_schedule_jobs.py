import json
from datetime import datetime, timedelta, timezone

import pytest
from pydantic import ValidationError

from app.core.config import settings
from app.scheduling.jobs import (
    RETENTION_FLOOR_DAYS,
    CleanupSessionsParams,
    InvalidJob,
    JobContext,
    PollGroupParams,
    RetentionParams,
    run_cleanup_sessions,
    run_poll_group,
    run_retention,
    validate_params,
)
from app.workers.queue import JobQueue, verify
from tests.polling_helpers import ctx, make_active_profile, make_pollable  # noqa: F401
from tests.test_scheduler import scheduler  # noqa: F401  (reused fixtures' helpers)


def context(ctx, job_key="poll", slot="20260928T1200Z", now_ms=10_000_000):
    return JobContext(ctx.pool, JobQueue(ctx.redis, settings.job_signing_key), slot, job_key, now_ms)


async def delayed(ctx):
    entries = await ctx.redis.zrange("cs:delayed:polling.jobs", 0, -1, withscores=True)
    return [(json.loads(m), s) for m, s in entries]


async def test_a_poll_group_queues_only_devices_that_may_be_polled(ctx, db):
    await make_active_profile(db)
    ok = await make_pollable(db, "10.91.0.1")
    await make_pollable(db, "10.91.0.2", owner="legacy")
    await make_pollable(db, "10.91.0.3", enabled=False)
    await make_pollable(db, "10.91.0.4", with_profile=False)
    tripped = await make_pollable(db, "10.91.0.5")
    await db.execute("insert into device_poll_state (device_id, consecutive_failures, breaker_open_until) values ($1::uuid, 5, now() + interval '5 minutes')", tripped)
    zte = await make_pollable(db, "10.91.0.6", vendor="zte", family="zte-c-olt")
    await db.execute("update devices set device_type = 'olt' where id = $1::uuid", zte)
    note = await run_poll_group(context(ctx), PollGroupParams(profile="switch_basic", device_type="switch", jitter_seconds=0))
    queued = await delayed(ctx)
    assert [m["device_id"] for m, _ in queued] == [ok] and "queued 1" in note and "1 device(s) held back by an open circuit breaker" in note


async def test_a_poll_group_respects_the_vendor_and_family_kill_switches(ctx, db):
    await make_active_profile(db)
    await make_pollable(db, "10.91.0.1")
    await db.execute("update vendor_model_families set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom-switch'")
    assert "queued 0" in await run_poll_group(context(ctx), PollGroupParams(profile="switch_basic"))
    await db.execute("update vendor_model_families set polling_enabled = true, polling_disabled_reason = null where slug = 'bdcom-switch'")
    await db.execute("update vendors set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom'")
    assert "queued 0" in await run_poll_group(context(ctx, slot="s2"), PollGroupParams(profile="switch_basic"))
    assert await delayed(ctx) == []


async def test_a_poll_group_can_be_narrowed_by_vendor_family_and_type(ctx, db):
    await make_active_profile(db)
    await make_pollable(db, "10.91.0.1")
    await make_pollable(db, "10.91.0.2", vendor="cisco", family="cisco-switch")
    for params, expected in (({"vendor_slug": "bdcom"}, 1), ({"vendor_slug": "cisco"}, 1), ({"family_slug": "bdcom-switch"}, 1),
                             ({"device_type": "switch"}, 2), ({"device_type": "olt"}, 0), ({}, 2)):
        note = await run_poll_group(context(ctx, slot=f"s{expected}{sorted(params)}"), PollGroupParams(profile="switch_basic", jitter_seconds=0, **params))
        assert f"queued {expected} poll" in note, (params, note)


async def test_a_poll_group_refuses_a_profile_that_is_not_active(ctx, db):
    await make_active_profile(db, "draft_only", activate=False)
    for name in ("draft_only", "missing"):
        with pytest.raises(RuntimeError, match="no active profile"):
            await run_poll_group(context(ctx), PollGroupParams(profile=name))
    assert await delayed(ctx) == []


async def test_polls_are_spread_over_the_window_deterministically_and_signed(ctx, db):
    await make_active_profile(db)
    devices = [await make_pollable(db, f"10.92.0.{i}") for i in range(1, 41)]
    await run_poll_group(context(ctx, now_ms=1_000_000), PollGroupParams(profile="switch_basic", jitter_seconds=20))
    first = {m["device_id"]: s for m, s in await delayed(ctx)}
    assert set(first) == set(devices)
    offsets = [int(s) - 1_000_000 for s in first.values()]
    assert min(offsets) >= 0 and max(offsets) <= 20_000 and len(set(offsets)) > 15          # spread, not a burst at one instant
    for message, _ in await delayed(ctx):
        assert verify(message, settings.job_signing_key) and message["kind"] == "scheduled" and message["profile"] == "switch_basic"
    await run_poll_group(context(ctx, slot="next-cycle", now_ms=9_000_000), PollGroupParams(profile="switch_basic", jitter_seconds=20))
    second = {m["device_id"]: int(s) - 9_000_000 for m, s in await delayed(ctx)}
    assert second == {d: int(s) - 1_000_000 for d, s in first.items()}                       # each device keeps its place in every cycle


async def test_a_poll_group_stops_at_its_device_limit_and_says_so(ctx, db):
    await make_active_profile(db)
    for i in range(1, 8):
        await make_pollable(db, f"10.93.0.{i}")
    note = await run_poll_group(context(ctx), PollGroupParams(profile="switch_basic", max_devices=3, jitter_seconds=0))
    assert "queued 3" in note and "stopped at max_devices=3" in note and len(await delayed(ctx)) == 3


async def test_retention_removes_only_old_rows_of_its_target(ctx, db):
    await db.execute("insert into login_attempts (username, success, occurred_at) values ('old', false, now() - interval '100 days'), ('new', false, now() - interval '5 days')")
    device = await make_pollable(db, "10.94.0.1")
    await db.execute("insert into discovery_jobs (device_id, status, oids, timeout_ms, retries, finished_at) values "
                     "($1::uuid, 'succeeded', array['1.3.6.1.2.1.1.1.0'], 2000, 1, now() - interval '200 days'), "
                     "($1::uuid, 'queued', array['1.3.6.1.2.1.1.1.0'], 2000, 1, null), "
                     "($1::uuid, 'succeeded', array['1.3.6.1.2.1.1.1.0'], 2000, 1, now() - interval '1 day')", device)
    await db.execute("insert into dead_letter_jobs (stream, reason, resolved_at) values ('s', 'r', now() - interval '400 days'), ('s', 'r', null), ('s', 'r', now())")
    await run_retention(context(ctx), RetentionParams(target="login_attempts", days=90))
    assert [r["username"] for r in await db.fetch("select username from login_attempts")] == ["new"]
    await run_retention(context(ctx), RetentionParams(target="discovery_jobs", days=90))
    assert sorted(r["status"] for r in await db.fetch("select status from discovery_jobs")) == ["queued", "succeeded"]     # the pending one is never touched
    await run_retention(context(ctx), RetentionParams(target="dead_letter_jobs", days=180))
    assert await db.fetchval("select count(*) from dead_letter_jobs") == 2 and await db.fetchval("select count(*) from dead_letter_jobs where resolved_at is null") == 1


async def test_retention_never_deletes_a_run_that_is_still_running(ctx, db):
    job_id = await db.fetchval("insert into schedule_jobs (key, job_type, crontab) values ('k', 'retention', '* * * * *') returning id")
    await db.execute("insert into schedule_runs (job_id, scheduled_for, status, started_at) values ($1, now(), 'running', now() - interval '90 days'), ($1, now(), 'ok', now() - interval '90 days')", job_id)
    await run_retention(context(ctx), RetentionParams(target="schedule_runs", days=30))
    assert [r["status"] for r in await db.fetch("select status from schedule_runs")] == ["running"]


async def test_cleanup_sessions_removes_expired_and_long_revoked_sessions_only(ctx, db):
    from tests.helpers import make_user

    uid = await make_user(db, "u", "ISP Admin")
    await db.execute(
        "insert into user_sessions (user_id, token_hash, expires_at, revoked_at) values "
        "($1::uuid, 'expired-long', now() - interval '30 days', null), ($1::uuid, 'revoked-long', now() + interval '1 day', now() - interval '30 days'), "
        "($1::uuid, 'live', now() + interval '1 day', null), ($1::uuid, 'just-expired', now() - interval '1 hour', null)", uid)
    await run_cleanup_sessions(context(ctx), CleanupSessionsParams(older_than_days=7))
    assert sorted(r["token_hash"] for r in await db.fetch("select token_hash from user_sessions")) == ["just-expired", "live"]


@pytest.mark.parametrize("target,floor", RETENTION_FLOOR_DAYS.items())
def test_retention_cannot_be_set_below_its_safety_floor(target, floor):
    RetentionParams(target=target, days=floor)
    with pytest.raises(InvalidJob, match="at least"):
        validate_params("retention", {"target": target, "days": floor - 1})


@pytest.mark.parametrize("job_type,params", [
    ("retention", {"target": "users", "days": 90}),                                   # not a permitted target
    ("retention", {"target": "login_attempts", "days": 90, "where": "1=1"}),          # unknown fields are refused
    ("retention", {"target": "login_attempts"}),
    ("poll_group", {"profile": "Has Spaces"}),
    ("poll_group", {"profile": "ok", "command": "rm -rf /"}),
    ("poll_group", {"profile": "ok", "device_type": "toaster"}),
    ("poll_group", {"profile": "ok", "jitter_seconds": 9999}),
    ("poll_group", {"profile": "ok", "max_devices": 0}),
    ("cleanup_sessions", {"older_than_days": 0}),
    ("shell", {"command": "ls"}),                                                     # there is no such job type
    ("", {}),
])
def test_job_parameters_are_validated_and_nothing_can_carry_a_command(job_type, params):
    with pytest.raises(InvalidJob):
        validate_params(job_type, params)


def test_valid_parameters_are_accepted():
    assert isinstance(validate_params("poll_group", {"profile": "switch_basic"}), PollGroupParams)
    assert validate_params("cleanup_sessions", {}).older_than_days == 7
    assert validate_params("retention", {"target": "schedule_runs", "days": 30}).days == 30
