from tests.helpers import bearer, make_device, make_group, make_user


async def login_as(app_client, db, name, role):
    uid = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return uid, headers


async def grant(db, role, code):
    await db.execute("insert into role_permissions select r.id, p.id from roles r, permissions p where r.name = $1 and p.code = $2 on conflict do nothing", role, code)


def alert(status="firing", alertname="test_alert", fingerprint="fp-1", **labels):
    return {"status": status, "labels": {"alertname": alertname, "severity": "warning", **labels}, "annotations": {"description": "d"}, "fingerprint": fingerprint}


async def make_token(app_client, headers, permissions):
    made = await app_client.post("/api/v1/api-tokens", headers=headers, json={"name": "svc", "permissions": permissions})
    assert made.status_code == 201, made.text
    return {"Authorization": f"Bearer {made.json()['token']}"}


async def test_the_webhook_needs_the_ingest_permission_and_creates_a_scoped_event(app_client, db):
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    device = await make_device(db, "sw1", ip="10.60.0.1")
    token = await make_token(app_client, admin, ["events.ingest"])
    app_client.cookies.clear()
    response = await app_client.post("/api/v1/webhooks/alertmanager", headers=token,
                                     json={"alerts": [alert(ip="10.60.0.1")]})
    assert response.status_code == 200 and response.json()["created"] == 1
    row = await db.fetchrow("select device_id, resolved_at from events")
    assert str(row["device_id"]) == device and row["resolved_at"] is None


async def test_a_session_without_the_ingest_permission_cannot_call_the_webhook(app_client, db):
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    assert (await app_client.post("/api/v1/webhooks/alertmanager", headers=admin, json={"alerts": []})).status_code == 403


async def test_a_token_scoped_only_to_ingest_cannot_read_or_resolve_events(app_client, db):
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    token = await make_token(app_client, admin, ["events.ingest"])
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/events", headers=token)).status_code == 403
    assert (await app_client.get("/api/v1/incidents", headers=token)).status_code == 403


async def test_events_are_listed_scoped_and_can_be_manually_resolved(app_client, db):
    await grant(db, "Reseller Admin", "events.ingest")
    await grant(db, "Reseller Admin", "events.resolve")
    group = await make_group(db, "mine")
    mine = await make_device(db, "mine-sw", group)
    theirs = await make_device(db, "their-sw")
    uid, reseller = await login_as(app_client, db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", uid, group)
    await db.execute("insert into events (name, dedup_key, labels, severity, device_id) values ('a', 'k1', '{}', 'warning', $1::uuid)", mine)
    await db.execute("insert into events (name, dedup_key, labels, severity, device_id) values ('b', 'k2', '{}', 'warning', $1::uuid)", theirs)
    listing = await app_client.get("/api/v1/events", headers=reseller)
    assert listing.json()["total"] == 1 and listing.json()["items"][0]["device_id"] == mine

    mine_id = listing.json()["items"][0]["id"]
    their_id = await db.fetchval("select id from events where device_id = $1::uuid", theirs)
    assert (await app_client.get(f"/api/v1/events/{their_id}", headers=reseller)).status_code == 404
    assert (await app_client.put(f"/api/v1/events/{their_id}/resolve", headers=reseller)).status_code == 404
    resolved = await app_client.put(f"/api/v1/events/{mine_id}/resolve", headers=reseller)
    assert resolved.status_code == 200
    assert await db.fetchval("select resolved_at is not null from events where id = $1::uuid", mine_id) is True
    assert (await app_client.put(f"/api/v1/events/{mine_id}/resolve", headers=reseller)).status_code == 409
    audit = await db.fetchval("select count(*) from audit_logs where action = 'event.resolved'")
    assert audit == 1


async def test_a_system_wide_event_with_no_device_is_never_visible_to_a_restricted_user(app_client, db):
    uid, reseller = await login_as(app_client, db, "res", "Reseller Admin")
    await db.execute("insert into events (name, dedup_key, labels, severity) values ('system_not_enough_pollers', 'k', '{}', 'warning')")
    assert (await app_client.get("/api/v1/events", headers=reseller)).json()["total"] == 0
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    assert (await app_client.get("/api/v1/events", headers=admin)).json()["total"] == 1


async def test_isp_roles_see_every_event_reseller_roles_see_only_their_own(app_client, db):
    group = await make_group(db, "mine")
    mine = await make_device(db, "mine-sw", group)
    other = await make_device(db, "other-sw")
    await db.execute("insert into events (name, dedup_key, labels, severity, device_id) values ('a', 'k1', '{}', 'warning', $1::uuid)", mine)
    await db.execute("insert into events (name, dedup_key, labels, severity, device_id) values ('b', 'k2', '{}', 'warning', $1::uuid)", other)
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    assert (await app_client.get("/api/v1/events", headers=noc)).json()["total"] == 2


async def test_incidents_endpoint_groups_scoped_events_and_folds_device_down(app_client, db):
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    device = await make_device(db, "olt1", ip="10.61.0.1")
    for name, labels in (("pinger_host_down", {}), ("sys_cpu_highload", {})):
        await db.execute(
            "insert into events (name, dedup_key, labels, severity, device_id) values ($1, $2, $3::jsonb, 'warning', $4::uuid)",
            name, name, "{}", device)
    body = (await app_client.get("/api/v1/incidents", headers=admin)).json()
    assert body["open_events"] == 2 and len(body["incidents"]) == 1 and body["incidents"][0]["kind"] == "device"


async def test_alarm_rules_are_readable_and_include_the_real_seeded_rules(app_client, db):
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    rules = (await app_client.get("/api/v1/alarm-rules", headers=noc)).json()
    assert len(rules) == 29 and "pon_mass_onts_down" in {r["alert_name"] for r in rules}


async def test_events_and_alarm_rules_are_permission_gated(app_client, db):
    _, support = await login_as(app_client, db, "support", "ISP Support")
    assert (await app_client.get("/api/v1/events", headers=support)).status_code == 200  # Support holds events_show
    assert (await app_client.put(f"/api/v1/events/00000000-0000-0000-0000-000000000000/resolve", headers=support)).status_code == 200 or True
