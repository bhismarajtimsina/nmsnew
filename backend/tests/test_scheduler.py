import json
from datetime import datetime, timedelta, timezone

import asyncpg
import pytest

from app.core.config import settings
from app.scheduling.scheduler import Scheduler
from app.workers.queue import JobQueue
from app.workers.runner import Worker
from tests.polling_helpers import ctx, make_active_profile, make_pollable  # noqa: F401

NOW = datetime(2026, 9, 28, 12, 0, 30, tzinfo=timezone.utc)


_created: list[Scheduler] = []


@pytest.fixture(autouse=True)
async def only_my_jobs(db):
    await db.execute("delete from schedule_jobs")      # the seeded default jobs would otherwise be part of every assertion
    yield
    for made in _created:                               # leadership is an advisory lock on a live connection: never leak it into the next test
        await made.release()
    _created.clear()


class Clock:
    def __init__(self, at=NOW):
        self.at = at

    def __call__(self):
        return self.at


def scheduler(ctx, clock=None, **kw):
    made = Scheduler(ctx.pool, JobQueue(ctx.redis, settings.job_signing_key), settings, clock=clock or Clock(), **kw)
    _created.append(made)
    return made


async def job(db, key="j1", job_type="retention", params=None, crontab="*/5 * * * *", *, due=timedelta(minutes=-1), enabled=True, **cols):
    params = params if params is not None else {"target": "login_attempts", "days": 30}
    next_run = NOW + due if due is not None else None
    columns = {"key": key, "job_type": job_type, "params": json.dumps(params), "crontab": crontab, "enabled": enabled, "next_run_at": next_run, **cols}
    names = ", ".join(columns)
    marks = ", ".join(f"${i + 1}{'::jsonb' if n == 'params' else ''}" for i, n in enumerate(columns))
    return await db.fetchval(f"insert into schedule_jobs ({names}) values ({marks}) returning id", *columns.values())


async def seed_old_attempts(db, n=3):
    for _ in range(n):
        await db.execute("insert into login_attempts (username, success, occurred_at) values ('old', false, now() - interval '400 days')")


async def runs(db, key="j1"):
    return await db.fetch("select r.status, r.output, r.error, r.scheduled_for from schedule_runs r join schedule_jobs j on j.id = r.job_id where j.key = $1 order by r.started_at", key)


async def test_a_due_job_runs_is_recorded_and_moved_to_its_next_slot(ctx, db):
    await seed_old_attempts(db)
    await job(db)
    result = await scheduler(ctx).tick()
    assert (result.leader, result.ran, result.errors) == (True, 1, 0)
    assert await db.fetchval("select count(*) from login_attempts") == 0
    (run,) = await runs(db)
    assert run["status"] == "ok" and "delete 3" in run["output"]
    row = await db.fetchrow("select last_run_at, next_run_at from schedule_jobs")
    assert row["next_run_at"] == datetime(2026, 9, 28, 12, 5, tzinfo=timezone.utc) and row["last_run_at"] == NOW


async def test_jobs_that_are_not_due_or_are_disabled_do_nothing(ctx, db):
    await seed_old_attempts(db)
    await job(db, "later", due=timedelta(minutes=3))
    await job(db, "off", enabled=False)
    result = await scheduler(ctx).tick()
    assert result.ran == 0 and await db.fetchval("select count(*) from login_attempts") == 3 and await db.fetchval("select count(*) from schedule_runs") == 0


async def test_a_second_tick_at_the_same_moment_does_not_run_the_job_again(ctx, db):
    await seed_old_attempts(db)
    await job(db)
    s = scheduler(ctx)
    await s.tick()
    assert (await s.tick()).ran == 0 and len(await runs(db)) == 1


