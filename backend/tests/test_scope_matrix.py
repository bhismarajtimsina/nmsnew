"""Every list and detail endpoint, called as every kind of user, against one shared data set.

Layout:   group North
            +- group North-East  (child)
          group South
          devices:  sw-north (North), sw-ne (North-East), sw-south (South), sw-loose (no group)
          each device has one interface.
"""
import uuid

import httpx
import pytest

from tests.helpers import bearer, make_device, make_group, make_interface, make_user


@pytest.fixture
async def world(app_client, db):
    north = await make_group(db, "North")
    north_east = await make_group(db, "North-East", parent=north)
    south = await make_group(db, "South")
    dev = {
        "north": await make_device(db, "sw-north", north),
        "ne": await make_device(db, "sw-ne", north_east),
        "south": await make_device(db, "sw-south", south),
        "loose": await make_device(db, "sw-loose", None),
    }
    iface = {key: await make_interface(db, dev_id, f"Gi-{key}") for key, dev_id in dev.items()}
    users = {
        "super": await make_user(db, "super", "Super Admin"),
        "isp": await make_user(db, "isp", "ISP Admin"),
        "noc": await make_user(db, "noc", "ISP NOC"),
        "support": await make_user(db, "support", "ISP Support"),
        "res_group": await make_user(db, "res_group", "Reseller Admin"),
        "res_device": await make_user(db, "res_device", "Reseller Operator"),
        "res_iface": await make_user(db, "res_iface", "Reseller Viewer"),
        "res_none": await make_user(db, "res_none", "Reseller Operator"),
    }
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", users["res_group"], north)
    await db.execute("insert into user_device_scopes (user_id, device_id) values ($1::uuid, $2::uuid)", users["res_device"], dev["south"])
    await db.execute("insert into user_interface_scopes (user_id, interface_id) values ($1::uuid, $2::uuid)", users["res_iface"], iface["south"])
    headers = {name: await bearer(app_client, name) for name in users}
    app_client.cookies.clear()
    return {"dev": dev, "iface": iface, "headers": headers, "groups": {"north": north, "south": south}}


async def names(client, path, headers, **params):
    response = await client.get(path, headers=headers, params=params)
    assert response.status_code == 200, response.text
    return sorted(item["name"] for item in response.json()["items"])


EVERYTHING_DEVICES = ["sw-loose", "sw-ne", "sw-north", "sw-south"]
EVERYTHING_INTERFACES = ["Gi-loose", "Gi-ne", "Gi-north", "Gi-south"]


@pytest.mark.parametrize("who", ["super", "isp", "noc", "support"])
async def test_isp_roles_see_everything(app_client, world, who):
    assert await names(app_client, "/api/v1/devices", world["headers"][who]) == EVERYTHING_DEVICES
    assert await names(app_client, "/api/v1/interfaces", world["headers"][who]) == EVERYTHING_INTERFACES


async def test_a_group_assignment_includes_child_groups_and_nothing_else(app_client, world):
    headers = world["headers"]["res_group"]
    assert await names(app_client, "/api/v1/devices", headers) == ["sw-ne", "sw-north"]
    assert await names(app_client, "/api/v1/interfaces", headers) == ["Gi-ne", "Gi-north"]
    for hidden in ("south", "loose"):
        assert (await app_client.get(f"/api/v1/devices/{world['dev'][hidden]}", headers=headers)).status_code == 404
        assert (await app_client.get(f"/api/v1/interfaces/{world['iface'][hidden]}", headers=headers)).status_code == 404
        assert (await app_client.get(f"/api/v1/devices/{world['dev'][hidden]}/interfaces", headers=headers)).json()["items"] == []


async def test_a_direct_device_assignment_grants_that_device_and_its_interfaces_only(app_client, world):
    headers = world["headers"]["res_device"]
    assert await names(app_client, "/api/v1/devices", headers) == ["sw-south"]
    assert await names(app_client, "/api/v1/interfaces", headers) == ["Gi-south"]
    assert (await app_client.get(f"/api/v1/devices/{world['dev']['south']}", headers=headers)).status_code == 200
    assert (await app_client.get(f"/api/v1/devices/{world['dev']['north']}", headers=headers)).status_code == 404


async def test_an_interface_assignment_grants_the_interface_but_not_its_device(app_client, world):
    headers = world["headers"]["res_iface"]
    assert await names(app_client, "/api/v1/devices", headers) == []
    assert await names(app_client, "/api/v1/interfaces", headers) == ["Gi-south"]
    assert (await app_client.get(f"/api/v1/interfaces/{world['iface']['south']}", headers=headers)).status_code == 200
    assert (await app_client.get(f"/api/v1/devices/{world['dev']['south']}", headers=headers)).status_code == 404
    assert (await app_client.get(f"/api/v1/interfaces/{world['iface']['north']}", headers=headers)).status_code == 404


