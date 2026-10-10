"""The console gateway's WebSocket end to end (Plan 38), with Starlette's TestClient and a scripted shell factory. Plain
synchronous tests, for the same reason as tests/test_realtime_ws.py: TestClient runs the app on its own loop, so
setup uses its own short-lived asyncpg connection inside asyncio.run(). No device is contacted."""
from __future__ import annotations

import asyncio

import asyncpg
import pytest
from starlette.testclient import TestClient
from starlette.websockets import WebSocketDisconnect

from app.console import broker
from app.console.gateway import UNAUTHORIZED, UNAVAILABLE, create_app
from app.console.redact import MARKER
from app.console.session import ShellUnavailable
from app.core.config import settings
from app.core.security import CurrentUser

SECRET = "Sw1tch-Secret!"


class ScriptedShell:
    def __init__(self):
        self.out: asyncio.Queue = asyncio.Queue()
        self.out.put_nowait("Password: ")
        self.received: list[str] = []
        self.closed = False

    async def read(self):
        return await self.out.get()

    async def write(self, data):
        self.received.append(data)
        if data.endswith("\r"):
            line = "".join(self.received)
            self.out.put_nowait(None if line.endswith("exit\r") else "\r\nsw# ")

    async def close(self):
        self.closed = True


class Factory:
    def __init__(self, refuse=False):
        self.refuse, self.shells, self.addresses, self.logins = refuse, [], [], []

    async def connect(self, address, *, protocol, port, username, password, enable_password):
        self.addresses.append((address, username, password))
        self.logins.append({"protocol": protocol, "port": port, "username": username, "password": password,
                            "enable_password": enable_password})
        if self.refuse:
            raise ShellUnavailable("no transport")
        shell = ScriptedShell()
        self.shells.append(shell)
        return shell


def _run(coro):
    return asyncio.run(coro)


CLI_PASS, CLI_ENABLE = "Stored-CANARY-login-5", "Stored-CANARY-enable-6"


async def _setup(*, revoke: str | None = None, deactivate=False, auto_auth=False, cli=False) -> tuple[str, str, str]:
    """A user, a device and a ticket for it, straight through the broker. Returns (ticket, session_id, user_id)."""
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        role = await conn.fetchrow("select id, name, scope_mode from roles where name = 'ISP Admin'")
        user = str(await conn.fetchval(
            "insert into users (role_id, username, display_name, password_hash, hash_scheme, is_active) "
            "values ($1, 'op', 'Op', 'x', 'argon2', true) returning id", role["id"]))
        device = str(await conn.fetchval(
            "insert into devices (name, management_ip, device_type) values ('core-sw', '10.40.0.1', 'switch') returning id"))
        if cli:
            from app.core.crypto import EncryptionService
            from app.repositories import access_profiles as profiles

            enc = EncryptionService.from_settings()
            pid = await profiles.create_profile(conn, enc, {
                "name": "cli", "snmp_version": "v2c", "snmp_community": "ro", "timeout_ms": 2000, "retries": 1, "cli_protocol": "telnet",
                "cli_port": 2323, "cli_username": "netops", "cli_password": CLI_PASS, "cli_enable_password": CLI_ENABLE})
            await conn.execute("update devices set access_profile_id = $2::uuid where id = $1::uuid", device, pid)
        actor = CurrentUser(user, "op", "Op", None, role["name"], str(role["id"]), role["scope_mode"],
                            frozenset({"console.open", "console.open_auto_auth", "devices.view"}))
        out = await broker.request_session(conn, actor, device, auto_auth=auto_auth)
        if revoke:
            await conn.execute("delete from role_permissions where role_id = $1 and permission_id = (select id from permissions where code = $2)",
                               role["id"], revoke)
        if deactivate:
            await conn.execute("update users set is_active = false where id = $1::uuid", user)
        return out["ticket"], out["session_id"], user
    finally:
        await conn.close()


async def _fetch(sql, *args):
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        return await conn.fetch(sql, *args)
    finally:
        await conn.close()


@pytest.fixture
def enabled(monkeypatch):
    monkeypatch.setattr(settings, "console_enabled", True)


def _refused(client, url, code):
    with pytest.raises(WebSocketDisconnect) as exc:
        with client.websocket_connect(url) as ws:
            ws.receive_text()
    assert exc.value.code == code


def test_a_session_relays_records_without_the_secret_and_closes_with_a_reason(clean, enabled):
    ticket, sid, user = _run(_setup())
    factory = Factory()
    with TestClient(create_app(factory)) as client:
        with client.websocket_connect(f"/console/ws?ticket={ticket}") as ws:
            banner = ws.receive_text()
            assert "core-sw" in banner and sid in banner and "recorded" in banner
            assert ws.receive_text() == "Password: "
            for ch in SECRET:
                ws.send_text(ch)
            ws.send_text("\r")
            assert ws.receive_text() == "\r\nsw# "
            ws.send_text("exit\r")
            assert "session ended" in ws.receive_text()
    assert factory.addresses == [("10.40.0.1", None, None)]  # address from the database; no credentials injected
    assert factory.shells[0].closed and "".join(factory.shells[0].received) == SECRET + "\rexit\r"
    session = _run(_fetch("select status, close_reason from console_sessions where id = $1::uuid", sid))[0]
    assert (session["status"], session["close_reason"]) == ("closed", "device closed the connection")
    transcript = "".join(r["data"] for r in _run(_fetch("select data from console_history where session_id = $1::uuid order by seq", sid)))
    assert SECRET not in transcript and MARKER in transcript and "exit" in transcript
    actions = [r["action"] for r in _run(_fetch("select action from audit_logs where action like 'console.%' order by occurred_at"))]
    assert actions == ["console.requested", "console.opened", "console.closed"]


