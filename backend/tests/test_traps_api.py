from app.traps.decode import DecodedTrap
from app.traps.ingest import record_trap
from tests.helpers import bearer, make_device, make_group, make_user


async def login_as(app_client, db, name, role):
    uid = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return uid, headers


async def test_trap_history_is_listed_scoped_by_device_visibility(app_client, db):
    group = await make_group(db, "mine")
    mine = await make_device(db, "mine-sw", group, ip="10.61.0.1")
    theirs = await make_device(db, "their-sw", ip="10.61.0.2")
    await record_trap(db, "10.61.0.1", DecodedTrap("v2c", "public", "1.3.6.1.6.3.1.1.5.3", {}))
    await record_trap(db, "10.61.0.2", DecodedTrap("v2c", "public", "1.3.6.1.6.3.1.1.5.4", {}))

    uid, reseller = await login_as(app_client, db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", uid, group)
    listing = (await app_client.get("/api/v1/trap-history", headers=reseller)).json()
    assert listing["total"] == 1 and listing["items"][0]["device_id"] == mine

    _, admin = await login_as(app_client, db, "admin", "ISP Admin")
    everyone = (await app_client.get("/api/v1/trap-history", headers=admin)).json()
    assert everyone["total"] == 2


async def test_trap_history_filters_by_device_and_known_status(app_client, db):
    device = await make_device(db, "sw1", ip="10.62.0.1")
    await record_trap(db, "10.62.0.1", DecodedTrap("v2c", "public", "1.3.6.1.6.3.1.1.5.3", {}))  # known
    await record_trap(db, "10.62.0.1", DecodedTrap("v1", "public", "1.3.6.1.4.1.1.2.3", {}))  # unknown
    _, admin = await login_as(app_client, db, "admin", "ISP Admin")

    known = (await app_client.get("/api/v1/trap-history", headers=admin, params={"known": "true"})).json()
    assert known["total"] == 1 and known["items"][0]["trap_name"] == "LinkDown"

    unknown = (await app_client.get("/api/v1/trap-history", headers=admin, params={"known": "false"})).json()
    assert unknown["total"] == 1 and unknown["items"][0]["trap_name"] is None

    by_device = (await app_client.get("/api/v1/trap-history", headers=admin, params={"device_id": device})).json()
    assert by_device["total"] == 2


async def test_trap_profiles_are_readable_and_include_the_real_seeded_catalogue(app_client, db):
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    rows = (await app_client.get("/api/v1/trap-profiles", headers=noc)).json()
    assert len(rows) == 28
    by_name = {(r["vendor"], r["name"]): r for r in rows}
    assert by_name[("bdcom", "OnuDyingGaspNotification")]["oid"] == "1.3.6.1.4.1.3320.10.3.8.2"
    assert by_name[("bdcom", "OnuDyingGaspNotification")]["modules"] == ["pon_onts_status"]


async def test_trap_endpoints_are_permission_gated(app_client, db):
    await make_user(db, "res", "Reseller Viewer")
    headers = await bearer(app_client, "res")
    # Reseller Viewer holds device_show -> devices.view -> EXPAND traps.view
    assert (await app_client.get("/api/v1/trap-history", headers=headers)).status_code == 200
    assert (await app_client.get("/api/v1/trap-profiles", headers=headers)).status_code == 200


async def test_trap_endpoints_refuse_a_caller_without_traps_view(app_client, db):
    # Every seeded role holds traps.view via EXPAND, so a token scoped away from it is the only way to lack it.
    await make_user(db, "admin", "ISP Admin")
    admin = await bearer(app_client, "admin")
    made = await app_client.post("/api/v1/api-tokens", headers=admin, json={"name": "narrow", "permissions": ["devices.view"]})
    assert made.status_code == 201, made.text
    scoped = {"Authorization": f"Bearer {made.json()['token']}"}
    assert (await app_client.get("/api/v1/trap-history", headers=scoped)).status_code == 403
    assert (await app_client.get("/api/v1/trap-profiles", headers=scoped)).status_code == 403
