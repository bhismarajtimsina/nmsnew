"""End-to-end coverage of the /ws endpoint: auth via a single-use ?ticket=, the ping/subscribe/unsubscribe protocol, and fan-out
of a message published on Redis - using Starlette's TestClient (a real ASGI websocket handshake, no mocked
transport) so the whole stack (app.main's lifespan, app/api/realtime.py, app/realtime/manager.py and bus.py) runs
for real.

Plain, synchronous tests (not `async def`): TestClient drives the app through its own dedicated event loop in a
background thread, so a websocket round trip here can't be mixed with the async `app_client`/`db` fixtures used
elsewhere in the suite, which run in pytest-asyncio's own loop and would hand back asyncpg connections bound to a
different loop than the one TestClient's requests actually execute on. User setup below opens and closes its own
plain asyncpg connection inside a single `asyncio.run()` call instead, so no connection object crosses loops.
"""
from __future__ import annotations

import asyncio

import asyncpg
import redis as sync_redis
from starlette.testclient import TestClient
from starlette.websockets import WebSocketDisconnect

from app.core.config import settings
from app.core.passwords import hash_password
from app.main import app as real_app

PASSWORD = "Correct-Horse-Battery-9"


async def app(scope, receive, send):
    """Starlette's TestClient hardcodes the peer address to the literal string "testclient", not a real IP - fine
    for every other test's app_client (which supplies a real one via httpx.ASGITransport's `client` kwarg), but a
    login here writes that address into an `inet` column. Give it one."""
    if scope["type"] in ("http", "websocket"):
        scope = {**scope, "client": ("127.0.0.1", 50000)}
    await real_app(scope, receive, send)


def _make_user(username: str, role: str = "ISP Admin", *, revoke_permission: str | None = None) -> None:
    async def _run() -> None:
        conn = await asyncpg.connect(settings.postgres_dsn)
        try:
            role_id = await conn.fetchval("select id from roles where name = $1", role)
            if revoke_permission:
                await conn.execute(
                    "delete from role_permissions where role_id = $1 and permission_id = "
                    "(select id from permissions where code = $2)",
                    role_id, revoke_permission,
                )
            await conn.execute(
                "insert into users (role_id, username, display_name, password_hash, hash_scheme, is_active) "
                "values ($1, $2, $3, $4, 'argon2', true)",
                role_id, username, username.title(), hash_password(PASSWORD),
            )
        finally:
            await conn.close()

    asyncio.run(_run())


def _login(client: TestClient, username: str) -> str:
    response = client.post("/api/v1/auth/login", json={"login": username, "password": PASSWORD})
    assert response.status_code == 200, response.text
    client.cookies.clear()  # the Bearer token is what these tests present
    return response.json()["token"]


def _ticket(client: TestClient, token: str) -> str:
    response = client.post("/api/v1/realtime/ticket", headers={"Authorization": f"Bearer {token}"})
    assert response.status_code == 200, response.text
    assert response.json()["expires_in"] == 30
    return response.json()["ticket"]


def _refused(client: TestClient, url: str) -> None:
    try:
        with client.websocket_connect(url):
            raise AssertionError("expected the handshake to be refused")
    except WebSocketDisconnect as exc:
        assert exc.code == 4401


def test_rejects_a_missing_ticket(clean):
    with TestClient(app) as client:
        _refused(client, "/ws")


def test_rejects_an_invalid_ticket(clean):
    with TestClient(app) as client:
        _refused(client, "/ws?ticket=csw_not-a-real-ticket")


def test_a_session_token_in_the_url_is_no_longer_accepted(clean):
    """The whole point: a long-lived credential must never be needed in a URL, where logs keep it."""
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        token = _login(client, "alice")
        _refused(client, f"/ws?token={token}")
        _refused(client, f"/ws?ticket={token}")


