import json

import pytest

from app.core.config import settings
from app.polling.fake import FakeTransport
from app.polling.transport import DisabledTransport
from app.services.discovery import publish_job, queue_discovery
from app.workers.queue import GROUP, JobQueue, sign
from app.workers.runner import DISCOVERY_STREAM, Worker, dispatch_discovery
from tests.polling_helpers import (  # noqa: F401
    COMMUNITY, SAFE, SYS_DESCR, SYS_NAME, SYS_OBJECT_ID, SYS_UPTIME, ctx, make_pollable,
)

ADDRESS = "10.60.0.1"
GOOD = {SYS_DESCR: "BDCOM S5612 software", SYS_OBJECT_ID: "1.3.6.1.4.1.3320.1.5612", SYS_UPTIME: 123456, SYS_NAME: "edge-sw-1"}


async def add_job(ctx, db, address=ADDRESS, **device_kwargs):
    device = await make_pollable(db, address, **device_kwargs)
    job = await queue_discovery(db, None, device, None)
    return device, job["job_id"]


def worker(ctx, kinds=("discovery",)):
    return Worker(ctx, set(kinds), worker_id="test-worker")


async def publish(ctx, db, job_id):
    assert await publish_job(db, ctx.redis, job_id) is True


async def test_a_discovery_job_is_consumed_and_the_device_identity_is_recorded(ctx, db):
    ctx.transport.script_get(ADDRESS, GOOD)
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    assert await worker(ctx).run_once() == {"done": 1, "skipped": 0, "dead": 0, "retry": 0}
    row = await db.fetchrow("select sys_name, sys_descr, sys_object_id from devices where id = $1::uuid", device)
    assert (row["sys_name"], row["sys_object_id"]) == ("edge-sw-1", "1.3.6.1.4.1.3320.1.5612")
    job = await db.fetchrow("select status, started_at, finished_at, result from discovery_jobs")
    assert job["status"] == "succeeded" and job["finished_at"] is not None and json.loads(job["result"])["sys_uptime_ticks"] == 123456
    assert ctx.transport.calls == [("get", ADDRESS, tuple(SAFE))]                     # exactly the four system OIDs, once
    assert (await ctx.redis.xpending(DISCOVERY_STREAM, GROUP))["pending"] == 0
    assert ctx.transport.seen_communities == [COMMUNITY]


async def test_what_a_device_says_about_itself_is_treated_as_untrusted(ctx, db):
    hostile = {**GOOD, SYS_DESCR: "Router\x00\x1b[31m" + "A" * 5000 + "\nline two", SYS_NAME: "sw\n<script>alert(1)</script>\r\nx" + "N" * 400}
    ctx.transport.script_get(ADDRESS, hostile)
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    await worker(ctx).run_once()
    row = await db.fetchrow("select sys_name, sys_descr from devices where id = $1::uuid", device)
    assert "\x00" not in row["sys_descr"] and "\x1b" not in row["sys_descr"] and len(row["sys_descr"]) <= 2000
    assert "\n" not in row["sys_name"] and "\r" not in row["sys_name"] and len(row["sys_name"]) <= 255


async def test_a_sysobjectid_that_is_not_an_oid_fails_the_job(ctx, db):
    ctx.transport.script_get(ADDRESS, {**GOOD, SYS_OBJECT_ID: "'; drop table devices; --"})
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    await worker(ctx).run_once()
    assert await db.fetchval("select status from discovery_jobs") == "failed"
    assert await db.fetchval("select sys_object_id from devices where id = $1::uuid", device) is None


async def test_a_second_delivery_of_the_same_job_does_not_contact_the_device_again(ctx, db):
    ctx.transport.script_get(ADDRESS, GOOD)
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    fields = {"discovery_id": job_id, "device_id": device, "oids": json.dumps(SAFE), "timeout_ms": "2000", "retries": "1", "job_id": f"{job_id}:dup"}
    await ctx.redis.xadd(DISCOVERY_STREAM, {**fields, "sig": sign(fields, settings.job_signing_key)})
    result = await worker(ctx).run_once()
    assert result["done"] == 1 and result["skipped"] == 1
    assert len(ctx.transport.calls) == 1


async def test_the_oid_list_comes_from_the_database_never_from_the_message(ctx, db):
    ctx.transport.script_get(ADDRESS, GOOD)
    device, job_id = await add_job(ctx, db)
    fields = {"discovery_id": job_id, "device_id": device, "oids": json.dumps(["1.3.6.1.2.1.2.2.1.2", "1.3.6.1.4.1.3320"]),
              "timeout_ms": "200", "retries": "0", "job_id": f"{job_id}:1"}
    await JobQueue(ctx.redis, settings.job_signing_key).publish(DISCOVERY_STREAM, f"{job_id}:1", {k: v for k, v in fields.items() if k != "job_id"})
    await worker(ctx).run_once()
    assert ctx.transport.calls == [("get", ADDRESS, tuple(SAFE))]                     # the message's OIDs were ignored
    assert ctx.transport.get_limits == [(ADDRESS, 2000, 1)]                          # and so were its timeout and retries


