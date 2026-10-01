"""Events reach the notification pipeline, and flapping alarms are suppressed (Plan 20, risk K-14) - against a real
database, with the real notification sender driven by a fake channel so "what would actually be delivered" is
measured, not assumed. Synthetic Alertmanager payloads only; no device is contacted."""
from datetime import datetime, timedelta, timezone

from app.alerting.ingest import IngestSummary, process_alert
from app.notifications.sender import get_due, process_one
from tests.helpers import make_device, make_user
from tests.test_events_api import login_as
from tests.test_notification_pipeline import make_contact, set_event_config

WINDOW = 900
FAR_FUTURE = datetime.now(timezone.utc) + timedelta(days=1)


def alert(status="firing", alertname="interface_is_down", fingerprint="fp-flap", **labels):
    return {"status": status, "labels": {"alertname": alertname, "severity": "warning", **labels},
            "annotations": {"description": "d"}, "fingerprint": fingerprint}


class FakeChannel:
    def __init__(self) -> None:
        self.sent: list[str] = []

    async def send(self, notification_id: str) -> dict:
        self.sent.append(notification_id)
        return {}


async def ingest(db, status="firing", *, window=WINDOW, **kw) -> IngestSummary:
    summary = IngestSummary()
    await process_alert(db, alert(status, **kw), worker_uptime_seconds=9999, summary=summary, flap_window_seconds=window)
    return summary


async def deliver(db, channel, *, until=None) -> list[str]:
    """One pass of the real sender over everything due by `until`. Returns each outcome."""
    return [await process_one(db, n, channel) for n in await get_due(db, now=until)]


