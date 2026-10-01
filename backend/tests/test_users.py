from tests.helpers import DEFAULT_PASSWORD, bearer, login, make_device, make_group, make_interface, make_user


async def grant(db, role, code):
    await db.execute(
        "insert into role_permissions select r.id, p.id from roles r, permissions p where r.name = $1 and p.code = $2 on conflict do nothing",
        role, code,
    )


async def role_id(db, name):
    return str(await db.fetchval("select id from roles where name = $1", name))


async def test_only_holders_of_users_manage_can_create_users(app_client, db):
    await make_user(db, "isp", "ISP Admin")
    headers = await bearer(app_client, "isp")
    app_client.cookies.clear()
    body = {"username": "new", "display_name": "New", "role_id": await role_id(db, "ISP NOC")}
    assert (await app_client.post("/api/v1/users", headers=headers, json=body)).status_code == 403
    assert (await app_client.get("/api/v1/users", headers=headers)).status_code == 403


async def test_a_user_is_created_with_a_generated_password_that_must_be_changed(app_client, db):
    await make_user(db, "root", "Super Admin")
    headers = await bearer(app_client, "root")
    app_client.cookies.clear()
    body = {"username": "noc1", "display_name": "NOC One", "role_id": await role_id(db, "ISP NOC")}
    created = await app_client.post("/api/v1/users", headers=headers, json=body)
    assert created.status_code == 201 and created.json()["generated_password"]
    first = await login(app_client, "noc1", created.json()["generated_password"])
    assert first.status_code == 200 and first.json()["must_change_password"] is True
    assert (await app_client.post("/api/v1/users", headers=headers, json=body)).status_code == 409  # duplicate name
    weak = await app_client.post("/api/v1/users", headers=headers, json={**body, "username": "noc2", "password": "weak"})
    assert weak.status_code == 422 and "TOO_SHORT" in weak.text
    bad_name = await app_client.post("/api/v1/users", headers=headers, json={**body, "username": "has space"})
    assert bad_name.status_code == 422
    assert "password" not in str((await app_client.get("/api/v1/users", headers=headers)).json())


async def test_nobody_can_hand_out_a_role_more_powerful_than_their_own(app_client, db):
    await make_user(db, "delegate", "ISP Admin")
    await grant(db, "ISP Admin", "users.manage")  # a delegated user administrator, not a Super Admin
    headers = await bearer(app_client, "delegate")
    app_client.cookies.clear()
    attempt = lambda role: app_client.post(  # noqa: E731
        "/api/v1/users", headers=headers, json={"username": f"u-{role.replace(' ', '')}", "display_name": "X", "role_id": ""})
    for role, expected in (("Super Admin", 403), ("ISP NOC", 201), ("Reseller Viewer", 201)):
        body = {"username": "u" + role.replace(" ", "").lower(), "display_name": "X", "role_id": await role_id(db, role)}
        assert (await app_client.post("/api/v1/users", headers=headers, json=body)).status_code == expected, role
    target = await make_user(db, "victim", "Reseller Viewer")
    promote = await app_client.patch(f"/api/v1/users/{target}", headers=headers, json={"role_id": await role_id(db, "Super Admin")})
    assert promote.status_code == 403
    assert await db.fetchval("select r.name from users u join roles r on r.id = u.role_id where u.id = $1::uuid", target) == "Reseller Viewer"


async def test_the_last_super_admin_cannot_be_removed_and_nobody_disables_themselves(app_client, db):
    root = await make_user(db, "root", "Super Admin")
    headers = await bearer(app_client, "root")
    app_client.cookies.clear()
    demote = await app_client.patch(f"/api/v1/users/{root}", headers=headers, json={"role_id": await role_id(db, "ISP Admin")})
    assert demote.status_code == 409 and "last active Super Admin" in demote.json()["detail"]
    assert (await app_client.patch(f"/api/v1/users/{root}", headers=headers, json={"is_active": False})).status_code == 409
    second = await make_user(db, "root2", "Super Admin")
    assert (await app_client.patch(f"/api/v1/users/{second}", headers=headers, json={"is_active": False})).status_code == 200
    assert (await app_client.patch(f"/api/v1/users/{root}", headers=headers, json={"is_active": False})).status_code == 409  # still the last


async def test_disabling_a_user_revokes_their_sessions_and_tokens(app_client, db):
    await make_user(db, "root", "Super Admin")
    victim = await make_user(db, "victim", "ISP Admin")
    admin = await bearer(app_client, "root")
    theirs = await bearer(app_client, "victim")
    app_client.cookies.clear()
    made = await app_client.post("/api/v1/api-tokens", headers=theirs, json={"name": "t", "permissions": ["devices.view"]})
    assert (await app_client.patch(f"/api/v1/users/{victim}", headers=admin, json={"is_active": False})).status_code == 200
    assert (await app_client.get("/api/v1/auth/session", headers=theirs)).status_code == 401
    assert (await app_client.get("/api/v1/devices", headers={"Authorization": f"Bearer {made.json()['token']}"})).status_code == 401
    assert await db.fetchval("select count(*) from api_tokens where revoked_at is null and user_id = $1::uuid", victim) == 0
    assert (await login(app_client, "victim")).status_code == 401


