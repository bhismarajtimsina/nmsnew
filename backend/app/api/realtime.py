"""The realtime WebSocket endpoint, ported from `WebSocketServerCommand`'s open/message handlers - see
app/realtime/permissions.py and app/realtime/manager.py for the pieces this wires together.

Auth is a `?ticket=...` query parameter: a short-lived, single-use ticket from `POST /api/v1/realtime/ticket`
(app/realtime/tickets.py), so no session token is ever written into a URL, where proxies and access logs would keep
it. Not a cookie: a WebSocket handshake is a cross-origin-capable request, so it gets the same "cookies are
read-only, present a real credential" treatment `authenticate()` gives every other route. Legacy's `/ws?token=...`
(the session token in the URL) is deliberately not accepted; any compatibility shim for it is Plan 41's decision.
"""
from __future__ import annotations

import json
import logging

from typing import Annotated

from fastapi import APIRouter, Depends, Request, WebSocket, WebSocketDisconnect

from app.core.config import settings
from app.core.security import CurrentUser, authenticate_credential, get_current_user
from app.realtime import tickets
from app.realtime.manager import Connection
from app.realtime.permissions import CHANNEL_RULES, is_subscribe_allowed

router = APIRouter()
logger = logging.getLogger("cybersathy.realtime")


def _identity(user: CurrentUser) -> dict:
    return {"id": user.id, "username": user.username, "role": user.role, "scope_all": user.scope_all}


@router.post(f"{settings.api_prefix}/realtime/ticket")
async def mint_ticket(request: Request, user: Annotated[CurrentUser, Depends(get_current_user)]) -> dict:
    """A ticket for one `/ws?ticket=...` handshake within the next few seconds. Needs a Bearer credential: the
    session cookie is read-only, so a cross-site page cannot mint one with it."""
    ticket = await tickets.mint(request.app.state.redis, user)
    return {"ticket": ticket, "expires_in": tickets.TICKET_TTL_SECONDS}


@router.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket) -> None:
    record = await tickets.redeem(websocket.app.state.redis, websocket.query_params.get("ticket", ""))
    if record is None:
        await websocket.close(code=4401, reason="unauthorized")
        return

    async with websocket.app.state.pool.acquire() as conn:
        try:
            user = await authenticate_credential(
                conn, record["kind"], record["credential_id"],
                ip=websocket.client.host if websocket.client else None, source="ticket",
            )
        except Exception:  # noqa: BLE001 - any auth failure (revoked, expired, disabled user, address) is "unauthorized"
            await websocket.close(code=4401, reason="unauthorized")
            return

    await websocket.accept()
    manager = websocket.app.state.realtime_manager
    key = id(websocket)
    manager.add(key, Connection(send=websocket.send_json, permissions=user.permissions, scope_all=user.scope_all))
    await websocket.send_json({"type": "ready", "user": _identity(user)})
    logger.info("realtime connection open user=%s", user.id)

    try:
        while True:
            raw = await websocket.receive_text()
            try:
                payload = json.loads(raw)
            except ValueError:
                payload = {}
            action = payload.get("action", "")

            if action == "ping":
                await websocket.send_json({"type": "pong"})
            elif action == "subscribe":
                channel = str(payload.get("channel", "")).strip()
                if channel and is_subscribe_allowed(CHANNEL_RULES, channel=channel, user_permissions=user.permissions, scope_all=user.scope_all):
                    manager.get(key).channels.add(channel)
                    await websocket.send_json({"type": "subscribed", "channel": channel})
                else:
                    await websocket.send_json({"type": "error", "error": "forbidden", "description": "Your role does not have permission to subscribe to this channel."})
            elif action == "unsubscribe":
                channel = str(payload.get("channel", "")).strip()
                conn_state = manager.get(key)
                if conn_state is not None:
                    conn_state.channels.discard(channel)
                await websocket.send_json({"type": "unsubscribed", "channel": channel})
            else:
                await websocket.send_json({"type": "error", "error": "unsupported_action"})
    except WebSocketDisconnect:
        pass
    finally:
        manager.remove(key)
        logger.info("realtime connection closed user=%s", user.id)
