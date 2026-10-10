"""The console gateway process (Plan 38): `python -m app.console` serves one WebSocket, `/console/ws?ticket=...`.

It is a separate service from the API, so the API process never opens a device shell. On connect it redeems the
ticket (single use, 30 s), loads the requester again with fresh data and re-checks `console.open` and the device's
scope, opens the shell through its `ShellFactory`, and relays with `run_session`, recording the transcript. The
default factory refuses every connection: no interactive transport is part of this build yet.
"""
from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, WebSocket
from starlette.websockets import WebSocketDisconnect

from app.actions.safety import load_actor
from app.console import broker
from app.console.session import DisabledShellFactory, ShellFactory, ShellUnavailable, ended_message, run_session
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import create_pool
from app.core.crypto import EncryptionNotConfigured, EncryptionService
from app.repositories import access_profiles as profile_repo
from app.repositories import devices as device_repo

logger = logging.getLogger("cybersathy.console")

UNAUTHORIZED = 4401
UNAVAILABLE = 4503


class WebSocketClient:
    def __init__(self, websocket: WebSocket) -> None:
        self.websocket = websocket

    async def receive(self) -> str | None:
        try:
            return await self.websocket.receive_text()
        except WebSocketDisconnect:
            return None

    async def send(self, data: str) -> None:
        await self.websocket.send_text(data)


async def _login(conn: Any, device_id: str, *, auto_auth: bool, actor: Any) -> dict[str, Any]:
    """Protocol and port from the device's access profile (ssh/22 when it names none). The stored login is decrypted only
    for an automatic-login session whose requester still holds `console.open_auto_auth`; otherwise the user types it,
    and the transcript hides what is typed at the password prompt."""
    if auto_auth and not actor.has_permission("console.open_auto_auth"):
        raise ShellUnavailable("the requester may no longer log in automatically")
    want_secrets = auto_auth
    if want_secrets:
        try:
            enc = EncryptionService.from_settings()
        except EncryptionNotConfigured as exc:
            raise ShellUnavailable("stored credentials cannot be read: encryption is not configured") from exc
    else:
        enc = None
    login = await profile_repo.cli_login(conn, enc, device_id)
    if login is None:
        return {"protocol": "ssh", "port": 22, "username": None, "password": None, "enable_password": None}
    if want_secrets and not (login["username"] and login["password"]):
        raise ShellUnavailable("the device's access profile no longer holds a CLI login")
    return {"protocol": login["protocol"], "port": login["port"], "username": login["username"] if want_secrets else None,
            "password": login["password"], "enable_password": login["enable_password"]}


def create_app(shell_factory: ShellFactory | None = None, *, pool: Any = None) -> FastAPI:
    factory = shell_factory or DisabledShellFactory()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        app.state.pool = pool or await create_pool()
        try:
            yield
        finally:
            if pool is None:
                await app.state.pool.close()

    app = FastAPI(title="CyberSathy console gateway", lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)

    @app.get("/console/health")
    async def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.websocket("/console/ws")
    async def console(websocket: WebSocket) -> None:
        if not settings.console_enabled:
            await websocket.close(code=UNAVAILABLE, reason="console is switched off")
            return
        async with websocket.app.state.pool.acquire() as conn:
            session = await broker.redeem(conn, websocket.query_params.get("ticket", ""))
            if session is None:
                await websocket.close(code=UNAUTHORIZED, reason="unauthorized")
                return
            sid = str(session["id"])
            actor = await load_actor(conn, str(session["user_id"])) if session["user_id"] else None
            device = await device_repo.get_device(conn, actor, str(session["device_id"])) if actor and session["device_id"] else None
            if actor is None or not actor.has_permission("console.open") or device is None:
                await broker.close(conn, sid, "refused: the requester may no longer open this console")
                await websocket.close(code=UNAUTHORIZED, reason="unauthorized")
                return
            await websocket.accept()
            await write_audit(conn, action="console.opened", actor_user_id=actor.id, resource_type="device",
                              resource_id=str(session["device_id"]), metadata={"session_id": sid})
            client = WebSocketClient(websocket)
            try:
                login = await _login(conn, str(session["device_id"]), auto_auth=session["auto_auth"], actor=actor)
                shell = await factory.connect(str(device["management_ip"]), **login)
            except ShellUnavailable as exc:
                reason = f"not opened: {exc}"
                await client.send(f"[{reason}]\r\n")
                await broker.close(conn, sid, reason)
                await websocket.close(code=UNAVAILABLE)
                return
            reason = await run_session(client, shell, broker.Recorder(conn, sid),
                                       banner=broker.banner(session["device_name"], sid, actor.username),
                                       idle_seconds=settings.console_idle_seconds, max_seconds=settings.console_max_seconds)
            await broker.close(conn, sid, reason)
            await write_audit(conn, action="console.closed", actor_user_id=actor.id, resource_type="device",
                              resource_id=str(session["device_id"]), metadata={"session_id": sid, "reason": reason})
            logger.info("console session %s closed: %s", sid, reason)
            try:
                await client.send(ended_message(reason))
            except Exception:  # noqa: BLE001 - the user may already be gone
                pass
        try:
            await websocket.close()
        except RuntimeError:
            pass  # already closed by the client

    return app
