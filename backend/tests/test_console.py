"""Plan 38's console gateway: transcript redaction, the session relay, tickets and limits, scoped transcripts, and the
gateway's WebSocket. A scripted shell only: no device is contacted and no interactive transport exists in this build."""
import asyncio
import json

import asyncpg
import pytest

from app.console import broker
from app.console.redact import MARKER, TranscriptRedactor
from app.console.session import (
    CHUNK_LIMIT, CLOSED_BY_DEVICE, CLOSED_BY_USER, IDLE, TIME_LIMIT, DisabledShellFactory, ShellUnavailable, run_session,
)
from app.core.config import settings
from tests.helpers import bearer, make_device, make_group, make_user

C = "/api/v1/console/sessions"
SECRET = "hunter2-Sw!tch"


# --- redaction ---

def typed(redactor, text):
    """Keystrokes one at a time, as a terminal sends them."""
    return "".join(redactor.input(ch) for ch in text)


@pytest.mark.parametrize("prompt", ["Password: ", "password:", "\r\nPassword:", "Enter password: ", "Passwd: ", "Secret:",
                                    "Enter passphrase for key '/root/.ssh/id_rsa': ", "PIN: ", "Пароль: ", "admin@10.0.0.1's password: ",
                                    "Password for admin: "])
def test_input_at_a_password_prompt_is_hidden(prompt):
    r = TranscriptRedactor()
    r.output(prompt)
    assert typed(r, SECRET + "\r") == MARKER + "\r"


def test_a_username_and_ordinary_commands_are_kept():
    r = TranscriptRedactor()
    r.output("User Name: ")
    assert typed(r, "admin\r") == "admin\r"
    r.output("\r\nPassword: ")
    assert typed(r, SECRET + "\r") == MARKER + "\r"
    r.output("\r\nswitch#")
    assert typed(r, "show running-config\r") == "show running-config\r"


def test_a_prompt_split_across_chunks_still_counts():
    r = TranscriptRedactor()
    r.output("\r\nPass")
    r.output("word: ")
    assert typed(r, SECRET + "\n") == MARKER + "\n"


def test_input_after_the_secret_in_the_same_chunk_is_kept():
    r = TranscriptRedactor()
    r.output("Password:")
    assert r.input(SECRET + "\rshow ver\r") == MARKER + "\rshow ver\r"


def test_a_device_that_echoes_stars_keeps_the_input_hidden():
    r = TranscriptRedactor()
    r.output("Password: ")
    out = ""
    for ch in SECRET:
        out += r.input(ch)
        r.output("*")
    assert out + r.input("\r") == MARKER + "\r"


def test_hiding_ends_when_the_device_prints_something_else_on_the_line():
    r = TranscriptRedactor()
    r.output("username admin password")  # a config line that merely ends in "password"
    r.output("\r\nswitch# ")
    assert typed(r, "exit\r") == "exit\r"


def test_enable_password_on_a_cisco_style_device():
    r = TranscriptRedactor()
    r.output("switch>")
    assert typed(r, "enable\r") == "enable\r"
    r.output("enable\r\nPassword: ")
    assert typed(r, SECRET + "\r") == MARKER + "\r"


# --- the relay ---

class FakeClient:
    def __init__(self, inputs, *, then_wait=False):
        self.inputs, self.sent, self.then_wait = list(inputs), [], then_wait

    async def receive(self):
        await asyncio.sleep(0)
        if self.inputs:
            return self.inputs.pop(0)
        if self.then_wait:
            await asyncio.Event().wait()
        return None

    async def send(self, data):
        self.sent.append(data)


class FakeShell:
    """Answers each Enter with the next scripted reply; closes when the script runs out if `close_after` is set."""

    def __init__(self, greeting="", replies=(), close_after=False):
        self.out: asyncio.Queue = asyncio.Queue()
        self.received, self.replies, self.closed, self.close_after = [], list(replies), False, close_after
        if greeting:
            self.out.put_nowait(greeting)

    async def read(self):
        return await self.out.get()

    async def write(self, data):
        self.received.append(data)
        if "\r" in data or "\n" in data:
            if self.replies:
                self.out.put_nowait(self.replies.pop(0))
            elif self.close_after:
                self.out.put_nowait(None)

    async def close(self):
        self.closed = True


