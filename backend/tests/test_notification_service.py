"""The standalone sender loop: dispatch, the concurrency cap, and skipping a notification already in flight - against
a real connection pool (not a bare connection), since `run_cycle` gives each concurrent send its own, the same way
each of legacy's forked `send-notify` processes had its own."""
import asyncio

from app.core.database import create_pool
from app.notifications.service import run_cycle
from tests.helpers import make_device, make_user
from tests.test_notification_pipeline import make_contact, make_event
from tests.test_notification_sender import FakeChannel, _queue_one


async def _pool():
    return await create_pool()


async def test_run_cycle_tallies_sent_failed_and_canceled(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    sendable = await make_event(db, "interface_is_down", device_id=device)
    already_resolved = await make_event(db, "interface_is_down", device_id=device, resolved=True)
    await _queue_one(db, contact_id=contact, event_id=sendable, send_at="now()")
    await _queue_one(db, contact_id=contact, event_id=already_resolved, send_at="now()")  # canceled: event resolved

    pool = await _pool()
    try:
        summary = await run_cycle(pool, FakeChannel(), in_flight=set())
    finally:
        await pool.close()

    assert summary == {"found": 2, "in_flight_skipped": 0, "sent": 1, "failed": 0, "canceled": 1}


async def test_run_cycle_skips_a_notification_already_in_flight(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    event = await make_event(db, "interface_is_down", device_id=device)
    nid = await _queue_one(db, contact_id=contact, event_id=event, send_at="now()")

    pool = await _pool()
    try:
        summary = await run_cycle(pool, FakeChannel(), in_flight={str(nid)})
    finally:
        await pool.close()

    assert summary == {"found": 1, "in_flight_skipped": 1, "sent": 0, "failed": 0, "canceled": 0}
    assert await db.fetchval("select status from notifications where id = $1::uuid", nid) == "queued"


async def test_run_cycle_never_exceeds_the_concurrency_cap(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    for _ in range(6):
        event = await make_event(db, "interface_is_down", device_id=device)
        await _queue_one(db, contact_id=contact, event_id=event, send_at="now()")

    running = 0
    peak = 0
    release = asyncio.Event()

    class SlowChannel:
        async def send(self, notification_id: str) -> dict:
            nonlocal running, peak
            running += 1
            peak = max(peak, running)
            await release.wait()
            running -= 1
            return {}

    pool = await _pool()
    try:
        task = asyncio.ensure_future(run_cycle(pool, SlowChannel(), in_flight=set(), max_concurrent=2))
        try:
            for _ in range(50):
                await asyncio.sleep(0.02)
                if running >= 2:
                    break
            observed = running
        finally:
            # Always release the blocked sends before awaiting the task, even if the assertion below fails -
            # otherwise a broken cap (more than 2 running) leaves extra tasks parked on `release.wait()` forever,
            # which then hangs `pool.close()` on their still-checked-out connections instead of failing cleanly.
            release.set()
        summary = await task
    finally:
        await pool.close()

    assert observed == 2  # never more than the cap, even with 6 ready to go
    assert peak == 2 and summary == {"found": 6, "in_flight_skipped": 0, "sent": 6, "failed": 0, "canceled": 0}


async def test_run_cycle_finds_nothing_when_the_queue_is_empty(db):
    pool = await _pool()
    try:
        summary = await run_cycle(pool, FakeChannel(), in_flight=set())
    finally:
        await pool.close()
    assert summary == {"found": 0, "in_flight_skipped": 0, "sent": 0, "failed": 0, "canceled": 0}
