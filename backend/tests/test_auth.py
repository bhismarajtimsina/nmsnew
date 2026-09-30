import hashlib
import time

import bcrypt
import httpx
import pytest
from redis.exceptions import ConnectionError as RedisConnectionError

from app.core import totp
from app.core.config import settings
from app.core.passwords import scheme_of
from tests.helpers import DEFAULT_PASSWORD, bearer, login, make_user


async def test_login_returns_a_bearer_token_and_a_hardened_cookie(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    response = await login(app_client, "alice")
    body = response.json()
    assert response.status_code == 200 and body["token"].startswith("css_") and body["token_type"] == "Bearer"
    assert body["user"]["username"] == "alice" and body["user"]["role"] == "ISP Admin" and body["user"]["scope_mode"] == "all"
    cookie = response.headers["set-cookie"].lower()
    assert "cs_session=" in cookie and "httponly" in cookie and "samesite=lax" in cookie
    stored = await db.fetchval("select token_hash from user_sessions")
    assert stored == hashlib.sha256(body["token"].encode()).hexdigest() and body["token"] not in stored  # only the hash is stored


async def test_the_cookie_is_secure_in_production(app_client, db, monkeypatch):
    await make_user(db, "alice", "ISP Admin")
    monkeypatch.setattr(settings, "environment", "production")
    assert "secure" in (await login(app_client, "alice")).headers["set-cookie"].lower()


async def test_failures_are_indistinguishable_between_unknown_wrong_and_disabled(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    await make_user(db, "gone", "ISP Admin", active=False)
    await make_user(db, "nopass", "ISP Admin", password=None)
    answers = {
        (r.status_code, r.text)
        for r in [
            await login(app_client, "nobody"),
            await login(app_client, "alice", "wrong-password-1A!"),
            await login(app_client, "gone"),
            await login(app_client, "nopass", "anything"),
        ]
    }
    assert answers == {(401, '{"detail":"Invalid login or password"}')}


async def test_repeated_failures_lock_the_account_even_against_the_right_password(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    for _ in range(settings.login_max_attempts):
        assert (await login(app_client, "alice", "wrong-password-1A!")).status_code == 401
    locked = await login(app_client, "alice")
    assert locked.status_code == 429 and int(locked.headers["retry-after"]) > 0
    assert "Too many" in locked.json()["detail"]


async def test_unknown_names_are_counted_like_real_ones_so_locking_reveals_nothing(app_client, db):
    for _ in range(settings.login_max_attempts):
        await login(app_client, "ghost", "wrong-password-1A!")
    assert (await login(app_client, "ghost", "wrong-password-1A!")).status_code == 429


async def test_a_successful_login_resets_the_account_counter(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    for _ in range(settings.login_max_attempts - 1):
        await login(app_client, "alice", "wrong-password-1A!")
    assert (await login(app_client, "alice")).status_code == 200
    for _ in range(settings.login_max_attempts - 1):
        assert (await login(app_client, "alice", "wrong-password-1A!")).status_code == 401
    assert (await login(app_client, "alice")).status_code == 200


async def test_login_is_refused_when_lockout_state_cannot_be_read(app_client, db, monkeypatch):
    await make_user(db, "alice", "ISP Admin")

    async def broken(self, username, ip):
        raise RedisConnectionError("redis is down")

    monkeypatch.setattr("app.core.ratelimit.LoginGuard.locked_for", broken)
    assert (await login(app_client, "alice")).status_code == 503


async def test_session_endpoint_reports_the_caller_and_needs_credentials(app_client, db):
    await make_user(db, "alice", "ISP Support")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    me = (await app_client.get("/api/v1/auth/session", headers=headers)).json()
    assert me["username"] == "alice" and "devices.view" in me["permissions"] and me["auth_kind"] == "session"
    assert (await app_client.get("/api/v1/auth/session")).status_code == 401
    assert (await app_client.get("/api/v1/auth/session", headers={"Authorization": "Bearer css_nonsense"})).status_code == 401
    assert (await app_client.get("/api/v1/auth/session", headers={"Authorization": "Basic abc"})).status_code == 401


async def test_the_cookie_can_read_but_never_change_state(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    assert (await login(app_client, "alice")).status_code == 200  # the client keeps the cookie
    assert (await app_client.get("/api/v1/auth/session")).status_code == 200
    blocked = await app_client.post("/api/v1/auth/logout")
    assert blocked.status_code == 403 and "read-only" in blocked.json()["detail"]


async def test_logout_revokes_the_token_and_clears_the_cookie(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    out = await app_client.post("/api/v1/auth/logout", headers=headers)
    assert out.status_code == 200 and "cs_session=" in out.headers["set-cookie"]
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/auth/session", headers=headers)).status_code == 401
    assert await db.fetchval("select count(*) from audit_logs where action = 'auth.logout'") == 1


async def test_expired_and_deactivated_sessions_are_rejected(app_client, db):
    uid = await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    await db.execute("update user_sessions set expires_at = now() - interval '1 minute'")
    assert (await app_client.get("/api/v1/auth/session", headers=headers)).status_code == 401
    headers = await bearer(app_client, "alice")
    await db.execute("update users set is_active = false where id = $1::uuid", uid)
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/auth/session", headers=headers)).status_code == 401


async def test_sessions_can_be_listed_and_revoked_but_not_across_users(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    await make_user(db, "bob", "ISP Admin")
    first, second = await bearer(app_client, "alice"), await bearer(app_client, "alice")
    bob = await bearer(app_client, "bob")
    app_client.cookies.clear()
    listing = (await app_client.get("/api/v1/auth/sessions", headers=first)).json()
    assert len(listing) == 2 and sum(item["current"] for item in listing) == 1
    other = next(item["id"] for item in listing if not item["current"])
    assert (await app_client.delete(f"/api/v1/auth/sessions/{other}", headers=bob)).status_code == 404  # existence not confirmed
    assert (await app_client.delete(f"/api/v1/auth/sessions/{other}", headers=first)).status_code == 200
    assert (await app_client.get("/api/v1/auth/session", headers=second)).status_code == 401
    assert (await app_client.delete("/api/v1/auth/sessions/not-a-uuid", headers=first)).status_code == 404


async def test_a_forced_password_change_blocks_everything_else_until_done(app_client, db):
    await make_user(db, "alice", "ISP Admin", must_change=True)
    first = await login(app_client, "alice")
    assert first.json()["must_change_password"] is True
    headers = {"Authorization": f"Bearer {first.json()['token']}"}
    other = await bearer_only(app_client, "alice")
    app_client.cookies.clear()
    blocked = await app_client.get("/api/v1/devices", headers=headers)
    assert blocked.status_code == 403 and blocked.json()["detail"] == "password_change_required"
    assert (await app_client.get("/api/v1/auth/session", headers=headers)).status_code == 200
    bad = await app_client.post("/api/v1/auth/password", headers=headers, json={"current_password": "wrong", "new_password": "New-Password-Value-7!"})
    assert bad.status_code == 400
    weak = await app_client.post("/api/v1/auth/password", headers=headers, json={"current_password": DEFAULT_PASSWORD, "new_password": "short"})
    assert weak.status_code == 422 and "TOO_SHORT" in weak.text
    same = await app_client.post("/api/v1/auth/password", headers=headers, json={"current_password": DEFAULT_PASSWORD, "new_password": DEFAULT_PASSWORD})
    assert same.status_code == 422
    done = await app_client.post("/api/v1/auth/password", headers=headers, json={"current_password": DEFAULT_PASSWORD, "new_password": "New-Password-Value-7!"})
    assert done.status_code == 200
    assert (await app_client.get("/api/v1/devices", headers=headers)).status_code == 200
    assert (await app_client.get("/api/v1/auth/session", headers=other)).status_code == 401  # other sessions were revoked
    assert (await login(app_client, "alice")).status_code == 401
    assert (await login(app_client, "alice", "New-Password-Value-7!")).status_code == 200


async def bearer_only(client, username):
    response = await login(client, username)
    return {"Authorization": f"Bearer {response.json()['token']}"}


async def test_legacy_password_hashes_verify_and_are_upgraded_on_login(app_client, db):
    php = "$2y$" + bcrypt.hashpw(b"old-secret", bcrypt.gensalt(rounds=4)).decode()[4:]
    await make_user(db, "bcryptuser", "ISP Admin", password_hash=php)
    await make_user(db, "sha1user", "ISP Admin", password_hash=hashlib.sha1(b"ancient").hexdigest())
    assert (await login(app_client, "bcryptuser", "old-secret")).status_code == 200
    assert (await login(app_client, "sha1user", "ancient")).status_code == 200
    for name in ("bcryptuser", "sha1user"):
        assert scheme_of(await db.fetchval("select password_hash from users where username = $1", name)) == "argon2id"
    assert (await login(app_client, "bcryptuser", "old-secret")).status_code == 200  # still works after the upgrade
    assert (await login(app_client, "sha1user", "wrong")).status_code == 401


async def test_second_factor_full_lifecycle(app_client, db):
    await make_user(db, "alice", "Super Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    secret = (await app_client.post("/api/v1/auth/2fa/enroll", headers=headers)).json()["secret"]
    assert (await app_client.post("/api/v1/auth/2fa/enable", headers=headers, json={"pin": "000000"})).status_code == 400
    enabled = await app_client.post("/api/v1/auth/2fa/enable", headers=headers, json={"pin": totp.totp_at(secret)})
    codes = enabled.json()["recovery_codes"]
    assert enabled.status_code == 200 and len(codes) == 8
    assert "secret" not in await db.fetchval("select totp_secret_enc from users where username = 'alice'")  # stored encrypted
    assert (await app_client.post("/api/v1/auth/2fa/enroll", headers=headers)).status_code == 409

    challenge = await login(app_client, "alice")
    assert challenge.status_code == 200 and challenge.json()["need_2fa"] is True and challenge.json()["token"] is None
    assert (await login(app_client, "alice", twofa_pin="000000")).status_code == 401
    next_pin = totp.totp_at(secret, time.time() + 30)
    assert (await login(app_client, "alice", twofa_pin=next_pin)).status_code == 200
    assert (await login(app_client, "alice", twofa_pin=next_pin)).status_code == 401  # replay of the same code

    assert (await login(app_client, "alice", recovery_code=codes[0])).status_code == 200
    assert (await login(app_client, "alice", recovery_code=codes[0])).status_code == 401  # single use
    assert (await login(app_client, "alice", recovery_code="0000-0000")).status_code == 401

    wrong = await app_client.post("/api/v1/auth/2fa/disable", headers=headers, json={"password": "wrong", "recovery_code": codes[1]})
    assert wrong.status_code == 400
    gone = await app_client.post("/api/v1/auth/2fa/disable", headers=headers, json={"password": DEFAULT_PASSWORD, "recovery_code": codes[1]})
    assert gone.status_code == 200
    assert (await login(app_client, "alice")).json()["token"]


async def test_strict_ip_uses_the_forwarded_address_only_from_a_trusted_proxy(app_client, db):
    await make_user(db, "alice", "ISP Admin", strict_ips=["203.0.113.0/24"])
    inside = await login_from(app_client, "alice", forwarded="203.0.113.9")
    assert inside.status_code == 200
    assert (await login_from(app_client, "alice", forwarded="198.51.100.5")).status_code == 403
    # A client that is not a trusted proxy cannot claim an address by sending the header itself.
    spoof = httpx.AsyncClient(transport=httpx.ASGITransport(app=app_client.app, client=("198.51.100.7", 1)), base_url="http://x")
    async with spoof:
        response = await spoof.post("/api/v1/auth/login", json={"login": "alice", "password": DEFAULT_PASSWORD},
                                    headers={"X-Forwarded-For": "203.0.113.9"})
    assert response.status_code == 403
    # The restriction also applies to a token already issued.
    token = {"Authorization": f"Bearer {inside.json()['token']}"}
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/auth/session", headers={**token, "X-Forwarded-For": "203.0.113.20"})).status_code == 200
    assert (await app_client.get("/api/v1/auth/session", headers={**token, "X-Forwarded-For": "192.0.2.50"})).status_code == 403


async def login_from(client, username, forwarded):
    return await client.post("/api/v1/auth/login", json={"login": username, "password": DEFAULT_PASSWORD}, headers={"X-Forwarded-For": forwarded})


async def test_the_recorded_client_address_is_the_forwarded_one(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    await login_from(app_client, "alice", forwarded="203.0.113.77")
    assert str(await db.fetchval("select ip_address from user_sessions")) == "203.0.113.77"
    assert str(await db.fetchval("select ip_address from login_attempts where success")) == "203.0.113.77"


async def test_attempts_and_audit_are_recorded_without_any_password(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    await login(app_client, "alice", "Wrong-Guess-Password-1!")
    await login(app_client, "alice")
    attempts = await db.fetch("select success, reason from login_attempts order by occurred_at")
    assert [(a["success"], a["reason"]) for a in attempts] == [(False, "bad_password"), (True, "ok")]
    actions = {r["action"] for r in await db.fetch("select action from audit_logs")}
    assert {"auth.login_failed", "auth.login"} <= actions
    leaked = await db.fetchval(
        "select count(*) from audit_logs where metadata::text like any(array['%Wrong-Guess%', '%' || $1 || '%'])", DEFAULT_PASSWORD)
    assert leaked == 0


async def test_the_gate_admits_only_callers_holding_the_apps_permission(app_client, db):
    await make_user(db, "root", "Super Admin")
    await make_user(db, "viewer", "Reseller Viewer")
    root = await bearer(app_client, "root")
    app_client.cookies.clear()
    allowed = await app_client.get("/api/v1/auth/verify", params={"app": "grafana"}, headers=root)
    assert allowed.status_code == 204
    assert allowed.headers["x-auth-user"] == "root" and allowed.headers["x-auth-role"] == "Super Admin"
    assert allowed.headers["x-auth-grafana-role"] == "Admin"
    # the cookie set at login works for the gate, which is what browsers send to a proxied app
    await login(app_client, "root")
    assert (await app_client.get("/api/v1/auth/verify", params={"app": "prometheus"})).status_code == 204
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/auth/verify", params={"app": "grafana"})).status_code == 401
    viewer = await bearer(app_client, "viewer")
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/auth/verify", params={"app": "grafana"}, headers=viewer)).status_code == 403
    assert (await app_client.get("/api/v1/auth/verify", params={"app": "unknown"}, headers=root)).status_code == 403
    assert (await app_client.get("/api/v1/auth/verify", params={"app": "../etc"}, headers=root)).status_code == 403


async def test_the_gate_refuses_an_account_that_must_change_its_password(app_client, db):
    await make_user(db, "root", "Super Admin", must_change=True)
    headers = await bearer(app_client, "root")
    app_client.cookies.clear()
    assert (await app_client.get("/api/v1/auth/verify", params={"app": "grafana"}, headers=headers)).status_code == 403
