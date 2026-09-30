"""The sender pipeline: cancellation, status transitions, and handing a surviving notification to a channel - against
a real database, with a fake channel (no real Telegram/email exists yet, see app/notifications/sender.py)."""
from app.notifications.sender import get_due, process_one
from tests.helpers import make_device, make_user
from tests.test_notification_pipeline import make_contact, make_event


class FakeChannel:
    def __init__(self, *, fail=False):
        self.fail = fail
        self.sent = []

    async def send(self, notification_id: str) -> dict:
        if self.fail:
            raise RuntimeError("channel unavailable")
        self.sent.append(notification_id)
        return {"message_id": 42}


async def _queue_one(db, *, type="alert", event_id=None, contact_id=None, send_at="now()", previous_notification_id=None):
    return await db.fetchval(
        f"insert into notifications (send_at, type, contact_id, event_id, previous_notification_id) "
        f"values ({send_at}, $1, $2::uuid, $3::uuid, $4::uuid) returning id",
        type, contact_id, event_id, previous_notification_id,
    )


async def test_get_due_returns_only_queued_rows_at_or_before_now(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    due = await _queue_one(db, contact_id=contact, send_at="now() - interval '1 second'")
    not_yet = await _queue_one(db, contact_id=contact, send_at="now() + interval '1 hour'")
    ids = {str(r["id"]) for r in await get_due(db)}
    assert str(due) in ids and str(not_yet) not in ids


async def test_a_successful_send_is_marked_sent_and_the_channel_is_called(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    event = await make_event(db, "interface_is_down", device_id=device)
    nid = await _queue_one(db, contact_id=contact, event_id=event, send_at="now()")
    channel = FakeChannel()
    row = await db.fetchrow("select id, type, contact_id, event_id, previous_notification_id from notifications where id = $1::uuid", nid)

    outcome = await process_one(db, row, channel)
    assert outcome == "sent" and channel.sent == [str(nid)]
    status = await db.fetchval("select status from notifications where id = $1::uuid", nid)
    assert status == "sent"


async def test_a_channel_failure_is_recorded_and_never_retried(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    event = await make_event(db, "interface_is_down", device_id=device)
    nid = await _queue_one(db, contact_id=contact, event_id=event, send_at="now()")
    row = await db.fetchrow("select id, type, contact_id, event_id, previous_notification_id from notifications where id = $1::uuid", nid)

    outcome = await process_one(db, row, FakeChannel(fail=True))
    assert outcome == "failed"
    row2 = await db.fetchrow("select status, meta from notifications where id = $1::uuid", nid)
    assert row2["status"] == "failed" and "error" in row2["meta"]


async def test_an_alert_is_canceled_not_sent_if_its_event_already_resolved(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    event = await make_event(db, "interface_is_down", device_id=device, resolved=True)
    nid = await _queue_one(db, contact_id=contact, event_id=event, send_at="now()")
    row = await db.fetchrow("select id, type, contact_id, event_id, previous_notification_id from notifications where id = $1::uuid", nid)

    channel = FakeChannel()
    outcome = await process_one(db, row, channel)
    assert outcome == "canceled" and channel.sent == []
    assert await db.fetchval("select status from notifications where id = $1::uuid", nid) == "canceled"


async def test_a_notification_with_no_event_is_canceled_rather_than_sent(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    nid = await _queue_one(db, contact_id=contact, send_at="now()")  # no event_id
    row = await db.fetchrow("select id, type, contact_id, event_id, previous_notification_id from notifications where id = $1::uuid", nid)

    channel = FakeChannel()
    outcome = await process_one(db, row, channel)
    assert outcome == "canceled" and channel.sent == []
    row2 = await db.fetchrow("select status, meta from notifications where id = $1::uuid", nid)
    assert row2["status"] == "canceled" and "error" in row2["meta"]


async def test_a_resolved_notification_is_canceled_if_its_alert_was_canceled(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    event = await make_event(db, "interface_is_down", device_id=device)
    alert = await _queue_one(db, contact_id=contact, event_id=event, send_at="now()")
    await db.execute("update notifications set status = 'canceled' where id = $1::uuid", alert)
    resolved = await _queue_one(db, type="resolved", contact_id=contact, event_id=event, send_at="now()", previous_notification_id=alert)
    row = await db.fetchrow("select id, type, contact_id, event_id, previous_notification_id from notifications where id = $1::uuid", resolved)

    outcome = await process_one(db, row, FakeChannel())
    assert outcome == "canceled"
