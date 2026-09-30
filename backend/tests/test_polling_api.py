import json

from redis.asyncio import Redis

from app.core.config import settings
from app.workers.queue import verify
from app.workers.runner import POLL_STREAM
from tests.helpers import bearer, make_user
from tests.polling_helpers import SYS_NAME, make_active_profile, make_pollable


async def login_as(app_client, db, name, role):
    user_id = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user_id, headers


async def test_a_manual_poll_is_signed_queued_and_audited(app_client, db):
    _, headers = await login_as(app_client, db, "noc", "ISP NOC")
    await make_active_profile(db)
    device = await make_pollable(db, "10.70.0.1")
    response = await app_client.post(f"/api/v1/devices/{device}/poll", headers=headers, json={"profile": "switch_basic"})
    assert response.status_code == 202 and response.json()["status"] == "queued"
    (_, fields), = await app_client.app.state.redis.xrange(POLL_STREAM)
    assert verify(fields, settings.job_signing_key) and fields["device_id"] == device and fields["profile"] == "switch_basic"
    assert fields["requested_by"] and fields["kind"] == "manual"
    audit = await db.fetchrow("select metadata from audit_logs where action = 'poll.requested'")
    assert json.loads(audit["metadata"])["profile"] == "switch_basic"


async def test_a_manual_poll_is_refused_at_once_with_the_reason_when_the_device_cannot_be_polled(app_client, db):
    _, headers = await login_as(app_client, db, "noc", "ISP NOC")
    await make_active_profile(db)
    legacy = await make_pollable(db, "10.70.0.1", owner="legacy")
    off = await make_pollable(db, "10.70.0.2", enabled=False)
    for device, reason in ((legacy, "legacy system"), (off, "switched off for this device")):
        refused = await app_client.post(f"/api/v1/devices/{device}/poll", headers=headers, json={"profile": "switch_basic"})
        assert refused.status_code == 409 and reason in refused.json()["detail"]
    assert await app_client.app.state.redis.xlen(POLL_STREAM) == 0


async def test_manual_polls_need_an_active_profile_and_a_sane_name(app_client, db):
    _, headers = await login_as(app_client, db, "noc", "ISP NOC")
    device = await make_pollable(db, "10.70.0.1")
    await make_active_profile(db, "draft_profile", activate=False)
    assert (await app_client.post(f"/api/v1/devices/{device}/poll", headers=headers, json={"profile": "draft_profile"})).status_code == 404
    assert (await app_client.post(f"/api/v1/devices/{device}/poll", headers=headers, json={"profile": "../../etc"})).status_code == 422
    assert (await app_client.post(f"/api/v1/devices/{device}/poll", headers=headers, json={"profile": ""})).status_code == 422


async def test_manual_polls_are_rate_limited_per_user(app_client, db, monkeypatch):
    monkeypatch.setattr(settings, "poll_manual_per_minute", 2)
    _, headers = await login_as(app_client, db, "noc", "ISP NOC")
    await make_active_profile(db)
    device = await make_pollable(db, "10.70.0.1")
    codes = [(await app_client.post(f"/api/v1/devices/{device}/poll", headers=headers, json={"profile": "switch_basic"})).status_code for _ in range(4)]
    assert codes == [202, 202, 429, 429]
    assert await app_client.app.state.redis.xlen(POLL_STREAM) == 2


async def test_polls_and_history_respect_scope_and_permission(app_client, db):
    await make_active_profile(db)
    mine, theirs = await make_pollable(db, "10.70.0.1"), await make_pollable(db, "10.70.0.2")
    group = await db.fetchval("insert into device_groups (name) values ('mine') returning id")
    await db.execute("update devices set group_id = $1 where id = $2::uuid", group, mine)
    for device in (mine, theirs):
        await db.execute("insert into polling_results (device_id, profile_name, profile_version, outcome, rows) values ($1::uuid, 'switch_basic', 1, 'ok', 3)", device)
    user_id, reseller = await login_as(app_client, db, "res", "Reseller Operator")
    await db.execute("insert into role_permissions select r.id, p.id from roles r, permissions p where r.name = 'Reseller Operator' and p.code = 'pollers.run' on conflict do nothing")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2)", user_id, group)
    await make_user(db, "viewer", "Reseller Viewer")
    viewer = await bearer(app_client, "viewer")
    app_client.cookies.clear()
    assert (await app_client.get(f"/api/v1/devices/{mine}/poll-history", headers=reseller)).status_code == 200
    assert (await app_client.get(f"/api/v1/devices/{theirs}/poll-history", headers=reseller)).status_code == 404
    assert (await app_client.post(f"/api/v1/devices/{theirs}/poll", headers=reseller, json={"profile": "switch_basic"})).status_code == 404
    assert (await app_client.post(f"/api/v1/devices/{mine}/poll", headers=viewer, json={"profile": "switch_basic"})).status_code == 403   # the viewer role cannot poll
    assert (await app_client.post(f"/api/v1/devices/{mine}/poll", headers=reseller, json={"profile": "switch_basic"})).status_code == 202   # in scope, with the permission
    assert (await app_client.get("/api/v1/devices/not-a-uuid/poll-history", headers=reseller)).status_code == 404


