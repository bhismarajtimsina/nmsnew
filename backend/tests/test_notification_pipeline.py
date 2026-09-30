"""Connecting the pure decision logic to real events, users, contacts and device scope - against a real database.
No channel is involved; see test_notification_sender.py for the send step."""
from datetime import datetime, timezone

from app.notifications.pipeline import eligible_contacts, get_event_config, queue_for_event
from tests.helpers import make_device, make_group, make_user


async def make_contact(db, user_id, *, type="email", value="a@example.com", severities=None, ignore=None):
    return str(await db.fetchval(
        "insert into notification_contacts (user_id, type, value, severities, ignore_event_names) "
        "values ($1::uuid, $2, $3, $4, $5) returning id",
        user_id, type, value, list(severities) if severities else ["info", "warning", "critical"], list(ignore or []),
    ))


async def set_event_config(db, event_name, **fields):
    """Real event names (pinger_host_down, interface_is_down, ...) are already seeded; update the seeded row rather
    than insert a colliding one. Falls back to inserting when the name genuinely has no config yet."""
    existing = await db.fetchval("select id from notification_event_config where event_name = $1", event_name)
    if existing is not None:
        if fields:
            columns = ", ".join(f"{k} = ${i + 2}" for i, k in enumerate(fields))
            await db.execute(f"update notification_event_config set {columns} where event_name = $1", event_name, *fields.values())
        return existing
    cols = ", ".join(["event_name", *fields.keys()])
    placeholders = ", ".join(f"${i + 1}" for i in range(len(fields) + 1))
    return await db.fetchval(
        f"insert into notification_event_config ({cols}) values ({placeholders}) returning id",
        event_name, *fields.values(),
    )


async def make_event(db, name, *, device_id=None, severity="warning", resolved=False):
    return await db.fetchval(
        "insert into events (name, dedup_key, labels, severity, device_id, resolved_at) "
        "values ($1, $1, '{}', $2, $3::uuid, case when $4 then now() else null end) returning id",
        name, severity, device_id, resolved,
    )


async def test_get_event_config_returns_none_for_an_unconfigured_event_name(db):
    assert await get_event_config(db, "no_such_event") is None


async def test_get_event_config_loads_ignored_devices(db):
    device = await make_device(db, "ignored-dev")
    config_id = await set_event_config(db, "interface_is_down", delay_before_send_seconds=5)
    await db.execute("insert into notification_event_ignored_devices (config_id, device_id) values ($1::uuid, $2::uuid)", config_id, device)
    cfg = await get_event_config(db, "interface_is_down")
    assert cfg.delay_before_send_seconds == 5 and cfg.ignored_device_ids == {device}


async def test_a_scope_all_user_is_an_eligible_contact_for_any_device(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact_id = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    contacts = await eligible_contacts(db, device)
    assert {c.id for c in contacts} == {contact_id}


async def test_a_scoped_user_is_eligible_only_for_their_own_group_including_descendants(db):
    parent = await make_group(db, "parent")
    child = await make_group(db, "child", parent)
    uid = await make_user(db, "res", "Reseller Admin")
    contact_id = await make_contact(db, uid)
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", uid, parent)

    in_scope = await make_device(db, "in-scope", child)
    out_of_scope = await make_device(db, "out-of-scope")
    assert {c.id for c in await eligible_contacts(db, in_scope)} == {contact_id}
    assert await eligible_contacts(db, out_of_scope) == []


async def test_a_device_less_event_reaches_only_send_global_holders(db):
    admin_uid = await make_user(db, "admin", "ISP Admin")  # holds notifications.send_global
    reseller_uid = await make_user(db, "res", "Reseller Admin")  # does not
    admin_contact = await make_contact(db, admin_uid)
    await make_contact(db, reseller_uid)
    assert {c.id for c in await eligible_contacts(db, None)} == {admin_contact}


async def test_queuing_an_alert_respects_severity_and_delay(db):
    await set_event_config(db, "pinger_host_down", delay_before_send_seconds=60)
    uid = await make_user(db, "admin", "ISP Admin")
    wants_it = await make_contact(db, uid, value="a@x.com", severities=["warning"])
    doesnt_want_it = await make_contact(db, uid, value="b@x.com", severities=["critical"])
    device = await make_device(db, "sw1")
    event = await make_event(db, "pinger_host_down", device_id=device, severity="warning")

    now = datetime.now(timezone.utc)
    created = await queue_for_event(db, event, now=now)
    assert created == 1
    row = await db.fetchrow("select contact_id, type, send_at from notifications where event_id = $1::uuid", event)
    assert str(row["contact_id"]) == wants_it and row["type"] == "alert"
    assert (row["send_at"] - now).total_seconds() == 60


async def test_queuing_a_resolved_event_pairs_with_its_previous_alert(db):
    await set_event_config(db, "interface_is_down", delay_before_send_seconds=0)
    uid = await make_user(db, "admin", "ISP Admin")
    await make_contact(db, uid)
    device = await make_device(db, "sw1")
    event = await make_event(db, "interface_is_down", device_id=device)
    await queue_for_event(db, event)
    alert = await db.fetchrow("select id, send_at from notifications where event_id = $1::uuid and type = 'alert'", event)

    await db.execute("update events set resolved_at = now() where id = $1::uuid", event)
    created = await queue_for_event(db, event)
    assert created == 1
    resolved = await db.fetchrow("select previous_notification_id, send_at from notifications where event_id = $1::uuid and type = 'resolved'", event)
    assert str(resolved["previous_notification_id"]) == str(alert["id"])
    assert (resolved["send_at"] - alert["send_at"]).total_seconds() == 10


async def test_an_event_with_no_matching_config_is_not_queued(db):
    device = await make_device(db, "sw1")
    event = await make_event(db, "totally_unconfigured_event_name", device_id=device)
    assert await queue_for_event(db, event) == 0


async def test_an_ignored_device_is_not_queued(db):
    device = await make_device(db, "sw1")
    config_id = await set_event_config(db, "interface_is_down")
    await db.execute("insert into notification_event_ignored_devices (config_id, device_id) values ($1::uuid, $2::uuid)", config_id, device)
    uid = await make_user(db, "admin", "ISP Admin")
    await make_contact(db, uid)
    event = await make_event(db, "interface_is_down", device_id=device)
    assert await queue_for_event(db, event) == 0
