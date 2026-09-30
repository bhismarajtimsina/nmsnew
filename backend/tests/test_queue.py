import asyncio
import json

import pytest
import pytest_asyncio
from redis.asyncio import Redis

from app.core.config import settings
from app.core.database import create_pool
from app.polling.engine import Context
from app.polling.fake import FakeTransport
from app.workers.queue import GROUP, JobQueue, Locks, Message, Permanent, RetryLater, SigningKeyMissing, Skipped, sign, verify
from app.workers.runner import Worker

KEY = "unit-test-signing-key"
STREAM = "test.jobs"


@pytest_asyncio.fixture
async def redis(clean):
    client = Redis.from_url(settings.redis_dsn, decode_responses=True)
    yield client
    await client.aclose()


def queue(redis, **kw):
    return JobQueue(redis, KEY, retry_base_ms=kw.pop("retry_base_ms", 30), **kw)


def test_signatures_cover_every_field_and_reject_tampering():
    fields = {"job_id": "1", "device_id": "d", "profile": "p"}
    fields["sig"] = sign(fields, KEY)
    assert verify(fields, KEY) and not verify(fields, "another-key")
    for key in ("job_id", "device_id", "profile"):
        assert not verify({**fields, key: "tampered"}, KEY), key
    assert not verify({**fields, "extra": "x"}, KEY)
    assert not verify({k: v for k, v in fields.items() if k != "sig"}, KEY) and not verify({**fields, "sig": ""}, KEY)


async def test_publishing_is_idempotent_and_signed(redis):
    q = queue(redis)
    assert await q.publish(STREAM, "job-1", {"device_id": "d1"}) is True
    assert await q.publish(STREAM, "job-1", {"device_id": "d1"}) is False          # same id: dropped
    assert await q.publish(STREAM, "job-2", {"device_id": "d1"}) is True
    assert await redis.xlen(STREAM) == 2 and q.stats.duplicates == 1
    entry = (await redis.xrange(STREAM))[0][1]
    assert entry["job_id"] == "job-1" and verify(entry, KEY)


async def test_an_unsigned_job_can_never_be_published(redis):
    with pytest.raises(SigningKeyMissing):
        await JobQueue(redis, None).publish(STREAM, "job-1", {"x": "1"})
    assert await redis.xlen(STREAM) == 0 and await redis.exists("cs:job:test.jobs:job-1") == 0


async def test_a_failed_publish_leaves_the_id_usable(redis, monkeypatch):
    q = queue(redis)

    async def boom(*a, **k):
        raise ConnectionError("redis went away")

    monkeypatch.setattr(redis, "xadd", boom)
    with pytest.raises(ConnectionError):
        await q.publish(STREAM, "job-1", {"x": "1"})
    monkeypatch.undo()
    assert await q.publish(STREAM, "job-1", {"x": "1"}) is True


async def test_streams_are_capped(redis):
    q = queue(redis, maxlen=5)
    for i in range(20):
        await q.publish(STREAM, f"j{i}", {"n": i})
    assert await redis.xlen(STREAM) == 5


async def test_a_message_goes_to_one_consumer_and_is_gone_once_acknowledged(redis):
    q = queue(redis)
    await q.ensure_group(STREAM)
    await q.ensure_group(STREAM)                                                   # safe to repeat
    await q.publish(STREAM, "j1", {"n": 1})
    first = await q.read(STREAM, "worker-a", block_ms=10)
    second = await q.read(STREAM, "worker-b", block_ms=10)
    assert len(first) == 1 and second == []
    assert (await redis.xpending(STREAM, GROUP))["pending"] == 1
    await q.ack(first[0])
    assert (await redis.xpending(STREAM, GROUP))["pending"] == 0