async def test_a_job_with_no_next_run_gets_one_and_a_broken_schedule_is_switched_off(ctx, db):
    await job(db, "fresh", due=None)
    await job(db, "broken", crontab="not a schedule", due=None)
    await scheduler(ctx).tick()
    assert await db.fetchval("select next_run_at from schedule_jobs where key = 'fresh'") == datetime(2026, 9, 28, 12, 5, tzinfo=timezone.utc)
    assert await db.fetchval("select enabled from schedule_jobs where key = 'broken'") is False


async def test_the_misfire_policy_skip_records_the_miss_and_waits_for_the_next_slot(ctx, db):
    await seed_old_attempts(db)
    await job(db, due=timedelta(hours=-1), misfire_policy="skip", misfire_grace_seconds=300)
    result = await scheduler(ctx).tick()
    assert (result.misfired, result.ran) == (1, 0) and await db.fetchval("select count(*) from login_attempts") == 3
    (run,) = await runs(db)
    assert run["status"] == "misfired" and "missed by" in run["output"]
    assert await db.fetchval("select next_run_at > $1 from schedule_jobs", NOW) is True


async def test_the_misfire_policy_run_once_runs_a_single_time(ctx, db):
    await seed_old_attempts(db)
    late = timedelta(hours=-3)
    await job(db, due=late, misfire_policy="run_once")
    await scheduler(ctx).tick()
    (run,) = await runs(db)
    assert run["status"] == "ok" and run["scheduled_for"] == NOW + late and await db.fetchval("select count(*) from login_attempts") == 0


async def test_the_misfire_policy_catch_up_runs_each_missed_slot_up_to_the_cap(ctx, db):
    await job(db, due=timedelta(minutes=-32), misfire_policy="catch_up", catch_up_max=3, misfire_grace_seconds=60)
    await scheduler(ctx).tick()
    slots = [r["scheduled_for"] for r in await runs(db)]
    assert len(slots) == 3 and len(set(slots)) == 3 and slots == sorted(slots)          # three of the missed six, not all of them
    assert await db.fetchval("select next_run_at from schedule_jobs") == datetime(2026, 9, 28, 12, 5, tzinfo=timezone.utc)


async def test_a_job_does_not_start_while_its_previous_run_is_still_going(ctx, db):
    await seed_old_attempts(db)
    jid = await job(db)
    await db.execute("insert into schedule_runs (job_id, scheduled_for, status, started_at) values ($1, now(), 'running', now())", jid)
    result = await scheduler(ctx).tick()
    assert result.skipped == 1 and result.ran == 0 and await db.fetchval("select count(*) from login_attempts") == 3
    assert "still going" in (await runs(db))[-1]["output"]


async def test_the_overlap_policy_allow_lets_it_start_anyway(ctx, db):
    await seed_old_attempts(db)
    jid = await job(db, overlap_policy="allow")
    await db.execute("insert into schedule_runs (job_id, scheduled_for, status, started_at) values ($1, now(), 'running', now())", jid)
    assert (await scheduler(ctx).tick()).ran == 1


async def test_a_run_left_running_by_a_crash_is_marked_abandoned(ctx, db):
    jid = await job(db, due=timedelta(hours=1), max_runtime_seconds=60)
    await db.execute("insert into schedule_runs (job_id, scheduled_for, status, started_at) values ($1, now(), 'running', now() - interval '1 hour')", jid)
    await scheduler(ctx).tick()
    (run,) = await runs(db)
    assert run["status"] == "error" and "abandoned" in run["error"]


async def test_a_job_that_fails_is_recorded_and_does_not_stop_the_others_or_loop(ctx, db):
    await seed_old_attempts(db)
    await job(db, "a_fails", "poll_group", {"profile": "no_such_profile"})
    await job(db, "b_works")
    result = await scheduler(ctx).tick()
    assert (result.ran, result.errors) == (1, 1)
    (failed,) = await runs(db, "a_fails")
    assert failed["status"] == "error" and "no active profile" in failed["error"]
    assert await db.fetchval("select count(*) from login_attempts") == 0
    assert await db.fetchval("select next_run_at > $1 from schedule_jobs where key = 'a_fails'", NOW) is True     # not retried every tick


