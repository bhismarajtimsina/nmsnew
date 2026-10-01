"""GET /api/v1/devices/overview (Plan 25): the device list page's one call, replacing legacy /dev-dashboard/devices."""
from tests.helpers import bearer, make_device, make_group, make_interface, make_user


async def login_as(app_client, db, name, role):
    await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


async def ping(db, device, status, latency=None):
    await db.execute(
        "insert into device_ping_status (device_id, status, latency_ms, last_checked_at) values ($1::uuid, $2, $3, now())",
        device, status, latency,
    )


async def set_oper(db, interface, status):
    await db.execute("update interfaces set oper_status = $2 where id = $1::uuid", interface, status)


async def overview(app_client, headers, **params):
    response = await app_client.get("/api/v1/devices/overview", headers=headers, params=params)
    assert response.status_code == 200, response.text
    return response.json()


async def test_each_device_carries_its_group_model_ping_and_interface_counts(app_client, db):
    core = await make_group(db, "Core")
    sw = await make_device(db, "sw-core", core, ip="10.0.0.2")
    model = await db.fetchval("select id from device_models where legacy_key = 'bdcom_s5612'")
    await db.execute("update devices set model_id = $2 where id = $1::uuid", sw, model)
    await ping(db, sw, "up", 1.5)
    for n, status in enumerate(["up", "up", "down", "lowerLayerDown", "unknown"], 1):
        await set_oper(db, await make_interface(db, sw, f"Gi0/{n}", n), status)
    headers = await login_as(app_client, db, "noc", "ISP NOC")

    item = (await overview(app_client, headers))["items"][0]
    assert item["name"] == "sw-core" and item["management_ip"] == "10.0.0.2" and item["polling_enabled"] is True
    assert item["group"] == {"id": core, "name": "Core"}
    assert item["model"]["id"] == str(model) and item["model"]["name"] == "BDCOM S5612" and item["model"]["vendor"] == "bdcom"
    assert item["ping"]["status"] == "up" and item["ping"]["latency_ms"] == 1.5 and item["ping"]["last_checked_at"]
    # Only `up` counts as up; every other status counts as down, as the legacy count does.
    assert item["interfaces"] == {"up": 2, "down": 3}


async def test_a_device_with_nothing_attached_has_nulls_and_zero_counts(app_client, db):
    await make_device(db, "sw-bare", None)
    headers = await login_as(app_client, db, "noc", "ISP NOC")
    item = (await overview(app_client, headers))["items"][0]
    assert item["group"] is None and item["model"] is None and item["ping"] is None
    assert item["interfaces"] == {"up": 0, "down": 0}


async def test_devices_last_seen_down_come_first_then_the_chosen_order(app_client, db):
    a = await make_device(db, "a-up", None, ip="10.0.0.30")
    await make_device(db, "b-never-pinged", None, ip="10.0.0.20")
    c = await make_device(db, "c-down", None, ip="10.0.0.10")
    d = await make_device(db, "d-down", None, ip="10.0.0.9")
    e = await make_device(db, "e-unknown", None, ip="10.0.0.1")
    await ping(db, a, "up", 2.0)
    await ping(db, c, "down")
    await ping(db, d, "down")
    await ping(db, e, "unknown")
    headers = await login_as(app_client, db, "noc", "ISP NOC")

    items = (await overview(app_client, headers))["items"]
    assert [i["name"] for i in items] == ["c-down", "d-down", "a-up", "b-never-pinged", "e-unknown"]
    # A failed ping has no latency but is still a ping result, not "never pinged".
    assert items[0]["ping"]["status"] == "down" and items[0]["ping"]["latency_ms"] is None
    assert items[3]["ping"] is None
    by_ip = [i["name"] for i in (await overview(app_client, headers, sort="ip"))["items"]]
    assert by_ip == ["d-down", "c-down", "e-unknown", "b-never-pinged", "a-up"]


async def test_search_matches_name_or_address_prefix_and_pages(app_client, db):
    for n in range(5):
        await make_device(db, f"olt-{n}", None, ip=f"10.7.0.{n + 1}")
    await make_device(db, "sw-other", None, ip="192.168.1.1")
    headers = await login_as(app_client, db, "noc", "ISP NOC")

    assert (await overview(app_client, headers, search="OLT"))["total"] == 5
    assert [i["name"] for i in (await overview(app_client, headers, search="192.168."))["items"]] == ["sw-other"]
    page = await overview(app_client, headers, limit=2, offset=2)
    assert page["total"] == 6 and page["limit"] == 2 and page["offset"] == 2
    assert [i["name"] for i in page["items"]] == ["olt-2", "olt-3"]
    assert (await app_client.get("/api/v1/devices/overview", headers=headers, params={"limit": 1001})).status_code == 422
    assert (await app_client.get("/api/v1/devices/overview", headers=headers, params={"sort": "location"})).status_code == 422


async def test_scope_applies_and_counts_only_visible_devices(app_client, db):
    north = await make_group(db, "North")
    child = await make_group(db, "North-East", parent=north)
    south = await make_group(db, "South")
    await make_device(db, "sw-north", north)
    await make_device(db, "sw-ne", child)
    await make_device(db, "sw-south", south)
    await make_device(db, "sw-loose", None)
    res = await make_user(db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", res, north)
    headers = await bearer(app_client, "res")
    app_client.cookies.clear()

    page = await overview(app_client, headers)
    assert sorted(i["name"] for i in page["items"]) == ["sw-ne", "sw-north"] and page["total"] == 2
    assert (await overview(app_client, headers, search="sw-south"))["items"] == []


async def test_it_needs_devices_view(app_client, db):
    await make_user(db, "nobody", "Reseller Operator")
    await db.execute(
        "delete from role_permissions where role_id = (select role_id from users where username = 'nobody') "
        "and permission_id = (select id from permissions where code = 'devices.view')"
    )
    headers = await bearer(app_client, "nobody")
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/devices/overview", headers=headers)).status_code == 403
    assert (await app_client.get("/api/v1/devices/overview")).status_code == 401
