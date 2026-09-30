from tests.helpers import bearer, make_device, make_user


async def create(client, headers, name="integration", permissions=("devices.view",), **extra):
    return await client.post("/api/v1/api-tokens", headers=headers, json={"name": name, "permissions": list(permissions), **extra})


async def test_a_token_is_shown_once_stored_hashed_and_limited_to_its_permissions(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    created = await create(app_client, headers, permissions=["devices.view"])
    token = created.json()["token"]
    assert created.status_code == 201 and token.startswith("cst_")
    assert token not in await db.fetchval("select token_hash from api_tokens")
    assert "token" not in (await app_client.get("/api/v1/api-tokens", headers=headers)).json()[0]
    use = {"Authorization": f"Bearer {token}"}
    await make_device(db, "sw1")
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 200
    assert (await app_client.get("/api/v1/interfaces", headers=use)).status_code == 403  # the owner may, the token may not
    me = (await app_client.get("/api/v1/auth/session", headers=use)).json()
    assert me["auth_kind"] == "api_token" and me["permissions"] == ["devices.view"]


async def test_a_token_cannot_exceed_what_its_creator_holds(app_client, db):
    await make_user(db, "alice", "ISP Support")  # cannot manage tokens at all
    await make_user(db, "bob", "ISP Admin")
    assert (await create(app_client, await bearer(app_client, "alice"))).status_code == 403
    app_client.cookies.clear()
    headers = await bearer(app_client, "bob")
    app_client.cookies.clear()
    assert (await create(app_client, headers, permissions=["users.manage"])).status_code == 403
    assert (await create(app_client, headers, permissions=["no.such.permission"])).status_code == 422
    assert (await create(app_client, headers, permissions=["api_tokens.manage"])).status_code == 422
    assert (await create(app_client, headers, permissions=[])).status_code == 422


async def test_a_token_cannot_create_tokens_or_log_out(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    token = (await create(app_client, headers, permissions=["devices.view", "api_tokens.manage"] if False else ["devices.view"])).json()["token"]
    use = {"Authorization": f"Bearer {token}"}
    assert (await create(app_client, use, name="chain")).status_code == 403
    assert (await app_client.post("/api/v1/auth/logout", headers=use)).status_code == 400


async def test_a_token_follows_its_owner_when_the_owner_loses_permissions(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    use = {"Authorization": "Bearer " + (await create(app_client, headers)).json()["token"]}
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 200
    await db.execute(
        "delete from role_permissions where role_id = (select id from roles where name = 'ISP Admin') "
        "and permission_id = (select id from permissions where code = 'devices.view')"
    )
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 403
    await db.execute(
        "insert into role_permissions select r.id, p.id from roles r, permissions p where r.name = 'ISP Admin' and p.code = 'devices.view'"
    )


async def test_revoked_expired_and_orphaned_tokens_stop_working(app_client, db):
    uid = await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    made = (await create(app_client, headers)).json()
    use = {"Authorization": f"Bearer {made['token']}"}
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 200
    assert (await app_client.delete(f"/api/v1/api-tokens/{made['id']}", headers=headers)).status_code == 200
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 401

    made = (await create(app_client, headers, name="short", expires_in_days=1)).json()
    use = {"Authorization": f"Bearer {made['token']}"}
    await db.execute("update api_tokens set expires_at = now() - interval '1 second' where name = 'short'")
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 401

    made = (await create(app_client, headers, name="orphan")).json()
    use = {"Authorization": f"Bearer {made['token']}"}
    await db.execute("update users set is_active = false where id = $1::uuid", uid)
    assert (await app_client.get("/api/v1/devices", headers=use)).status_code == 401


async def test_tokens_belong_to_their_owner_unless_an_admin_looks(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    await make_user(db, "bob", "ISP Admin")
    alice = await bearer(app_client, "alice")
    bob = await bearer(app_client, "bob")
    app_client.cookies.clear()
    made = (await create(app_client, alice)).json()
    assert (await app_client.get("/api/v1/api-tokens", headers=bob)).json() == []
    assert (await app_client.get("/api/v1/api-tokens", headers=bob, params={"all_users": "true"})).status_code == 403
    assert (await app_client.delete(f"/api/v1/api-tokens/{made['id']}", headers=bob)).status_code == 404
    assert (await create(app_client, alice)).status_code == 409  # one name per owner


async def test_even_a_token_that_somehow_holds_the_manage_permission_cannot_mint_tokens(app_client, db):
    """The API refuses to issue such a token, but a row inserted directly must still not become a foothold."""
    import hashlib

    uid = await make_user(db, "alice", "ISP Admin")
    raw = "cst_" + "x" * 48
    await db.execute(
        "insert into api_tokens (user_id, name, token_hash, permissions) values ($1::uuid, 'planted', $2, array['api_tokens.manage','devices.view'])",
        uid, hashlib.sha256(raw.encode()).hexdigest(),
    )
    denied = await create(app_client, {"Authorization": f"Bearer {raw}"}, name="chain")
    assert denied.status_code == 403 and "cannot create API tokens" in denied.json()["detail"]