async def test_history_shows_results_and_the_breaker_state(app_client, db):
    _, headers = await login_as(app_client, db, "noc", "ISP NOC")
    device = await make_pollable(db, "10.70.0.1")
    await db.execute("insert into polling_results (device_id, profile_name, outcome, error) values ($1::uuid, 'switch_basic', 'timeout', 'no response')", device)
    await db.execute("insert into device_poll_state (device_id, consecutive_failures, breaker_open_until, last_error) values ($1::uuid, 5, now() + interval '5 minutes', 'no response')", device)
    body = (await app_client.get(f"/api/v1/devices/{device}/poll-history", headers=headers)).json()
    assert body["results"][0]["outcome"] == "timeout" and body["state"]["breaker_open"] is True and body["state"]["consecutive_failures"] == 5


async def test_worker_health_and_dead_letters_are_visible_to_those_who_watch_the_system(app_client, db):
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    _, support = await login_as(app_client, db, "support", "ISP Support")
    await db.execute("insert into worker_heartbeats (worker_id, kind, status) values ('w-live', 'discovery', 'running')")
    await db.execute("insert into worker_heartbeats (worker_id, kind, status, last_seen) values ('w-stale', 'poller', 'running', now() - interval '5 minutes')")
    await db.execute("insert into dead_letter_jobs (stream, job_id, fields, reason, deliveries) values ('discovery.jobs', 'j1', '{\"device_id\": \"d\"}', 'bad signature', 1)")
    workers = {w["worker_id"]: w["healthy"] for w in (await app_client.get("/api/v1/workers", headers=noc)).json()}
    assert workers == {"w-live": True, "w-stale": False}
    letters = (await app_client.get("/api/v1/dead-letters", headers=noc)).json()
    assert letters[0]["reason"] == "bad signature" and letters[0]["fields"] == {"device_id": "d"}
    assert (await app_client.get("/api/v1/workers", headers=support)).status_code == 403
    assert (await app_client.get("/api/v1/dead-letters", headers=support)).status_code == 403


async def test_adding_a_device_and_running_a_worker_identifies_the_device_end_to_end(app_client, db):
    from app.core.crypto import EncryptionService
    from app.polling.engine import Context
    from app.polling.fake import FakeTransport
    from app.workers.runner import Worker

    _, headers = await login_as(app_client, db, "boss", "ISP Admin")
    profile = (await app_client.post("/api/v1/device-access-profiles", headers=headers, json={"name": "lab", "snmp_version": "v2c", "snmp_community": "lab-community-1"})).json()["id"]
    created = await app_client.post("/api/v1/devices", headers=headers, json={
        "name": "lab-sw", "management_ip": "10.80.0.9", "device_type": "switch", "vendor_slug": "bdcom", "family_slug": "bdcom-switch", "access_profile_id": profile})
    assert created.status_code == 201 and created.json()["discovery"]["status"] == "queued"
    transport = FakeTransport()
    transport.script_get("10.80.0.9", {"1.3.6.1.2.1.1.5.0": "lab-sw.example", "1.3.6.1.2.1.1.1.0": "BDCOM lab", "1.3.6.1.2.1.1.2.0": "1.3.6.1.4.1.3320.1.1", "1.3.6.1.2.1.1.3.0": 42})
    app = app_client.app
    ctx = Context(app.state.pool, app.state.redis, transport, EncryptionService.from_settings(), settings)
    settings_interval = settings.poll_min_interval_seconds
    settings.poll_min_interval_seconds = 0
    try:
        outcome = await Worker(ctx, {"discovery"}, worker_id="e2e").run_once()
    finally:
        settings.poll_min_interval_seconds = settings_interval
    assert outcome["done"] == 1
    device = (await app_client.get(f"/api/v1/devices/{created.json()['device']['id']}", headers=headers)).json()
    assert device["polling_enabled"] is False                                          # identifying a device does not switch polling on
    history = (await app_client.get(f"/api/v1/devices/{created.json()['device']['id']}/discovery", headers=headers)).json()
    assert history[0]["status"] == "succeeded"
    assert transport.seen_communities == ["lab-community-1"]