def test_a_ticket_works_once(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        ticket = _ticket(client, _login(client, "alice"))
        with client.websocket_connect(f"/ws?ticket={ticket}") as ws:
            assert ws.receive_json()["type"] == "ready"
        _refused(client, f"/ws?ticket={ticket}")


def test_an_expired_ticket_is_refused(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        ticket = _ticket(client, _login(client, "alice"))
        redis_client = sync_redis.Redis.from_url(settings.redis_dsn)
        try:
            for key in redis_client.scan_iter("cs:ws:ticket:*"):
                redis_client.delete(key)  # what its 30-second expiry does
        finally:
            redis_client.close()
        _refused(client, f"/ws?ticket={ticket}")


def test_revoking_the_session_also_stops_its_unused_tickets(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        token = _login(client, "alice")
        ticket = _ticket(client, token)
        assert client.post("/api/v1/auth/logout", headers={"Authorization": f"Bearer {token}"}).status_code in (200, 204)
        _refused(client, f"/ws?ticket={ticket}")


def test_the_session_cookie_cannot_mint_a_ticket(clean):
    """Cookies are read-only: otherwise any page the user visits could mint a ticket with their cookie."""
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        response = client.post("/api/v1/auth/login", json={"login": "alice", "password": PASSWORD})
        assert response.status_code == 200 and client.cookies.get(settings.session_cookie_name)
        assert client.post("/api/v1/realtime/ticket").status_code == 403


def test_redis_holds_neither_the_ticket_nor_the_session_token(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        token = _login(client, "alice")
        ticket = _ticket(client, token)
        redis_client = sync_redis.Redis.from_url(settings.redis_dsn)
        try:
            keys = list(redis_client.scan_iter("cs:ws:ticket:*"))
            assert len(keys) == 1 and ticket.encode() not in keys[0]
            stored = redis_client.get(keys[0])
            assert token.encode() not in stored and ticket.encode() not in stored
            assert 0 < redis_client.ttl(keys[0]) <= 30
        finally:
            redis_client.close()


def test_ready_ping_subscribe_and_unsubscribe(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        token = _login(client, "alice")
        with client.websocket_connect(f"/ws?ticket={_ticket(client, token)}") as ws:
            ready = ws.receive_json()
            assert ready["type"] == "ready" and ready["user"]["username"] == "alice"

            ws.send_json({"action": "ping"})
            assert ws.receive_json() == {"type": "pong"}

            ws.send_json({"action": "subscribe", "channel": "events.created"})
            assert ws.receive_json() == {"type": "subscribed", "channel": "events.created"}

            ws.send_json({"action": "unsubscribe", "channel": "events.created"})
            assert ws.receive_json() == {"type": "unsubscribed", "channel": "events.created"}

            ws.send_json({"action": "made-up-action"})
            assert ws.receive_json() == {"type": "error", "error": "unsupported_action"}


def test_a_scoped_role_without_the_permission_is_refused_the_channel(clean):
    # Reseller Viewer is scope_mode "assigned" and normally holds events.view; revoked here so the subscribe is
    # denied purely on the permission check (a separate rule denies any wildcard channel to a non-scope-all caller).
    _make_user("bob", "Reseller Viewer", revoke_permission="events.view")
    with TestClient(app) as client:
        token = _login(client, "bob")
        with client.websocket_connect(f"/ws?ticket={_ticket(client, token)}") as ws:
            ws.receive_json()  # ready
            ws.send_json({"action": "subscribe", "channel": "events.created"})
            reply = ws.receive_json()
            assert reply["type"] == "error" and reply["error"] == "forbidden"


def test_a_published_event_is_fanned_out_to_a_subscribed_connection(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        token = _login(client, "alice")
        with client.websocket_connect(f"/ws?ticket={_ticket(client, token)}") as ws:
            ws.receive_json()  # ready
            ws.send_json({"action": "subscribe", "channel": "events.*"})
            ws.receive_json()  # subscribed ack

            # Published over the real Redis server the app itself is subscribed to, from a plain synchronous
            # client - the fan-out must reach the app's background subscriber regardless of which asyncio event
            # loop published it, exactly as it would from a genuinely separate worker process in production.
            redis_client = sync_redis.Redis.from_url(settings.redis_dsn)
            try:
                redis_client.publish("cybersathy:realtime", '{"name": "events.created", "data": {"id": "abc"}}')
            finally:
                redis_client.close()

            event = ws.receive_json()
            assert event == {"type": "event", "channel": "events.created", "data": {"id": "abc"}}


def test_an_unmatched_published_event_is_not_delivered(clean):
    _make_user("alice", "ISP Admin")
    with TestClient(app) as client:
        token = _login(client, "alice")
        with client.websocket_connect(f"/ws?ticket={_ticket(client, token)}") as ws:
            ws.receive_json()  # ready
            ws.send_json({"action": "subscribe", "channel": "events.*"})
            ws.receive_json()  # subscribed ack

            redis_client = sync_redis.Redis.from_url(settings.redis_dsn)
            try:
                redis_client.publish("cybersathy:realtime", '{"name": "devices.updated", "data": {}}')
                # A channel this connection did subscribe to, sent right after: if it arrives, the earlier
                # non-matching publish was correctly dropped rather than delivered out of order.
                redis_client.publish("cybersathy:realtime", '{"name": "events.created", "data": {"id": "xyz"}}')
            finally:
                redis_client.close()

            event = ws.receive_json()
            assert event == {"type": "event", "channel": "events.created", "data": {"id": "xyz"}}