async def test_an_unacknowledged_job_is_reclaimed_only_after_a_growing_backoff(redis):
    q = queue(redis, retry_base_ms=60)
    await q.ensure_group(STREAM)
    await q.publish(STREAM, "j1", {"n": 1})
    (m,) = await q.read(STREAM, "crashed", block_ms=10)
    assert m.deliveries == 1
    assert await q.reclaim(STREAM, "rescuer") == []                                # too soon
    await asyncio.sleep(0.08)
    (again,) = await q.reclaim(STREAM, "rescuer")
    assert again.id == m.id and again.deliveries == 2
    await asyncio.sleep(0.08)
    assert await q.reclaim(STREAM, "rescuer") == []                                # the second wait is twice as long (120 ms)
    await asyncio.sleep(0.06)
    (third,) = await q.reclaim(STREAM, "rescuer")
    assert third.deliveries == 3


async def _worker(redis, transport=None, **cfg):
    pool = await create_pool()
    ctx = Context(pool, redis, transport or FakeTransport(), None, settings)
    return Worker(ctx, {"poller"}, worker_id="test-worker"), pool


async def test_a_job_whose_worker_crashed_is_completed_exactly_once_by_another(redis, db):
    worker_a, pool = await _worker(redis)
    q = worker_a.queue
    q.retry_base_ms = 40
    await q.ensure_group(STREAM)
    await q.publish(STREAM, "j1", {"n": 1})
    effects = []

    async def crashing(message):
        raise RuntimeError("worker died mid-job")

    async def working(message):
        effects.append(message.fields["job_id"])

    (message,) = await q.read(STREAM, "a", block_ms=10)
    assert await worker_a.process(message, crashing) == "retry"                    # not acknowledged: stays pending
    await asyncio.sleep(0.06)
    (claimed,) = await q.reclaim(STREAM, "b")
    assert await worker_a.process(claimed, working) == "done"
    assert effects == ["j1"] and (await redis.xpending(STREAM, GROUP))["pending"] == 0
    await pool.close()


async def test_after_the_retry_cap_a_job_is_dead_lettered_and_never_run_again(redis, db):
    worker, pool = await _worker(redis)
    ran = []

    async def handler(message):
        ran.append(1)

    message = Message(STREAM, "1-0", {"job_id": "j9", "device_id": "d"}, deliveries=worker.queue.max_deliveries + 1)
    message.fields["sig"] = sign(message.fields, settings.job_signing_key)
    await redis.xadd(STREAM, {k: v for k, v in message.fields.items()})
    assert await worker.process(message, handler) == "dead" and ran == []
    row = await db.fetchrow("select reason, deliveries, job_id from dead_letter_jobs")
    assert (row["reason"], row["deliveries"], row["job_id"]) == ("retries exhausted", 4, "j9")
    await pool.close()


async def test_a_message_written_to_the_stream_by_anyone_else_is_never_acted_on(redis, db):
    worker, pool = await _worker(redis)
    await worker.queue.ensure_group(STREAM)
    ran = []

    async def handler(message):
        ran.append(1)

    await redis.xadd(STREAM, {"job_id": "evil", "device_id": "d", "sig": "0" * 64})
    await redis.xadd(STREAM, {"job_id": "unsigned", "device_id": "d"})
    for message in await worker.queue.read(STREAM, "w", block_ms=10):
        assert await worker.process(message, handler) == "dead"
    assert ran == [] and await db.fetchval("select count(*) from dead_letter_jobs where reason = 'bad signature'") == 2
    assert (await redis.xpending(STREAM, GROUP))["pending"] == 0
    await pool.close()


async def test_handler_outcomes_decide_what_happens_to_the_message(redis, db):
    worker, pool = await _worker(redis)
    q = worker.queue
    await q.ensure_group(STREAM)

    def signed(job):
        return {"job_id": job}

    async def outcome(exception):
        await q.publish(STREAM, f"o-{exception.__name__ if exception else 'ok'}", signed("x"))
        (m,) = await q.read(STREAM, "w", block_ms=10)

        async def handler(message):
            if exception:
                raise exception("because")

        return await worker.process(m, handler)

    assert await outcome(None) == "done"
    assert await outcome(Skipped) == "skipped"
    assert await outcome(Permanent) == "dead"
    assert await outcome(RetryLater) == "retry"
    assert (await redis.xpending(STREAM, GROUP))["pending"] == 1                   # only the retry is still pending
    await pool.close()


