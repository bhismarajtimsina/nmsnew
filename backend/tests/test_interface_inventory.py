"""Interface status history (the write path no live poller calls yet) and interface marks/tags, ported from
the real device_interfaces_tags design (src/Storage/Devices/DeviceInterfaceTagStorage.php): marks and tags are
global to the interface, not per user."""
import pytest

from app.repositories import interfaces as interface_repo
from app.services.interface_status import record_interface_status
from tests.helpers import bearer, make_device, make_group, make_interface, make_user


async def test_recording_a_status_change_writes_exactly_one_history_row(db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    changed = await record_interface_status(db, iface, "up", "down")
    assert changed is True
    rows = await db.fetch("select admin_status, oper_status from interface_status_history where interface_id = $1::uuid", iface)
    assert len(rows) == 1 and rows[0]["admin_status"] == "up" and rows[0]["oper_status"] == "down"
    assert await db.fetchval("select admin_status || ',' || oper_status from interfaces where id = $1::uuid", iface) == "up,down"


async def test_recording_the_same_status_again_writes_no_history_row(db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await record_interface_status(db, iface, "up", "up")
    changed = await record_interface_status(db, iface, "up", "up")
    assert changed is False
    count = await db.fetchval("select count(*) from interface_status_history where interface_id = $1::uuid", iface)
    assert count == 1  # from unknown/unknown -> up/up: one real change, then a no-op


async def test_a_change_to_only_one_field_still_writes_exactly_one_row(db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await record_interface_status(db, iface, "up", "up")
    changed = await record_interface_status(db, iface, "up", "down")
    assert changed is True
    count = await db.fetchval("select count(*) from interface_status_history where interface_id = $1::uuid", iface)
    assert count == 2


async def test_recording_status_for_an_unknown_interface_raises(db):
    with pytest.raises(LookupError):
        await record_interface_status(db, "00000000-0000-0000-0000-000000000000", "up", "up")


async def test_marks_default_to_not_favorite_and_no_tags(app_client, db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "admin", "ISP Admin")
    headers = await bearer(app_client, "admin")
    marks = (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=headers)).json()
    assert marks == {"favorite": False, "tags": []}


async def test_setting_favorite_and_reading_it_back(app_client, db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "admin", "ISP Admin")
    headers = await bearer(app_client, "admin")
    resp = await app_client.put(f"/api/v1/interfaces/{iface}/favorite", headers=headers, json={"favorite": True})
    assert resp.status_code == 200 and resp.json() == {"favorite": True}
    marks = (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=headers)).json()
    assert marks["favorite"] is True
    await app_client.put(f"/api/v1/interfaces/{iface}/favorite", headers=headers, json={"favorite": False})
    marks = (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=headers)).json()
    assert marks["favorite"] is False
    audit = await db.fetchval("select count(*) from audit_logs where action = 'interface.favorite_set'")
    assert audit == 2


async def test_setting_tags_sanitizes_deduplicates_and_replaces_the_previous_set(app_client, db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "admin", "ISP Admin")
    headers = await bearer(app_client, "admin")
    resp = await app_client.put(
        f"/api/v1/interfaces/{iface}/tags", headers=headers,
        json={"tags": ["uplink", "up link", "up-link", 'up"link', "up,link", "up#link", "up;link", "", "  "]},
    )
    assert resp.status_code == 200
    assert resp.json()["tags"] == ["uplink"]  # every variant sanitizes to the same tag, then dedupes

    resp = await app_client.put(f"/api/v1/interfaces/{iface}/tags", headers=headers, json={"tags": ["core"]})
    assert resp.json()["tags"] == ["core"]  # replaces, does not merge with the previous set
    marks = (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=headers)).json()
    assert marks["tags"] == ["core"]


async def test_marking_an_interface_needs_the_mark_permission_not_just_view(app_client, db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "support", "ISP Support")  # holds interfaces.view, not interfaces.mark
    headers = await bearer(app_client, "support")
    assert (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=headers)).status_code == 200
    assert (await app_client.put(f"/api/v1/interfaces/{iface}/favorite", headers=headers, json={"favorite": True})).status_code == 403
    assert (await app_client.put(f"/api/v1/interfaces/{iface}/tags", headers=headers, json={"tags": ["x"]})).status_code == 403


