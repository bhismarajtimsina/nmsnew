import json

import asyncpg
import pytest
from redis.exceptions import RedisError

from app.core.config import settings
from tests.helpers import bearer, make_device, make_group, make_interface, make_user

SAFE = ["1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0", "1.3.6.1.2.1.1.5.0"]


async def grant(db, role, code):
    await db.execute("insert into role_permissions select r.id, p.id from roles r, permissions p where r.name = $1 and p.code = $2 on conflict do nothing", role, code)


async def profile(db, name="snmp"):
    return str(await db.fetchval(
        "insert into device_access_profiles (name, snmp_version, snmp_community_enc) values ($1, 'v2c', 'v1:test1:x') returning id", name))


async def login_as(app_client, db, name, role):
    await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


def device(**extra):
    return {"name": "core-sw-1", "management_ip": "10.10.0.11", "device_type": "switch", **extra}


async def stream(app_client):
    return await app_client.app.state.redis.xrange("discovery.jobs")


async def test_a_new_device_starts_with_polling_off_and_only_safe_discovery_queued(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    pid = await profile(db)
    made = await app_client.post("/api/v1/devices", headers=headers,
                                 json=device(vendor_slug="bdcom", family_slug="bdcom-switch", access_profile_id=pid))
    assert made.status_code == 201, made.text
    body = made.json()
    assert body["device"]["management_ip"] == "10.10.0.11"
    assert body["device"]["polling_enabled"] is False and body["device"]["polling_owner"] == "cybersathy"
    assert body["device"]["vendor"] == "bdcom" and body["discovery"]["status"] == "queued"
    job = await db.fetchrow("select * from discovery_jobs")
    assert list(job["oids"]) == SAFE and job["status"] == "queued" and job["timeout_ms"] == 2000
    entries = await stream(app_client)
    assert len(entries) == 1 and json.loads(entries[0][1]["oids"]) == SAFE and entries[0][1]["discovery_id"] == str(job["id"])
    assert await db.fetchval("select created_by::text from devices") is not None


async def test_the_database_itself_refuses_a_discovery_job_that_asks_for_more(db):
    device_id = await make_device(db, "sw")
    for oids in (SAFE + ["1.3.6.1.2.1.2.2.1.2"], ["1.3.6.1.2.1.31.1.1.1.18"], ["1.3.6.1.4.1.3320"], []):
        with pytest.raises(asyncpg.CheckViolationError):
            await db.execute("insert into discovery_jobs (device_id, oids, timeout_ms, retries) values ($1::uuid, $2::text[], 2000, 1)", device_id, oids)
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into discovery_jobs (device_id, oids, timeout_ms, retries, status) values ($1::uuid, $2::text[], 2000, 1, 'skipped')", device_id, SAFE)


async def test_discovery_is_skipped_with_a_reason_when_it_cannot_run(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    no_profile = await app_client.post("/api/v1/devices", headers=headers, json=device())
    assert no_profile.json()["discovery"] == {"job_id": no_profile.json()["discovery"]["job_id"], "status": "skipped", "reason": "no access profile: nothing to authenticate with"}
    await db.execute("update vendor_model_families set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom-olt'")
    pid = await profile(db)
    off = await app_client.post("/api/v1/devices", headers=headers, json=device(name="olt", management_ip="10.10.0.12", device_type="olt",
                                                                                   vendor_slug="bdcom", family_slug="bdcom-olt", access_profile_id=pid))
    assert off.status_code == 201 and off.json()["discovery"]["status"] == "skipped" and "switched off" in off.json()["discovery"]["reason"]
    assert await stream(app_client) == []            # nothing skipped is ever published


async def test_a_redis_outage_does_not_lose_the_job_or_the_device(app_client, db, monkeypatch):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    pid = await profile(db)

    async def broken(*args, **kwargs):
        raise RedisError("down")

    monkeypatch.setattr(app_client.app.state.redis, "xadd", broken)
    made = await app_client.post("/api/v1/devices", headers=headers, json=device(access_profile_id=pid))
    assert made.status_code == 201
    assert await db.fetchval("select status from discovery_jobs") == "queued"      # the database row is the source of truth


@pytest.mark.parametrize("address", ["127.0.0.1", "0.0.0.0", "224.0.0.5", "169.254.10.10", "255.255.255.255", "240.0.0.1", "::1", "not-an-ip", "", "10.0.0.256", "10.0.0"])
async def test_unusable_management_addresses_are_refused(app_client, db, address):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(management_ip=address))).status_code == 422
    assert await db.fetchval("select count(*) from devices") == 0


async def test_addresses_must_be_inside_the_configured_management_networks(app_client, db, monkeypatch):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    monkeypatch.setattr(settings, "management_networks", ("10.10.0.0/16",))
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(management_ip="192.168.1.5"))).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(management_ip="10.10.4.5"))).status_code == 201