async def test_a_device_lock_is_exclusive_and_expires_on_its_own(redis):
    locks = Locks(redis)
    async with locks.device("d1", 5000) as first:
        async with locks.device("d1", 5000) as second:
            async with locks.device("d2", 5000) as other:
                assert (first, second, other) == (True, False, True)
    async with locks.device("d1", 5000) as again:
        assert again is True                                                       # released when the first holder finished
    async with locks.device("d3", 60) as _:
        await asyncio.sleep(0.09)
        async with locks.device("d3", 5000) as after_expiry:
            assert after_expiry is True                                            # a crashed holder cannot block forever


async def test_a_device_lock_can_only_be_released_by_its_holder(redis):
    locks = Locks(redis)
    async with locks.device("d1", 60) as held:
        assert held
        await asyncio.sleep(0.09)
        await redis.set("cs:lock:device:d1", "someone-else", px=5000)              # it expired and another worker took it
    assert await redis.get("cs:lock:device:d1") == "someone-else"                  # the first holder's release did not free it


async def test_the_per_vendor_slot_limit_holds_and_frees(redis):
    locks = Locks(redis)
    async with locks.vendor("bdcom", 2, 5000) as a, locks.vendor("bdcom", 2, 5000) as b, locks.vendor("bdcom", 2, 5000) as c:
        assert (a, b, c) == (True, True, False)
        async with locks.vendor("zte", 2, 5000) as other:
            assert other is True
    async with locks.vendor("bdcom", 2, 5000) as after:
        assert after is True
    async with locks.vendor("cisco", 1, 60) as _:
        await asyncio.sleep(0.09)
        async with locks.vendor("cisco", 1, 5000) as expired:
            assert expired is True


async def test_a_delayed_job_waits_until_due_and_is_released_exactly_once(redis):
    q = queue(redis)
    now = 1_000_000
    assert await q.publish_at(STREAM, "later", {"n": 1}, now + 5_000) is True
    assert await q.publish_at(STREAM, "later", {"n": 1}, now + 5_000) is False           # idempotent like publish
    assert await q.publish_at(STREAM, "sooner", {"n": 2}, now - 1) is True
    assert await q.release_due(STREAM, now_ms=now) == 1 and await redis.xlen(STREAM) == 1
    assert await q.release_due(STREAM, now_ms=now) == 0                                  # nothing more is due yet
    assert await q.release_due(STREAM, now_ms=now + 6_000) == 1 and await redis.xlen(STREAM) == 2
    assert await q.release_due(STREAM, now_ms=now + 60_000) == 0                         # and nothing is released twice
    for _, fields in await redis.xrange(STREAM):
        assert verify(fields, KEY)                                                       # released messages keep their signature


async def test_two_workers_releasing_at_once_never_release_a_job_twice(redis):
    q = queue(redis)
    for i in range(30):
        await q.publish_at(STREAM, f"j{i}", {"n": i}, 1)
    released = await asyncio.gather(q.release_due(STREAM, now_ms=10), q.release_due(STREAM, now_ms=10), q.release_due(STREAM, now_ms=10))
    assert sum(released) == 30 and await redis.xlen(STREAM) == 30


async def test_a_delayed_publish_needs_the_signing_key_and_a_failure_frees_the_id(redis, monkeypatch):
    with pytest.raises(SigningKeyMissing):
        await JobQueue(redis, None).publish_at(STREAM, "x", {}, 1)

    async def boom(*a, **k):
        raise ConnectionError("down")

    q = queue(redis)
    monkeypatch.setattr(redis, "zadd", boom)
    with pytest.raises(ConnectionError):
        await q.publish_at(STREAM, "x", {}, 1)
    monkeypatch.undo()
    assert await q.publish_at(STREAM, "x", {}, 1) is True