async def test_marks_are_global_not_per_user(app_client, db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "alice", "ISP Admin")
    await make_user(db, "bob", "ISP Admin")
    alice = await bearer(app_client, "alice")
    app_client.cookies.clear()
    bob = await bearer(app_client, "bob")
    app_client.cookies.clear()
    await app_client.put(f"/api/v1/interfaces/{iface}/favorite", headers=alice, json={"favorite": True})
    marks = (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=bob)).json()
    assert marks["favorite"] is True  # bob sees alice's mark: there is no per-user favorites list


async def test_marking_an_interface_outside_scope_is_not_found(app_client, db):
    device = await make_device(db, "theirs")
    iface = await make_interface(db, device, "eth0")
    uid, headers = str(await make_user(db, "res", "Reseller Admin")), await bearer(app_client, "res")
    assert (await app_client.get(f"/api/v1/interfaces/{iface}/marks", headers=headers)).status_code == 404
    assert (await app_client.put(f"/api/v1/interfaces/{iface}/favorite", headers=headers, json={"favorite": True})).status_code == 404


async def test_listing_interfaces_filters_by_favorite_and_tag(app_client, db):
    device = await make_device(db, "sw1")
    starred = await make_interface(db, device, "eth0", if_index=1)
    tagged = await make_interface(db, device, "eth1", if_index=2)
    plain = await make_interface(db, device, "eth2", if_index=3)
    await make_user(db, "admin", "ISP Admin")
    headers = await bearer(app_client, "admin")
    await app_client.put(f"/api/v1/interfaces/{starred}/favorite", headers=headers, json={"favorite": True})
    await app_client.put(f"/api/v1/interfaces/{tagged}/tags", headers=headers, json={"tags": ["uplink"]})

    favorites = (await app_client.get("/api/v1/interfaces", headers=headers, params={"favorite": "true"})).json()
    assert {i["id"] for i in favorites["items"]} == {starred}

    by_tag = (await app_client.get("/api/v1/interfaces", headers=headers, params={"tag": "uplink"})).json()
    assert {i["id"] for i in by_tag["items"]} == {tagged}

    everyone = (await app_client.get("/api/v1/interfaces", headers=headers)).json()
    assert {i["id"] for i in everyone["items"]} == {starred, tagged, plain}


async def test_known_tags_are_scoped_and_support_prefix_search(app_client, db):
    mine_group = await make_group(db, "mine")
    mine_device = await make_device(db, "mine-sw", mine_group)
    other_device = await make_device(db, "other-sw")
    mine_iface = await make_interface(db, mine_device, "eth0")
    other_iface = await make_interface(db, other_device, "eth0")
    uid = await make_user(db, "admin", "ISP Admin")
    admin = await bearer(app_client, "admin")
    app_client.cookies.clear()
    await app_client.put(f"/api/v1/interfaces/{mine_iface}/tags", headers=admin, json={"tags": ["uplink-core"]})
    await app_client.put(f"/api/v1/interfaces/{other_iface}/tags", headers=admin, json={"tags": ["backup"]})

    res_uid = await make_user(db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", res_uid, mine_group)
    reseller = await bearer(app_client, "res")

    seen_by_admin = set((await app_client.get("/api/v1/interfaces/tags", headers=admin)).json())
    assert seen_by_admin == {"uplinkcore", "backup"}  # setTags already sanitized the hyphen out

    seen_by_reseller = (await app_client.get("/api/v1/interfaces/tags", headers=reseller)).json()
    assert seen_by_reseller == ["uplinkcore"]  # backup lives on a device outside this reseller's scope

    prefix = (await app_client.get("/api/v1/interfaces/tags", headers=admin, params={"q": "up"})).json()
    assert prefix == ["uplinkcore"]


async def test_status_history_lists_recent_changes_scoped_and_paginated(app_client, db):
    device = await make_device(db, "sw1")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "admin", "ISP Admin")
    headers = await bearer(app_client, "admin")
    for oper in ("down", "up", "down"):
        await record_interface_status(db, iface, "up", oper)
    history = (await app_client.get(f"/api/v1/interfaces/{iface}/history", headers=headers)).json()
    assert history["total"] == 3
    assert [row["oper_status"] for row in history["items"]] == ["down", "up", "down"]  # newest first


async def test_status_history_for_an_invisible_interface_is_not_found(app_client, db):
    device = await make_device(db, "theirs")
    iface = await make_interface(db, device, "eth0")
    await make_user(db, "res", "Reseller Admin")
    headers = await bearer(app_client, "res")
    assert (await app_client.get(f"/api/v1/interfaces/{iface}/history", headers=headers)).status_code == 404