class Store:
    def __init__(self):
        self.rows = []

    async def __call__(self, direction, data):
        self.rows.append((direction, data))


async def test_the_relay_records_everything_except_the_secret_which_still_reaches_the_device():
    client = FakeClient(["admin\r", *list(SECRET), "\r", "show ver\r", "exit\r"], then_wait=True)
    shell = FakeShell("Username: ", ["\r\nPassword: ", "\r\nsw# ", "\r\nVersion 1.0\r\nsw# "], close_after=True)
    store = Store()
    reason = await run_session(client, shell, store, banner="BANNER\r\n", idle_seconds=60, max_seconds=600, tick=0.01)
    assert reason == CLOSED_BY_DEVICE and shell.closed
    assert store.rows[0] == ("out", "BANNER\r\n") and client.sent[0] == "BANNER\r\n"
    recorded = "".join(data for _, data in store.rows)
    assert SECRET not in recorded and MARKER in recorded and "show ver" in recorded and "Version 1.0" in recorded
    assert "".join(shell.received) == "admin\r" + SECRET + "\rshow ver\rexit\r"  # the device got every keystroke
    assert client.sent[-1] == "\r\nVersion 1.0\r\nsw# "  # the end is announced by the gateway, after it is recorded


async def test_the_session_ends_when_the_user_leaves():
    shell = FakeShell("sw# ")
    reason = await run_session(FakeClient(["show clock\r"]), shell, Store(), banner="B", idle_seconds=60, max_seconds=600, tick=0.01)
    assert reason == CLOSED_BY_USER and shell.closed


async def test_idle_and_total_time_limits():
    now = [0.0]

    def clock():
        now[0] += 1.0
        return now[0]

    idle = await run_session(FakeClient([], then_wait=True), FakeShell(), Store(), banner="B", idle_seconds=5, max_seconds=600,
                             clock=clock, tick=0)
    assert idle == IDLE

    now[0] = 0.0

    class Chatty(FakeClient):
        async def receive(self):
            await asyncio.sleep(0)
            return " "  # keeps typing, so only the total limit can end it

    capped = await run_session(Chatty([]), FakeShell(), Store(), banner="B", idle_seconds=10_000, max_seconds=50, clock=clock, tick=0)
    assert capped == TIME_LIMIT


async def test_long_output_is_split_to_fit_the_transcript_column():
    store = Store()
    shell = FakeShell("x" * (CHUNK_LIMIT * 2 + 5))
    shell.out.put_nowait(None)
    await run_session(FakeClient([], then_wait=True), shell, store, banner="B", idle_seconds=60, max_seconds=600, tick=0.01)
    sizes = [len(d) for direction, d in store.rows if direction == "out" and d.startswith("x")]
    assert sizes == [CHUNK_LIMIT, CHUNK_LIMIT, 5]


async def test_the_default_shell_factory_refuses():
    with pytest.raises(ShellUnavailable):
        await DisabledShellFactory().connect("10.0.0.1", username=None, password=None)


# --- requesting a session ---

@pytest.fixture
def enabled(monkeypatch):
    monkeypatch.setattr(settings, "console_enabled", True)


async def login(app_client, db, name="op", role="ISP Admin", group=None, grant=()):
    user = await make_user(db, name, role)
    for code in grant:
        await db.execute("insert into role_permissions (role_id, permission_id) select role_id, (select id from permissions where code = $2) "
                         "from users where id = $1::uuid on conflict do nothing", user, code)
    if group is not None:
        await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user, headers


async def test_switched_off_by_default(app_client, db):
    assert settings.console_enabled is False
    device = await make_device(db, "sw")
    _, headers = await login(app_client, db)
    response = await app_client.post(C, headers=headers, json={"device_id": device})
    assert response.status_code == 503 and await db.fetchval("select count(*) from console_sessions") == 0


