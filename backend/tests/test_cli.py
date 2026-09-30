import os

from app import cli
from app.core.passwords import verify_password
from tests.helpers import DEFAULT_PASSWORD, bearer, login, make_user


async def test_set_password_replaces_the_password_and_revokes_sessions(app_client, db, monkeypatch):
    await make_user(db, "alice", "ISP Admin")
    headers = await bearer(app_client, "alice")
    app_client.cookies.clear()
    monkeypatch.setenv("CYBERSATHY_NEW_PASSWORD", "Brand-New-Password-42!")
    assert await cli._set_password("alice") == 0
    assert (await app_client.get("/api/v1/auth/session", headers=headers)).status_code == 401
    assert (await login(app_client, "alice", DEFAULT_PASSWORD)).status_code == 401
    assert (await login(app_client, "alice", "Brand-New-Password-42!")).status_code == 200
    assert verify_password(await db.fetchval("select password_hash from users where username = 'alice'"), "Brand-New-Password-42!")[0]


async def test_set_password_refuses_weak_passwords_and_unknown_users(db, monkeypatch):
    await make_user(db, "alice", "ISP Admin")
    monkeypatch.setenv("CYBERSATHY_NEW_PASSWORD", "weak")
    assert await cli._set_password("alice") == 2
    monkeypatch.setenv("CYBERSATHY_NEW_PASSWORD", "Brand-New-Password-42!")
    assert await cli._set_password("nobody") == 1


async def test_session_cleanup_removes_only_old_rows(app_client, db):
    await make_user(db, "alice", "ISP Admin")
    await bearer(app_client, "alice")
    await db.execute("insert into user_sessions (user_id, token_hash, expires_at) select id, 'old', now() - interval '30 days' from users")
    await db.execute("insert into login_attempts (username, success, occurred_at) values ('a', false, now() - interval '200 days')")
    await cli._sessions_cleanup()
    assert await db.fetchval("select count(*) from user_sessions where token_hash = 'old'") == 0
    assert await db.fetchval("select count(*) from user_sessions") == 1
    assert await db.fetchval("select count(*) from login_attempts where username = 'a'") == 0
    assert await db.fetchval("select count(*) from login_attempts where success") == 1


async def test_seed_command_refuses_a_weak_password_in_production(db, monkeypatch, capsys):
    from app.core.config import settings

    monkeypatch.setattr(settings, "environment", "production")
    monkeypatch.setenv("CYBERSATHY_SEED_ADMIN_PASSWORD", "weak")
    assert await cli._seed(False) == 2
    assert "too weak" in capsys.readouterr().err


def test_parser_rejects_unknown_commands():
    import pytest

    with pytest.raises(SystemExit):
        cli.main(["nonsense"])