async def test_an_admin_password_reset_revokes_sessions_and_forces_a_change(app_client, db):
    await make_user(db, "root", "Super Admin")
    victim = await make_user(db, "victim", "ISP Admin")
    admin = await bearer(app_client, "root")
    theirs = await bearer(app_client, "victim")
    app_client.cookies.clear()
    reset = await app_client.post(f"/api/v1/users/{victim}/password", headers=admin, json={})
    new_password = reset.json()["generated_password"]
    assert reset.status_code == 200 and new_password
    assert (await app_client.get("/api/v1/auth/session", headers=theirs)).status_code == 401
    assert (await login(app_client, "victim")).status_code == 401
    assert (await login(app_client, "victim", new_password)).json()["must_change_password"] is True
    assert await db.fetchval("select count(*) from audit_logs where action = 'user.password_reset'") == 1


async def test_an_admin_can_reset_a_lost_second_factor(app_client, db):
    await make_user(db, "root", "Super Admin")
    victim = await make_user(db, "victim", "ISP Admin")
    await db.execute("update users set totp_enabled = true, totp_secret_enc = 'v1:x:y' where id = $1::uuid", victim)
    admin = await bearer(app_client, "root")
    assert (await app_client.post(f"/api/v1/users/{victim}/2fa/reset", headers=admin)).status_code == 200
    assert (await login(app_client, "victim")).json()["token"]


async def test_strict_ip_settings_are_validated(app_client, db):
    await make_user(db, "root", "Super Admin")
    victim = await make_user(db, "victim", "ISP Admin")
    admin = await bearer(app_client, "root")
    app_client.cookies.clear()
    url = f"/api/v1/users/{victim}"
    assert (await app_client.patch(url, headers=admin, json={"strict_ip_enabled": True})).status_code == 422  # no address to allow
    assert (await app_client.patch(url, headers=admin, json={"allowed_ips": ["not-an-ip"]})).status_code == 422
    ok = await app_client.patch(url, headers=admin, json={"allowed_ips": ["203.0.113.0/24"], "strict_ip_enabled": True})
    assert ok.status_code == 200 and ok.json()["allowed_ips"] == ["203.0.113.0/24"] and ok.json()["strict_ip_enabled"] is True


async def test_scope_assignment_is_validated_replaced_and_audited(app_client, db):
    await make_user(db, "root", "Super Admin")
    reseller = await make_user(db, "res", "Reseller Operator")
    group = await make_group(db, "North")
    device = await make_device(db, "sw1", group)
    iface = await make_interface(db, device, "Gi0/1")
    admin = await bearer(app_client, "root")
    app_client.cookies.clear()
    url = f"/api/v1/access/users/{reseller}/scopes"
    unknown = await app_client.put(url, headers=admin, json={"devices": [{"id": "00000000-0000-0000-0000-000000000000"}]})
    assert unknown.status_code == 422
    malformed = await app_client.put(url, headers=admin, json={"devices": [{"id": "nope"}]})
    assert malformed.status_code == 422
    badlevel = await app_client.put(url, headers=admin, json={"devices": [{"id": device, "level": "root"}]})
    assert badlevel.status_code == 422
    put = await app_client.put(url, headers=admin, json={"device_groups": [{"id": group, "level": "operator"}], "interfaces": [{"id": iface}]})
    assert put.status_code == 200
    got = (await app_client.get(url, headers=admin)).json()
    assert got["device_groups"] == [{"id": group, "level": "operator"}] and got["interfaces"] == [{"id": iface, "level": "viewer"}]
    await app_client.put(url, headers=admin, json={})  # replacing with nothing removes everything
    assert (await app_client.get(url, headers=admin)).json() == {"device_groups": [], "devices": [], "interfaces": []}
    assert await db.fetchval("select count(*) from audit_logs where action = 'scope.assigned'") == 2  # rejected requests are not audited as assignments


async def test_resellers_can_be_created_and_users_assigned_to_them(app_client, db):
    await make_user(db, "root", "Super Admin")
    member = await make_user(db, "res", "Reseller Operator")
    admin = await bearer(app_client, "root")
    app_client.cookies.clear()
    made = await app_client.post("/api/v1/resellers", headers=admin, json={"name": "Himalaya ISP"})
    assert made.status_code == 201
    assert (await app_client.post("/api/v1/resellers", headers=admin, json={"name": "Himalaya ISP"})).status_code == 409
    assert (await app_client.put(f"/api/v1/resellers/{made.json()['id']}/users", headers=admin, json={"user_ids": [member]})).json() == {"users": 1}
    assert (await app_client.get(f"/api/v1/users/{member}", headers=admin)).json()["reseller"] == "Himalaya ISP"
    assert (await app_client.get("/api/v1/resellers", headers=admin)).json()[0]["users"] == 1


async def test_creating_and_resetting_without_a_password_never_fails_on_the_generated_one(app_client, db, monkeypatch):
    """Forces the weak first draw that used to make the route answer 422."""
    from app.core import passwords

    await make_user(db, "root2", "Super Admin")
    headers = await bearer(app_client, "root2")
    app_client.cookies.clear()
    weak_then_strong = iter(("a" * 24 + "Ab1" + "c" * 21) * 2)
    monkeypatch.setattr(passwords.secrets, "choice", lambda alphabet: next(weak_then_strong))
    created = await app_client.post("/api/v1/users", headers=headers, json={"username": "gen1", "display_name": "Gen", "role_id": await role_id(db, "ISP NOC")})
    assert created.status_code == 201 and created.json()["generated_password"] == "Ab1" + "c" * 21
    reset = await app_client.post(f"/api/v1/users/{created.json()['id']}/password", headers=headers, json={})
    assert reset.status_code == 200 and reset.json()["generated_password"] == "Ab1" + "c" * 21