async def test_a_ticket_is_issued_once_stored_hashed_and_short_lived(app_client, db, enabled):
    device = await make_device(db, "sw")
    user, headers = await login(app_client, db)
    response = await app_client.post(C, headers=headers, json={"device_id": device})
    assert response.status_code == 201, response.text
    body = response.json()
    assert body["gateway_path"] == "/console/ws" and len(body["ticket"]) >= 40
    row = await db.fetchrow("select status, ticket_hash, ticket_expires_at - created_at as ttl, user_id from console_sessions")
    assert row["status"] == "pending" and row["ticket_hash"] != body["ticket"] and str(row["user_id"]) == user
    assert row["ttl"].total_seconds() <= broker.TICKET_TTL_SECONDS + 1
    assert body["ticket"] not in json.dumps([dict(r) for r in await db.fetch("select metadata from audit_logs")], default=str)
    assert await db.fetchval("select count(*) from audit_logs where action = 'console.requested'") == 1


async def test_scope_and_permissions_are_enforced(app_client, db, enabled):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    hidden, visible = await make_device(db, "hidden", theirs), await make_device(db, "visible", mine)
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("console.open",))
    assert (await app_client.post(C, headers=reseller, json={"device_id": hidden})).status_code == 404
    assert (await app_client.post(C, headers=reseller, json={"device_id": visible})).status_code == 201
    # Automatic login needs its own permission, and is not available in this build even with it.
    assert (await app_client.post(C, headers=reseller, json={"device_id": visible, "auto_auth": True})).status_code == 403
    _, admin = await login(app_client, db, "adm", grant=("console.open_auto_auth",))
    refused = await app_client.post(C, headers=admin, json={"device_id": visible, "auto_auth": True})
    assert refused.status_code == 501 and "credentials" in refused.json()["detail"]
    user, nobody = await login(app_client, db, "nobody")
    await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                     "and permission_id = (select id from permissions where code = 'console.open')", user)
    assert (await app_client.post(C, headers=nobody, json={"device_id": visible})).status_code == 403


async def test_each_device_and_each_user_have_a_session_limit(app_client, db, enabled, monkeypatch):
    monkeypatch.setattr(settings, "console_max_per_device", 2)
    monkeypatch.setattr(settings, "console_max_per_user", 3)
    group = await make_group(db, "G")
    a, b = await make_device(db, "a", group), await make_device(db, "b", group)
    _, op1 = await login(app_client, db, "op1")
    _, op2 = await login(app_client, db, "op2")
    codes = [(await app_client.post(C, headers=h, json={"device_id": a})).status_code for h in (op1, op2, op2)]
    assert codes == [201, 201, 429]
    assert (await app_client.post(C, headers=op1, json={"device_id": b})).status_code == 201
    assert (await app_client.post(C, headers=op1, json={"device_id": b})).status_code == 201
    over = await app_client.post(C, headers=op1, json={"device_id": b})
    assert over.status_code == 429  # device b is full too; op1 holds 3


async def test_expired_tickets_and_abandoned_sessions_do_not_hold_a_device(app_client, db, enabled, monkeypatch):
    monkeypatch.setattr(settings, "console_max_per_device", 1)
    device = await make_device(db, "sw")
    _, headers = await login(app_client, db)
    assert (await app_client.post(C, headers=headers, json={"device_id": device})).status_code == 201
    await db.execute("update console_sessions set created_at = now() - interval '1 minute', ticket_expires_at = now() - interval '1 second'")
    assert (await app_client.post(C, headers=headers, json={"device_id": device})).status_code == 201
    assert sorted(r["status"] for r in await db.fetch("select status from console_sessions")) == ["expired", "pending"]
    # An open session whose gateway died long ago is not counted either.
    await db.execute("update console_sessions set status = 'open', opened_at = now() - make_interval(secs => $1) where status = 'pending'",
                     settings.console_max_seconds + broker.STALE_MARGIN_SECONDS + 5)
    assert (await app_client.post(C, headers=headers, json={"device_id": device})).status_code == 201
    await db.execute("update console_sessions set opened_at = now() where status = 'open'")  # a live one does count
    assert (await app_client.post(C, headers=headers, json={"device_id": device})).status_code == 429


async def test_a_ticket_opens_its_session_once_and_never_after_expiry(app_client, db, enabled):
    device = await make_device(db, "sw")
    _, headers = await login(app_client, db)
    first = (await app_client.post(C, headers=headers, json={"device_id": device})).json()
    second = (await app_client.post(C, headers=headers, json={"device_id": device})).json()
    opened = await broker.redeem(db, first["ticket"])
    assert str(opened["id"]) == first["session_id"]
    assert await broker.redeem(db, first["ticket"]) is None
    assert await broker.redeem(db, "not-a-ticket") is None
    await db.execute("update console_sessions set created_at = now() - interval '1 minute', ticket_expires_at = now() - interval '1 second' "
                     "where id = $1::uuid", second["session_id"])
    assert await broker.redeem(db, second["ticket"]) is None
    async with db.transaction():
        with pytest.raises(RuntimeError):
            await broker.redeem(db, "anything")