async def test_registry_links_are_checked_and_the_device_type_must_match_the_family(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    mismatch = await app_client.post("/api/v1/devices", headers=headers, json=device(device_type="olt", vendor_slug="bdcom", family_slug="bdcom-switch"))
    assert mismatch.status_code == 422 and "does not match the model family" in str(mismatch.json())
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(vendor_slug="bdcom", family_slug="nope"))).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(vendor_slug="bdcom"))).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(device_type="bridge"))).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(name=""))).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(group_id="nope"))).status_code == 422
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(access_profile_id="00000000-0000-0000-0000-000000000000"))).status_code == 422
    assert await db.fetchval("select count(*) from devices") == 0


async def test_a_duplicate_management_address_is_refused(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device())).status_code == 201
    again = await app_client.post("/api/v1/devices", headers=headers, json=device(name="other"))
    assert again.status_code == 422 and "already exists" in str(again.json())


async def test_a_restricted_user_can_only_add_devices_inside_their_scope(app_client, db):
    await grant(db, "Reseller Admin", "devices.manage")
    mine, theirs = await make_group(db, "mine"), await make_group(db, "theirs")
    user_id = await make_user(db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user_id, mine)
    headers = await bearer(app_client, "res")
    app_client.cookies.clear()
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(group_id=mine))).status_code == 201
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(name="x", management_ip="10.10.0.99", group_id=theirs))).status_code == 404
    assert (await app_client.post("/api/v1/devices", headers=headers, json=device(name="y", management_ip="10.10.0.98"))).status_code == 422  # no group at all
    assert await db.fetchval("select count(*) from devices") == 1


async def test_a_restricted_user_cannot_change_or_delete_what_is_not_theirs(app_client, db):
    await grant(db, "Reseller Admin", "devices.manage")
    mine, theirs = await make_group(db, "mine"), await make_group(db, "theirs")
    other = await make_device(db, "foreign", theirs)
    own = await make_device(db, "own", mine)
    user_id = await make_user(db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user_id, mine)
    headers = await bearer(app_client, "res")
    app_client.cookies.clear()
    assert (await app_client.patch(f"/api/v1/devices/{other}", headers=headers, json={"name": "hijacked"})).status_code == 404
    assert (await app_client.patch(f"/api/v1/devices/{own}", headers=headers, json={"group_id": theirs})).status_code == 404     # cannot push it out of reach
    assert (await app_client.patch(f"/api/v1/devices/{own}", headers=headers, json={"group_id": None})).status_code == 422       # or out of its group
    assert (await app_client.patch(f"/api/v1/devices/{own}", headers=headers, json={"name": "renamed"})).status_code == 200
    assert (await app_client.post(f"/api/v1/devices/{other}/discovery", headers=headers)).status_code == 404
    assert await db.fetchval("select name from devices where id = $1::uuid", other) == "foreign"


async def test_polling_can_only_be_enabled_when_everything_it_needs_is_in_place(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    did = (await app_client.post("/api/v1/devices", headers=headers, json=device())).json()["device"]["id"]
    refused = await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"polling_enabled": True})
    assert refused.status_code == 422
    reasons = refused.json()["detail"]["reasons"]
    assert "choose the model family first" in reasons and "choose an access profile first" in reasons
    pid = await profile(db)
    ok = await app_client.patch(f"/api/v1/devices/{did}", headers=headers,
                                json={"vendor_slug": "bdcom", "family_slug": "bdcom-switch", "access_profile_id": pid, "polling_enabled": True})
    assert ok.status_code == 200 and ok.json()["polling_enabled"] is True
    await db.execute("update vendors set polling_enabled = false, polling_disabled_reason = 'incident' where slug = 'bdcom'")
    await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"polling_enabled": False})
    blocked = await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"polling_enabled": True})
    assert blocked.status_code == 422 and "switched off" in str(blocked.json())