def test_a_ticket_works_once_and_a_bad_one_never(clean, enabled):
    ticket, _, _ = _run(_setup())
    with TestClient(create_app(Factory())) as client:
        with client.websocket_connect(f"/console/ws?ticket={ticket}") as ws:
            ws.receive_text()
            ws.receive_text()
            ws.send_text("exit\r")
        _refused(client, f"/console/ws?ticket={ticket}", UNAUTHORIZED)
        _refused(client, "/console/ws?ticket=nope", UNAUTHORIZED)
        _refused(client, "/console/ws", UNAUTHORIZED)


@pytest.mark.parametrize("change", [{"revoke": "console.open"}, {"deactivate": True}])
def test_the_requester_is_checked_again_when_the_ticket_is_redeemed(clean, enabled, change):
    ticket, sid, _ = _run(_setup(**change))
    factory = Factory()
    with TestClient(create_app(factory)) as client:
        _refused(client, f"/console/ws?ticket={ticket}", UNAUTHORIZED)
    assert factory.addresses == []
    row = _run(_fetch("select status, close_reason from console_sessions where id = $1::uuid", sid))[0]
    assert row["status"] == "closed" and row["close_reason"].startswith("refused")


def test_without_a_transport_the_session_is_closed_and_says_why(clean, enabled):
    ticket, sid, _ = _run(_setup())
    with TestClient(create_app(Factory(refuse=True))) as client:
        with pytest.raises(WebSocketDisconnect) as exc:
            with client.websocket_connect(f"/console/ws?ticket={ticket}") as ws:
                assert "not opened" in ws.receive_text()
                ws.receive_text()
        assert exc.value.code == UNAVAILABLE
    row = _run(_fetch("select status, close_reason from console_sessions where id = $1::uuid", sid))[0]
    assert row["status"] == "closed" and "no transport" in row["close_reason"]


def test_switched_off_the_gateway_refuses_even_a_valid_ticket(clean, monkeypatch):
    monkeypatch.setattr(settings, "console_enabled", True)
    ticket, sid, _ = _run(_setup())
    monkeypatch.setattr(settings, "console_enabled", False)
    with TestClient(create_app(Factory())) as client:
        _refused(client, f"/console/ws?ticket={ticket}", UNAVAILABLE)
    assert _run(_fetch("select status from console_sessions where id = $1::uuid", sid))[0]["status"] == "pending"


def _drive(client, ticket):
    with client.websocket_connect(f"/console/ws?ticket={ticket}") as ws:
        ws.receive_text()
        ws.receive_text()
        ws.send_text("exit\r")
        ws.receive_text()


def test_a_manual_session_uses_the_profiles_protocol_and_port_but_never_its_stored_login(clean, enabled):
    ticket, _, _ = _run(_setup(cli=True))
    factory = Factory()
    with TestClient(create_app(factory)) as client:
        _drive(client, ticket)
    assert factory.logins == [{"protocol": "telnet", "port": 2323, "username": None, "password": None, "enable_password": None}]


def test_an_automatic_login_hands_the_decrypted_login_to_the_shell_and_never_to_the_transcript(clean, enabled):
    ticket, sid, _ = _run(_setup(cli=True, auto_auth=True))
    factory = Factory()
    with TestClient(create_app(factory)) as client:
        _drive(client, ticket)
    assert factory.logins == [{"protocol": "telnet", "port": 2323, "username": "netops", "password": CLI_PASS, "enable_password": CLI_ENABLE}]
    stored = " ".join(r["data"] for r in _run(_fetch("select data from console_history where session_id = $1::uuid", sid)))
    audit = " ".join(str(dict(r)) for r in _run(_fetch("select * from audit_logs")))
    assert CLI_PASS not in stored + audit and CLI_ENABLE not in stored + audit


def test_an_automatic_login_is_refused_when_the_permission_was_withdrawn_after_the_request(clean, enabled):
    ticket, sid, _ = _run(_setup(cli=True, auto_auth=True, revoke="console.open_auto_auth"))
    factory = Factory()
    with TestClient(create_app(factory)) as client:
        with pytest.raises(WebSocketDisconnect) as exc:
            with client.websocket_connect(f"/console/ws?ticket={ticket}") as ws:
                assert "may no longer log in automatically" in ws.receive_text()
                ws.receive_text()
        assert exc.value.code == UNAVAILABLE
    assert factory.logins == []
    assert "may no longer log in automatically" in _run(_fetch("select close_reason from console_sessions where id = $1::uuid", sid))[0]["close_reason"]