# --- reading transcripts ---

async def test_transcripts_are_scoped_need_their_permission_and_come_in_order(app_client, db, enabled):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    visible, hidden = await make_device(db, "visible", mine), await make_device(db, "hidden", theirs)
    _, admin = await login(app_client, db, "adm")
    sessions = {}
    for name, device in (("visible", visible), ("hidden", hidden)):
        ticket = (await app_client.post(C, headers=admin, json={"device_id": device})).json()
        opened = await broker.redeem(db, ticket["ticket"])
        recorder = broker.Recorder(db, str(opened["id"]))
        await recorder("out", "Password: ")
        await recorder("in", MARKER + "\r")
        await recorder("out", "sw# ")
        await broker.close(db, str(opened["id"]), CLOSED_BY_USER)
        sessions[name] = str(opened["id"])
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("console.logs.view",))
    listed = (await app_client.get(C, headers=reseller)).json()["items"]
    assert [s["id"] for s in listed] == [sessions["visible"]] and listed[0]["close_reason"] == CLOSED_BY_USER
    history = (await app_client.get(f"{C}/{sessions['visible']}/history", headers=reseller)).json()
    assert [(c["seq"], c["direction"], c["data"]) for c in history["chunks"]] == [
        (1, "out", "Password: "), (2, "in", MARKER + "\r"), (3, "out", "sw# ")]
    assert (await app_client.get(f"{C}/{sessions['visible']}/history?after_seq=2", headers=reseller)).json()["chunks"][0]["seq"] == 3
    assert (await app_client.get(f"{C}/{sessions['hidden']}/history", headers=reseller)).status_code == 404
    await db.execute("delete from role_permissions where role_id = (select id from roles where name = 'Reseller Operator') "
                     "and permission_id = (select id from permissions where code = 'console.logs.view')")
    assert (await app_client.get(C, headers=reseller)).status_code == 403  # permissions are read per request


async def test_the_database_keeps_session_states_consistent(app_client, db, enabled):
    device = await make_device(db, "sw")
    _, headers = await login(app_client, db)
    await app_client.post(C, headers=headers, json={"device_id": device})
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update console_sessions set status = 'open'")  # open needs opened_at
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update console_sessions set status = 'closed'")  # closed needs closed_at
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update console_sessions set ticket_expires_at = created_at + interval '1 hour'")


async def test_typing_keeps_a_session_alive_past_the_idle_window():
    now = [0.0]

    def clock():
        now[0] += 1.0
        return now[0]

    class Typist(FakeClient):
        async def receive(self):
            await asyncio.sleep(0)
            return "x"

    # Idle after 5 s without input, but the user types all the time: only the 50 s total limit ends it.
    reason = await run_session(Typist([]), FakeShell(), Store(), banner="B", idle_seconds=5, max_seconds=50, clock=clock, tick=0)
    assert reason == TIME_LIMIT


async def test_the_per_user_limit_holds_on_its_own_and_ignores_expired_tickets(app_client, db, enabled, monkeypatch):
    monkeypatch.setattr(settings, "console_max_per_device", 10)
    monkeypatch.setattr(settings, "console_max_per_user", 1)
    group = await make_group(db, "G")
    a, b = await make_device(db, "a", group), await make_device(db, "b", group)
    _, headers = await login(app_client, db)
    assert (await app_client.post(C, headers=headers, json={"device_id": a})).status_code == 201
    refused = await app_client.post(C, headers=headers, json={"device_id": b})
    assert refused.status_code == 429 and "you already have" in refused.json()["detail"]
    # The ticket for device a expires unused: it no longer counts, though nothing has swept device a.
    await db.execute("update console_sessions set created_at = now() - interval '1 minute', ticket_expires_at = now() - interval '1 second'")
    assert (await app_client.post(C, headers=headers, json={"device_id": b})).status_code == 201