async def test_a_restricted_user_with_no_assignment_sees_nothing_anywhere(app_client, world):
    headers = world["headers"]["res_none"]
    assert await names(app_client, "/api/v1/devices", headers) == []
    assert await names(app_client, "/api/v1/interfaces", headers) == []
    for key in world["dev"]:
        assert (await app_client.get(f"/api/v1/devices/{world['dev'][key]}", headers=headers)).status_code == 404


async def test_a_foreign_object_and_a_missing_one_are_indistinguishable(app_client, world):
    headers = world["headers"]["res_group"]
    foreign = await app_client.get(f"/api/v1/devices/{world['dev']['south']}", headers=headers)
    missing = await app_client.get(f"/api/v1/devices/{uuid.uuid4()}", headers=headers)
    malformed = await app_client.get("/api/v1/devices/not-a-uuid", headers=headers)
    assert (foreign.status_code, foreign.text) == (missing.status_code, missing.text) == (malformed.status_code, malformed.text)


async def test_filters_cannot_be_used_to_reach_hidden_rows(app_client, world):
    headers = world["headers"]["res_group"]
    assert await names(app_client, "/api/v1/devices", headers, search="sw-south") == []
    assert await names(app_client, "/api/v1/devices", headers, search="sw-") == ["sw-ne", "sw-north"]
    assert await names(app_client, "/api/v1/devices", headers, device_type="switch") == ["sw-ne", "sw-north"]
    assert await names(app_client, "/api/v1/interfaces", headers, device_id=world["dev"]["south"]) == []
    listing = (await app_client.get("/api/v1/devices", headers=headers, params={"limit": 1, "offset": 1})).json()
    assert listing["total"] == 2 and len(listing["items"]) == 1  # the total counts only what the caller may see


async def test_removing_an_assignment_takes_effect_immediately(app_client, world, db):
    headers = world["headers"]["res_group"]
    assert await names(app_client, "/api/v1/devices", headers) == ["sw-ne", "sw-north"]
    await db.execute("delete from user_device_group_scopes")
    assert await names(app_client, "/api/v1/devices", headers) == []


async def test_the_role_scope_mode_is_what_widens_access(app_client, world, db):
    headers = world["headers"]["res_none"]
    assert await names(app_client, "/api/v1/devices", headers) == []
    await db.execute("update roles set scope_mode = 'all' where name = 'Reseller Operator'")
    assert await names(app_client, "/api/v1/devices", headers) == EVERYTHING_DEVICES


async def test_unauthenticated_callers_get_nothing(app_client, world):
    for path in ("/api/v1/devices", "/api/v1/interfaces", f"/api/v1/devices/{world['dev']['north']}",
                 f"/api/v1/interfaces/{world['iface']['north']}", f"/api/v1/devices/{world['dev']['north']}/interfaces"):
        assert (await app_client.get(path)).status_code == 401, path


async def test_a_role_without_the_view_permission_is_refused_even_inside_its_scope(app_client, world, db):
    await db.execute(
        "delete from role_permissions where role_id = (select id from roles where name = 'Reseller Admin') "
        "and permission_id = (select id from permissions where code = 'interfaces.view')")
    headers = world["headers"]["res_group"]
    assert (await app_client.get("/api/v1/interfaces", headers=headers)).status_code == 403
    assert (await app_client.get("/api/v1/devices", headers=headers)).status_code == 200


async def test_device_responses_never_carry_credentials(app_client, world, db):
    profile = await db.fetchval("insert into device_access_profiles (name, snmp_version, snmp_community_enc) values ('p', 'v2c', 'v1:k:SECRETVALUE') returning id")
    await db.execute("update devices set access_profile_id = $1", profile)
    body = (await app_client.get("/api/v1/devices", headers=world["headers"]["isp"])).text
    assert "SECRETVALUE" not in body and "community" not in body and "access_profile" not in body


def test_an_unknown_scope_mode_is_treated_as_restricted():
    from app.core.security import CurrentUser

    kwargs = dict(id="1", username="u", display_name="u", email=None, role="r", role_id="1")
    assert CurrentUser(scope_mode="all", **kwargs).scope_all is True
    for mode in ("assigned", "", "ALL", "everything", "none"):
        assert CurrentUser(scope_mode=mode, **kwargs).scope_all is False
