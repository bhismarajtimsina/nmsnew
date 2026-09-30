from tests.helpers import bearer, make_device, make_group, make_user


async def login_as(app_client, db, name, role):
    user_id = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user_id, headers


async def grant(db, role, code):
    await db.execute("insert into role_permissions select r.id, p.id from roles r, permissions p where r.name = $1 and p.code = $2 on conflict do nothing", role, code)


async def test_groups_form_a_tree_with_unique_names_per_parent(app_client, db):
    _, headers = await login_as(app_client, db, "boss", "ISP Admin")
    north = (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "North"})).json()
    east = await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "East", "parent_id": north["id"]})
    assert east.status_code == 201 and east.json()["parent_id"] == north["id"]
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "East", "parent_id": north["id"]})).status_code == 409
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "East"})).status_code == 201   # same name, other parent
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "North"})).status_code == 409   # top level clash
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "x", "parent_id": "nope"})).status_code == 404


async def test_a_group_cannot_be_moved_inside_itself_and_cannot_be_deleted_while_used(app_client, db):
    _, headers = await login_as(app_client, db, "boss", "ISP Admin")
    top = await make_group(db, "top")
    child = await make_group(db, "child", top)
    grandchild = await make_group(db, "grandchild", child)
    assert (await app_client.patch(f"/api/v1/device-groups/{top}", headers=headers, json={"parent_id": grandchild})).status_code == 409
    assert (await app_client.patch(f"/api/v1/device-groups/{top}", headers=headers, json={"parent_id": top})).status_code == 409
    assert (await app_client.patch(f"/api/v1/device-groups/{grandchild}", headers=headers, json={"parent_id": top})).status_code == 200
    assert (await app_client.delete(f"/api/v1/device-groups/{top}", headers=headers)).status_code == 409           # has subgroups
    await make_device(db, "sw", grandchild)
    assert (await app_client.delete(f"/api/v1/device-groups/{grandchild}", headers=headers)).status_code == 409    # has a device
    assert (await app_client.delete(f"/api/v1/device-groups/{child}", headers=headers)).status_code == 200
    assert await db.fetchval("select count(*) from audit_logs where action like 'device_group.%'") == 2   # the refused requests are not audited


async def test_a_restricted_user_sees_and_manages_only_their_own_part_of_the_tree(app_client, db):
    await grant(db, "Reseller Admin", "device_groups.manage")
    mine, other = await make_group(db, "mine"), await make_group(db, "other")
    sub = await make_group(db, "mine-sub", mine)
    user_id, headers = await login_as(app_client, db, "res", "Reseller Admin")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user_id, mine)
    names = sorted(g["name"] for g in (await app_client.get("/api/v1/device-groups", headers=headers)).json())
    assert names == ["mine", "mine-sub"]
    assert (await app_client.get(f"/api/v1/device-groups/{other}", headers=headers)).status_code == 404
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "new", "parent_id": sub})).status_code == 201
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "new", "parent_id": other})).status_code == 404
    assert (await app_client.post("/api/v1/device-groups", headers=headers, json={"name": "root"})).status_code == 409      # no top-level groups
    assert (await app_client.patch(f"/api/v1/device-groups/{other}", headers=headers, json={"name": "x"})).status_code == 404
    assert (await app_client.patch(f"/api/v1/device-groups/{sub}", headers=headers, json={"parent_id": other})).status_code == 404
    assert (await app_client.patch(f"/api/v1/device-groups/{sub}", headers=headers, json={"parent_id": None})).status_code == 409
    assert (await app_client.delete(f"/api/v1/device-groups/{other}", headers=headers)).status_code == 404
    assert await db.fetchval("select name from device_groups where id = $1::uuid", other) == "other"


async def test_only_holders_of_the_manage_permission_change_groups(app_client, db):
    _, viewer = await login_as(app_client, db, "res", "Reseller Viewer")
    assert (await app_client.post("/api/v1/device-groups", headers=viewer, json={"name": "g"})).status_code == 403
    assert (await app_client.get("/api/v1/device-groups", headers=viewer)).status_code == 200
    assert (await app_client.get("/api/v1/device-groups/not-a-uuid", headers=viewer)).status_code == 404