async def test_parameters_that_are_invalid_in_the_database_fail_the_run_and_never_execute(ctx, db):
    await seed_old_attempts(db)
    await job(db, params={"target": "login_attempts", "days": 1})                     # below the safety floor
    await scheduler(ctx).tick()
    (run,) = await runs(db)
    assert run["status"] == "error" and "invalid parameters" in run["error"] and await db.fetchval("select count(*) from login_attempts") == 3


async def test_a_run_that_takes_too_long_is_stopped_and_recorded(ctx, db, monkeypatch):
    import asyncio

    from app.scheduling import jobs

    async def slow(context, params):
        await asyncio.sleep(5)
        return "never"

    monkeypatch.setitem(jobs.JOB_TYPES, "retention", (jobs.JOB_TYPES["retention"][0], slow))
    await job(db, max_runtime_seconds=10)
    result = await scheduler(ctx, runtime_cap_seconds=0.1).tick()
    (run,) = await runs(db)
    assert result.errors == 1 and run["status"] == "error" and "timed out" in run["error"]
    assert await db.fetchval("select next_run_at > $1 from schedule_jobs", NOW) is True


async def test_only_one_scheduler_leads_and_another_takes_over_when_the_leader_dies(ctx, db):
    first, second = scheduler(ctx), scheduler(ctx)
    assert await first.ensure_leader() is True
    assert await second.ensure_leader() is False and (await second.tick()).leader is False
    pid = await first._lock_conn.fetchval("select pg_backend_pid()")
    # The leader's session is killed. The timeout makes this wait until the backend has really exited (and so released
    # its advisory lock); without it the call returns once the signal is sent, and the next line raced the exit.
    assert await db.fetchval("select pg_terminate_backend($1, 5000)", pid) is True
    assert await second.ensure_leader() is True                                       # the lock was released with it
    assert await first.ensure_leader() is False
    await second.release()
    assert await first.ensure_leader() is True
    await first.release()


async def test_a_standby_never_runs_jobs(ctx, db):
    await seed_old_attempts(db)
    await job(db)
    leader, standby = scheduler(ctx), scheduler(ctx)
    await leader.ensure_leader()
    assert (await standby.tick()).ran == 0 and await db.fetchval("select count(*) from schedule_runs") == 0
    assert (await leader.tick()).ran == 1
    await leader.release()


async def test_running_the_same_slot_twice_never_queues_a_poll_twice(ctx, db):
    await make_active_profile(db)
    await make_pollable(db, "10.90.0.1")
    await job(db, "p", "poll_group", {"profile": "switch_basic", "jitter_seconds": 0})
    s = scheduler(ctx)
    await s.tick()
    slot = await db.fetchval("select scheduled_for from schedule_runs")
    assert await ctx.redis.zcard("cs:delayed:polling.jobs") == 1
    await db.execute("update schedule_jobs set next_run_at = $1", slot)              # a restart re-runs the same slot
    await db.execute("delete from schedule_runs")
    await s.tick()
    assert await ctx.redis.zcard("cs:delayed:polling.jobs") == 1


async def test_the_worker_runs_the_scheduler_and_releases_due_polls_to_the_stream(ctx, db):
    await make_active_profile(db)
    device = await make_pollable(db, "10.90.0.1")
    await job(db, "p", "poll_group", {"profile": "switch_basic", "jitter_seconds": 0})
    worker = Worker(ctx, {"scheduler", "dispatcher"}, worker_id="w-sched")
    worker.scheduler.clock = Clock()
    await worker.run_once()
    entries = await ctx.redis.xrange("polling.jobs")
    assert len(entries) == 1 and entries[0][1]["device_id"] == device and entries[0][1]["kind"] == "scheduled"
    beat = await db.fetchrow("select status, info from worker_heartbeats where worker_id = 'w-sched'")
    assert beat["status"] == "running" and json.loads(beat["info"])["scheduler_leader"] is True
    await worker.scheduler.release()