async def test_switching_the_vendor_off_after_queuing_stops_the_job_before_it_reaches_the_device(ctx, db):
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    await db.execute("update vendors set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom'")
    assert (await worker(ctx).run_once())["skipped"] == 1
    job = await db.fetchrow("select status, error from discovery_jobs")
    assert job["status"] == "skipped" and "switched off" in job["error"] and ctx.transport.calls == []


async def test_a_device_taken_over_by_the_legacy_system_is_not_contacted(ctx, db):
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    await db.execute("update devices set polling_owner = 'legacy' where id = $1::uuid", device)
    await worker(ctx).run_once()
    assert await db.fetchval("select status from discovery_jobs") == "skipped" and ctx.transport.calls == []


async def test_a_timeout_fails_the_job_counts_toward_the_breaker_and_never_retries_by_itself(ctx, db, monkeypatch):
    monkeypatch.setattr(settings, "poll_breaker_threshold", 2)
    ctx.transport.timeouts.add(ADDRESS)
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    assert (await worker(ctx).run_once())["done"] == 1
    job = await db.fetchrow("select status, error from discovery_jobs")
    assert job["status"] == "failed" and "no response" in job["error"]
    assert await db.fetchval("select consecutive_failures from device_poll_state") == 1
    await worker(ctx).run_once()
    assert len(ctx.transport.calls) == 1                                              # nothing retried the failed job on its own
    await db.execute("update discovery_jobs set requested_at = now() - interval '1 hour'")
    second = await queue_discovery(db, None, device, None)
    await publish(ctx, db, second["job_id"])
    await worker(ctx).run_once()
    assert await db.fetchval("select breaker_open_until > now() from device_poll_state") is True
    third = await queue_discovery(db, None, device, None)
    await publish(ctx, db, third["job_id"])
    await worker(ctx).run_once()
    assert len(ctx.transport.calls) == 2 and await db.fetchval("select status from discovery_jobs where id = $1::uuid", third["job_id"]) == "skipped"


async def test_a_credential_echoed_in_an_error_is_scrubbed_from_the_job(ctx, db):
    ctx.transport.errors[ADDRESS] = f"bad community {COMMUNITY}"
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    await worker(ctx).run_once()
    assert COMMUNITY not in await db.fetchval("select error from discovery_jobs") and COMMUNITY not in await db.fetchval("select last_error from device_poll_state")


async def test_a_job_for_a_device_that_no_longer_exists_is_dropped_quietly(ctx, db):
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    await db.execute("delete from devices where id = $1::uuid", device)
    assert (await worker(ctx).run_once())["skipped"] == 1


async def test_with_the_transport_disabled_the_worker_consumes_nothing_and_says_so(ctx, db):
    ctx.transport = DisabledTransport()
    device, job_id = await add_job(ctx, db)
    await publish(ctx, db, job_id)
    w = worker(ctx, ("discovery", "poller"))
    assert await w.run_once() == {"done": 0, "skipped": 0, "dead": 0, "retry": 0}
    assert await db.fetchval("select status from discovery_jobs") == "queued"
    assert await ctx.redis.xlen(DISCOVERY_STREAM) == 1
    status = await db.fetchval("select status from worker_heartbeats where worker_id = 'test-worker'")
    assert status.startswith("idle") and "disabled" in status


def test_unknown_worker_kinds_are_refused():
    with pytest.raises(ValueError):
        Worker(None, {"discovery", "sweeper"})  # type: ignore[arg-type]


async def test_the_dispatcher_publishes_a_job_that_never_reached_the_stream_and_does_it_once(ctx, db):
    device, job_id = await add_job(ctx, db)
    assert await ctx.redis.xlen(DISCOVERY_STREAM) == 0                                # add_job only queued it in the database
    w = worker(ctx, ("dispatcher",))
    await w.run_once()
    await w.run_once()
    assert await ctx.redis.xlen(DISCOVERY_STREAM) == 1
    row = await db.fetchrow("select published_at, publish_attempts from discovery_jobs")
    assert row["published_at"] is not None and row["publish_attempts"] == 1


async def test_the_dispatcher_republishes_a_lost_job_then_gives_up_after_three_attempts(ctx, db):
    device, job_id = await add_job(ctx, db)
    w = worker(ctx, ("dispatcher",))
    for attempt in (1, 2, 3):
        await w.run_once()
        assert await db.fetchval("select publish_attempts from discovery_jobs") == attempt
        await db.execute("update discovery_jobs set published_at = now() - interval '20 minutes'")   # nobody picked it up
    assert await ctx.redis.xlen(DISCOVERY_STREAM) == 3
    await w.run_once()
    job = await db.fetchrow("select status, error from discovery_jobs")
    assert job["status"] == "failed" and "never picked up" in job["error"]
    assert await ctx.redis.xlen(DISCOVERY_STREAM) == 3


async def test_publishing_without_a_signing_key_leaves_the_job_queued(ctx, db, monkeypatch):
    device, job_id = await add_job(ctx, db)
    monkeypatch.setattr(settings, "job_signing_key", None)
    assert await publish_job(db, ctx.redis, job_id) is False
    assert await ctx.redis.xlen(DISCOVERY_STREAM) == 0 and await db.fetchval("select status from discovery_jobs") == "queued"