async def test_a_device_still_owned_by_the_legacy_system_cannot_be_switched_on_here(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    did = await make_device(db, "imported")          # migrated devices start as legacy-owned
    await db.execute("update devices set polling_owner = 'legacy' where id = $1::uuid", did)
    pid = await profile(db)
    refused = await app_client.patch(f"/api/v1/devices/{did}", headers=headers,
                                     json={"vendor_slug": "bdcom", "family_slug": "bdcom-switch", "access_profile_id": pid, "polling_enabled": True})
    assert refused.status_code == 422 and "legacy system" in str(refused.json())


async def test_type_changes_and_address_changes_are_checked(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    a = (await app_client.post("/api/v1/devices", headers=headers, json=device(vendor_slug="bdcom", family_slug="bdcom-switch"))).json()["device"]["id"]
    await app_client.post("/api/v1/devices", headers=headers, json=device(name="b", management_ip="10.10.0.12"))
    assert (await app_client.patch(f"/api/v1/devices/{a}", headers=headers, json={"device_type": "olt"})).status_code == 422
    assert (await app_client.patch(f"/api/v1/devices/{a}", headers=headers, json={"management_ip": "10.10.0.12"})).status_code == 422
    assert (await app_client.patch(f"/api/v1/devices/{a}", headers=headers, json={"management_ip": "127.0.0.1"})).status_code == 422
    moved = await app_client.patch(f"/api/v1/devices/{a}", headers=headers, json={"management_ip": "10.10.0.50"})
    assert moved.status_code == 200 and moved.json()["management_ip"] == "10.10.0.50"     # a plain address, no prefix length
    assert (await app_client.patch("/api/v1/devices/nope", headers=headers, json={"name": "x"})).status_code == 404


async def test_changes_are_audited_with_only_what_changed(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    did = (await app_client.post("/api/v1/devices", headers=headers, json=device())).json()["device"]["id"]
    await app_client.patch(f"/api/v1/devices/{did}", headers=headers, json={"name": "renamed"})
    row = await db.fetchrow("select before, after from audit_logs where action = 'device.updated'")
    before, after = json.loads(row["before"]), json.loads(row["after"])
    assert set(before) == set(after) == {"name", "updated_at"}          # only what changed is recorded
    assert (before["name"], after["name"]) == ("core-sw-1", "renamed")
    assert await db.fetchval("select count(*) from audit_logs where action = 'device.created'") == 1


async def test_deleting_needs_the_typed_name_and_the_dangerous_permission(app_client, db):
    admin = await login_as(app_client, db, "boss", "ISP Admin")
    await make_user(db, "noc", "ISP NOC")
    noc = await bearer(app_client, "noc")
    app_client.cookies.clear()
    did = (await app_client.post("/api/v1/devices", headers=admin, json=device())).json()["device"]["id"]
    await make_interface(db, did, "Gi0/1")
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=noc, params={"confirm": "core-sw-1"})).status_code == 403
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=admin)).status_code == 422                       # confirm is required
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=admin, params={"confirm": "wrong"})).status_code == 422
    assert await db.fetchval("select count(*) from devices") == 1
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=admin, params={"confirm": "core-sw-1"})).status_code == 200
    assert await db.fetchval("select count(*) from devices") == 0 and await db.fetchval("select count(*) from interfaces") == 0
    assert await db.fetchval("select count(*) from discovery_jobs") == 0
    audit = await db.fetchrow("select before::text b from audit_logs where action = 'device.deleted'")
    assert "core-sw-1" in audit["b"]
    assert (await app_client.delete(f"/api/v1/devices/{did}", headers=admin, params={"confirm": "core-sw-1"})).status_code == 404


async def test_discovery_history_is_scoped_and_repeat_requests_are_limited(app_client, db):
    headers = await login_as(app_client, db, "boss", "ISP Admin")
    pid = await profile(db)
    did = (await app_client.post("/api/v1/devices", headers=headers, json=device(access_profile_id=pid))).json()["device"]["id"]
    history = (await app_client.get(f"/api/v1/devices/{did}/discovery", headers=headers)).json()
    assert len(history) == 1 and history[0]["oids"] == SAFE
    again = await app_client.post(f"/api/v1/devices/{did}/discovery", headers=headers)
    assert again.status_code == 429 and again.headers["retry-after"] == "600"
    await db.execute("update discovery_jobs set status = 'succeeded', requested_at = now() - interval '1 hour'")
    fresh = await app_client.post(f"/api/v1/devices/{did}/discovery", headers=headers)
    assert fresh.status_code == 202 and fresh.json()["status"] == "queued"
    assert len(await stream(app_client)) == 2
    await make_user(db, "res", "Reseller Viewer")
    res = await bearer(app_client, "res")
    app_client.cookies.clear()
    assert (await app_client.get(f"/api/v1/devices/{did}/discovery", headers=res)).status_code == 404


async def test_who_may_add_and_change_devices(app_client, db):
    for name, role in (("noc", "ISP NOC"), ("support", "ISP Support"), ("reseller", "Reseller Admin")):
        await make_user(db, name, role)
    tokens = {n: await bearer(app_client, n) for n in ("noc", "support", "reseller")}
    app_client.cookies.clear()
    for n in tokens:
        assert (await app_client.post("/api/v1/devices", headers=tokens[n], json=device())).status_code == 403, n
    assert (await app_client.post("/api/v1/device-groups", headers=tokens["noc"], json={"name": "g"})).status_code == 403