async def contact_for_device(db, name="admin"):
    """interface_is_down is a real seeded config: enabled, no delay, sends resolved. An ISP Admin sees every device."""
    uid = await make_user(db, name, "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, f"sw-{name}")
    return contact, device


async def notifications(db, contact):
    return [(r["type"], r["status"]) for r in await db.fetch(
        "select type, status from notifications where contact_id = $1::uuid order by created_at", contact)]


# --- events reach the notification pipeline (parity with legacy's event:created / event:resolved observers) ---

async def test_a_new_event_queues_an_alert_and_resolving_it_queues_a_resolved(db):
    contact, device = await contact_for_device(db)
    await ingest(db, dev_id=device)
    assert await notifications(db, contact) == [("alert", "queued")]
    await ingest(db, "resolved", dev_id=device)
    assert await notifications(db, contact) == [("alert", "queued"), ("resolved", "queued")]


async def test_an_event_name_with_no_notification_config_queues_nothing(db):
    contact, device = await contact_for_device(db)
    await ingest(db, alertname="no_config_for_this", dev_id=device)
    assert await notifications(db, contact) == []


async def test_a_manual_resolve_through_the_api_queues_a_resolved_notification(app_client, db):
    _, headers = await login_as(app_client, db, "op", "ISP Admin")
    contact, device = await contact_for_device(db)
    await ingest(db, dev_id=device)
    event_id = await db.fetchval("select id from events")
    response = await app_client.put(f"/api/v1/events/{event_id}/resolve", headers=headers)
    assert response.status_code == 200, response.text
    assert await notifications(db, contact) == [("alert", "queued"), ("resolved", "queued")]


# --- flapping suppression: events ---

async def test_refiring_within_the_window_reopens_the_same_event(db):
    await ingest(db)
    await ingest(db, "resolved")
    summary = await ingest(db)
    assert (summary.reopened, summary.created) == (1, 0)
    row = await db.fetchrow("select count(*) over () as n, resolved_at, flap_count, last_reopened_at from events")
    assert row["n"] == 1 and row["resolved_at"] is None and row["flap_count"] == 1 and row["last_reopened_at"] is not None


async def test_a_flapping_interface_is_one_event_not_hundreds(db):
    """Plan 20's acceptance check."""
    for _ in range(100):
        await ingest(db)
        await ingest(db, "resolved")
    assert await db.fetchval("select count(*) from events") == 1
    assert await db.fetchval("select flap_count from events") == 99


async def test_refiring_after_the_window_is_a_new_event(db):
    await ingest(db)
    await ingest(db, "resolved")
    await db.execute("update events set resolved_at = now() - make_interval(secs => $1)", WINDOW + 1)
    summary = await ingest(db)
    assert (summary.reopened, summary.created) == (0, 1)
    assert await db.fetchval("select count(*) from events") == 2


async def test_an_event_an_operator_resolved_by_hand_is_never_reopened(db):
    uid = await make_user(db, "op", "ISP Admin")
    await ingest(db)
    await db.execute("update events set resolved_at = now(), resolved_by_user_id = $1::uuid", uid)
    summary = await ingest(db)
    assert (summary.reopened, summary.created) == (0, 1)


async def test_a_different_fingerprint_is_never_merged_into_another_alarm(db):
    await ingest(db, fingerprint="fp-a")
    await ingest(db, "resolved", fingerprint="fp-a")
    summary = await ingest(db, fingerprint="fp-b")
    assert (summary.reopened, summary.created) == (0, 1)


async def test_a_zero_window_turns_suppression_off(db):
    await ingest(db, window=0)
    await ingest(db, "resolved", window=0)
    summary = await ingest(db, window=0)
    assert (summary.reopened, summary.created) == (0, 1)


# --- flapping suppression: notifications ---

async def test_a_contact_already_told_resolved_gets_a_fresh_alert_on_reopen(db):
    """Suppression must never leave someone believing a live alarm is over."""
    contact, device = await contact_for_device(db)
    channel = FakeChannel()
    await ingest(db, dev_id=device)
    await deliver(db, channel)
    await ingest(db, "resolved", dev_id=device)
    await deliver(db, channel, until=FAR_FUTURE)
    assert await notifications(db, contact) == [("alert", "sent"), ("resolved", "sent")]
    await ingest(db, dev_id=device)
    assert await notifications(db, contact) == [("alert", "sent"), ("resolved", "sent"), ("alert", "queued")]


async def test_after_a_flap_the_next_resolved_is_held_and_canceled_if_it_reopens_again(db):
    contact, device = await contact_for_device(db)
    channel = FakeChannel()
    await ingest(db, dev_id=device)
    await deliver(db, channel)
    await ingest(db, "resolved", dev_id=device)
    await deliver(db, channel, until=FAR_FUTURE)
    await ingest(db, dev_id=device)  # first reopen
    await deliver(db, channel, until=FAR_FUTURE)
    await ingest(db, "resolved", dev_id=device)
    held = await db.fetchval("select send_at from notifications where type = 'resolved' and status = 'queued'")
    assert held >= datetime.now(timezone.utc) + timedelta(seconds=WINDOW - 5)
    await ingest(db, dev_id=device)  # reopens before the held notification was due
    assert await notifications(db, contact) == [
        ("alert", "sent"), ("resolved", "sent"), ("alert", "sent"), ("resolved", "canceled"),
    ]


async def test_a_contact_whose_alert_is_still_pending_gets_nothing_extra(db):
    """pinger_host_down really has a 60-second delay before its alert goes out."""
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw-p")
    await ingest(db, alertname="pinger_host_down", dev_id=device)
    await db.execute("update notifications set status = 'queued'")  # the alert is still waiting out its delay
    await db.execute("delete from notifications where type = 'resolved'")
    await ingest(db, "resolved", alertname="pinger_host_down", dev_id=device)
    await ingest(db, alertname="pinger_host_down", dev_id=device)
    assert await notifications(db, contact) == [("alert", "queued"), ("resolved", "canceled")]


async def test_a_contact_whose_alert_was_canceled_by_a_fast_flap_is_alerted_on_reopen(db):
    """The sender cancels an alert whose event is already resolved by send time. If the alarm then comes back, that
    contact has heard nothing at all and must be told."""
    await set_event_config(db, "interface_is_down", delay_before_send_seconds=60, send_resolved=False)
    contact, device = await contact_for_device(db)
    channel = FakeChannel()
    await ingest(db, dev_id=device)
    await ingest(db, "resolved", dev_id=device)
    await deliver(db, channel, until=FAR_FUTURE)  # alert comes due with the event resolved: canceled
    assert await notifications(db, contact) == [("alert", "canceled")]
    await ingest(db, dev_id=device)
    assert await notifications(db, contact) == [("alert", "canceled"), ("alert", "queued")]


async def test_a_long_flapping_storm_delivers_one_alert_and_one_resolved(db):
    """Risk K-14. 30 fast flaps, with the real sender running after every transition; legacy would deliver 60
    messages. Even the first resolved is never sent mid-storm: it is paired 10 seconds after its alert (legacy's own
    rule), the alarm reopens inside that, and every resolved after it is held for the flap window. Only the last one,
    once the flapping stops, goes out."""
    contact, device = await contact_for_device(db)
    channel = FakeChannel()
    for _ in range(30):
        await ingest(db, dev_id=device)
        await deliver(db, channel)
        await ingest(db, "resolved", dev_id=device)
        await deliver(db, channel)
    assert len(channel.sent) == 1
    await deliver(db, channel, until=FAR_FUTURE)  # the flapping stopped: the held resolved finally goes out
    assert len(channel.sent) == 2
    delivered = [t for t, s in await notifications(db, contact) if s == "sent"]
    assert delivered == ["alert", "resolved"]
    assert await db.fetchval("select count(*) from events") == 1
