"""Maintenance windows (Plan 20, risk K-14): events are recorded but their notifications are held while a device is
under planned work, and anything still open when the window ends is announced. Against a real database, with
synthetic Alertmanager payloads; no device is contacted."""
from datetime import datetime, timedelta, timezone

import pytest_asyncio

from app.alerting.ingest import IngestSummary, process_alert
from app.alerting.maintenance import device_in_maintenance, release_suppressed
from app.core.database import create_pool
from app.scheduling.jobs import JobContext, run_maintenance_release, validate_params
from tests.helpers import make_device, make_group, make_user
from tests.test_events_api import login_as
from tests.test_notification_pipeline import make_contact


@pytest_asyncio.fixture
async def pool_ctx(clean):
    pool = await create_pool()
    yield JobContext(pool=pool, queue=None, slot="t", job_key="maintenance_release", now_ms=0)
    await pool.close()


def now() -> datetime:
    return datetime.now(timezone.utc)


async def window(db, *, device=None, group=None, start=-60, end=3600, canceled=False):
    """Seconds relative to now."""
    return str(await db.fetchval(
        "insert into maintenance_windows (device_id, device_group_id, starts_at, ends_at, reason, canceled_at) "
        "values ($1::uuid, $2::uuid, now() + make_interval(secs => $3), now() + make_interval(secs => $4), 'work', "
        "case when $5 then now() else null end) returning id",
        device, group, start, end, canceled,
    ))


async def end_window(db, window_id):
    await db.execute("update maintenance_windows set starts_at = now() - interval '2 hours', "
                     "ends_at = now() - interval '1 second' where id = $1::uuid", window_id)


async def ingest(db, status="firing", *, device=None, fingerprint="fp-m", name="interface_is_down"):
    summary = IngestSummary()
    labels = {"alertname": name, "severity": "warning"}
    if device:
        labels["dev_id"] = device
    await process_alert(db, {"status": status, "labels": labels, "annotations": {}, "fingerprint": fingerprint},
                        worker_uptime_seconds=9999, summary=summary)
    return summary


async def setup_contact(db):
    uid = await make_user(db, "admin", "ISP Admin")
    return await make_contact(db, uid)


async def sent_types(db, contact):
    return [r["type"] for r in await db.fetch(
        "select type from notifications where contact_id = $1::uuid order by created_at", contact)]


# --- what a window covers ---

async def test_a_window_on_the_device_covers_it(db):
    device = await make_device(db, "sw1")
    await window(db, device=device)
    assert await device_in_maintenance(db, device)


async def test_a_window_on_an_ancestor_group_covers_a_device_in_a_child_group(db):
    parent = await make_group(db, "region")
    child = await make_group(db, "site", parent)
    device = await make_device(db, "sw1", child)
    await window(db, group=parent)
    assert await device_in_maintenance(db, device)


async def test_windows_not_started_ended_canceled_or_elsewhere_cover_nothing(db):
    device = await make_device(db, "sw1")
    other = await make_device(db, "sw2")
    await window(db, device=device, start=600, end=3600)  # not started
    await window(db, device=device, start=-7200, end=-1)  # over
    await window(db, device=device, canceled=True)
    await window(db, device=other)
    assert not await device_in_maintenance(db, device)


async def test_a_device_less_event_is_never_in_maintenance(db):
    assert not await device_in_maintenance(db, None)


# --- what a window does to events and notifications ---

async def test_an_event_during_a_window_is_recorded_but_notifies_nobody(db):
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    await window(db, device=device)
    await ingest(db, device=device)
    assert await db.fetchval("select suppressed_by_maintenance from events") is True
    await ingest(db, "resolved", device=device)
    assert await sent_types(db, contact) == []  # neither the alert nor, since nobody was alerted, the resolved


async def test_an_event_still_open_when_the_window_ends_is_announced(db):
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    w = await window(db, device=device)
    await ingest(db, device=device)
    assert await release_suppressed(db) == 0  # still inside the window
    await end_window(db, w)
    assert await release_suppressed(db) == 1
    assert await sent_types(db, contact) == ["alert"]
    assert await db.fetchval("select suppressed_by_maintenance from events") is False
    assert await release_suppressed(db) == 0  # never twice
    await ingest(db, "resolved", device=device)
    assert await sent_types(db, contact) == ["alert", "resolved"]  # once announced, it closes normally


async def test_an_event_that_opened_and_closed_inside_the_window_stays_quiet(db):
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    w = await window(db, device=device)
    await ingest(db, device=device)
    await ingest(db, "resolved", device=device)
    await end_window(db, w)
    assert await release_suppressed(db) == 0
    assert await sent_types(db, contact) == []
    assert await db.fetchval("select suppressed_by_maintenance from events") is True  # kept as history


async def test_release_waits_while_another_window_still_covers_the_device(db):
    group = await make_group(db, "site")
    device = await make_device(db, "sw1", group)
    short = await window(db, device=device)
    await window(db, group=group, end=7200)
    await ingest(db, device=device)
    await end_window(db, short)
    assert await release_suppressed(db) == 0


async def test_a_suppressed_event_that_flaps_stays_quiet_inside_and_is_announced_after(db):
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    w = await window(db, device=device)
    await ingest(db, device=device)
    await ingest(db, "resolved", device=device)
    await ingest(db, device=device)  # reopened inside the window
    assert await sent_types(db, contact) == []
    await ingest(db, "resolved", device=device)
    await end_window(db, w)
    await ingest(db, device=device)  # reopened after the window: nobody was ever told, so everyone is now
    assert await sent_types(db, contact) == ["alert"]
    assert await db.fetchval("select suppressed_by_maintenance from events") is False


async def test_an_event_that_began_before_the_window_keeps_notifying(db):
    """Contacts already knew about it; going quiet mid-incident could leave someone thinking it is still open."""
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    await ingest(db, device=device)
    await window(db, device=device)
    await ingest(db, "resolved", device=device)
    assert await sent_types(db, contact) == ["alert", "resolved"]


async def test_the_scheduled_release_job_is_seeded_on_and_releases(db, pool_ctx):
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    w = await window(db, device=device)
    await ingest(db, device=device)
    await end_window(db, w)
    note = await run_maintenance_release(pool_ctx, validate_params("maintenance_release", {}))
    assert note.startswith("released 1 ")
    assert await sent_types(db, contact) == ["alert"]


# --- the API ---

async def test_creating_a_window_is_scoped_and_audited(app_client, db):
    group = await make_group(db, "mine")
    mine = await make_device(db, "mine-sw", group)
    theirs = await make_device(db, "their-sw")
    uid, reseller = await login_as(app_client, db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", uid, group)
    ends = (now() + timedelta(hours=2)).isoformat()

    made = await app_client.post("/api/v1/maintenance-windows", headers=reseller,
                                 json={"device_id": mine, "ends_at": ends, "reason": "fiber splice"})
    assert made.status_code == 201, made.text
    assert made.json()["active"] is True and made.json()["created_by_user_id"] == uid
    assert await db.fetchval("select count(*) from audit_logs where action = 'maintenance_window.created'") == 1

    refused = await app_client.post("/api/v1/maintenance-windows", headers=reseller,
                                    json={"device_id": theirs, "ends_at": ends, "reason": "x"})
    assert refused.status_code == 404
    group_ok = await app_client.post("/api/v1/maintenance-windows", headers=reseller,
                                     json={"device_group_id": group, "ends_at": ends, "reason": "site power"})
    assert group_ok.status_code == 201
    other_group = await make_group(db, "not-mine")
    group_refused = await app_client.post("/api/v1/maintenance-windows", headers=reseller,
                                          json={"device_group_id": other_group, "ends_at": ends, "reason": "x"})
    assert group_refused.status_code == 404
    assert await db.fetchval("select count(*) from maintenance_windows") == 2


async def test_a_reseller_lists_only_windows_in_their_scope(app_client, db):
    group = await make_group(db, "mine")
    mine = await make_device(db, "mine-sw", group)
    theirs = await make_device(db, "their-sw")
    await window(db, device=mine)
    await window(db, device=theirs)
    await window(db, group=await make_group(db, "other-site"))
    uid, reseller = await login_as(app_client, db, "res", "Reseller Viewer")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", uid, group)
    listing = await app_client.get("/api/v1/maintenance-windows", headers=reseller)
    assert listing.status_code == 200
    assert [i["device_id"] for i in listing.json()["items"]] == [mine]


async def test_a_role_without_events_resolve_cannot_create_a_window(app_client, db):
    device = await make_device(db, "sw1")
    _, viewer = await login_as(app_client, db, "viewer", "Reseller Viewer")
    response = await app_client.post("/api/v1/maintenance-windows", headers=viewer, json={
        "device_id": device, "ends_at": (now() + timedelta(hours=1)).isoformat(), "reason": "x"})
    assert response.status_code == 403


async def test_invalid_windows_are_refused(app_client, db):
    device = await make_device(db, "sw1")
    group = await make_group(db, "g")
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    soon = (now() + timedelta(hours=1)).isoformat()
    cases = [
        {"device_id": device, "device_group_id": group, "ends_at": soon, "reason": "both targets"},
        {"ends_at": soon, "reason": "no target"},
        {"device_id": device, "ends_at": (now() + timedelta(days=8)).isoformat(), "reason": "too long"},
        {"device_id": device, "ends_at": (now() - timedelta(minutes=1)).isoformat(),
         "starts_at": (now() - timedelta(hours=1)).isoformat(), "reason": "already over"},
        {"device_id": device, "ends_at": "2030-01-01T10:00:00", "reason": "no time zone"},
        {"device_id": device, "ends_at": soon, "reason": ""},
    ]
    for body in cases:
        response = await app_client.post("/api/v1/maintenance-windows", headers=admin, json=body)
        assert response.status_code == 422, (body["reason"], response.text)
    assert await db.fetchval("select count(*) from maintenance_windows") == 0


async def test_canceling_a_window_announces_what_it_held_at_once(app_client, db):
    contact = await setup_contact(db)
    device = await make_device(db, "sw1")
    _, admin = await login_as(app_client, db, "op", "ISP Admin")
    w = await window(db, device=device)
    await ingest(db, device=device)
    canceled = await app_client.post(f"/api/v1/maintenance-windows/{w}/cancel", headers=admin)
    assert canceled.status_code == 200 and canceled.json()["released_events"] == 1
    assert await sent_types(db, contact) == ["alert"]
    assert (await app_client.post(f"/api/v1/maintenance-windows/{w}/cancel", headers=admin)).status_code == 409
    assert await db.fetchval("select count(*) from audit_logs where action = 'maintenance_window.canceled'") == 1


async def test_a_reseller_cannot_cancel_a_window_outside_their_scope(app_client, db):
    theirs = await make_device(db, "their-sw")
    w = await window(db, device=theirs)
    uid, reseller = await login_as(app_client, db, "res", "Reseller Admin")
    assert (await app_client.post(f"/api/v1/maintenance-windows/{w}/cancel", headers=reseller)).status_code == 404
    assert await db.fetchval("select canceled_at from maintenance_windows") is None
