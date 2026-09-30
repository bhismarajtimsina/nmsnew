"""The realtime WebSocket endpoint, ported from `WebSocketServerCommand`'s open/message handlers - see
app/realtime/permissions.py and app/realtime/manager.py for the pieces this wires together.

Auth is a `?token=...` query parameter, exactly like the legacy `/ws?token=...` - not a cookie (a WebSocket
handshake is a cross-origin-capable request the same way any other state-affecting call is, so it gets the same
"cookies are read-only, present a real token" treatment `authenticate()` already gives every other route).
"""
from __future__ import annotations

import json
import logging

from fastapi import APIRouter, WebSocket, WebSocketDisconnect

from app.core.security import CurrentUser, authenticate_token
from app.realtime.manager import Connection
from app.realtime.permissions import CHANNEL_RULES, is_subscribe_allowed

router = APIRouter()
logger = logging.getLogger("cybersathy.realtime")


def _identity(user: CurrentUser) -> dict:
    return {"id": user.id, "username": user.username, "role": user.role, "scope_all": user.scope_all}


@router.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket) -> None:
    token = websocket.query_params.get("token", "")
    if not token:
        await websocket.close(code=4401, reason="unauthorized")
        return

    async with websocket.app.state.pool.acquire() as conn:
        try:
            user = await authenticate_token(conn, token, ip=websocket.client.host if websocket.client else None, source="bearer")
        except Exception:  # noqa: BLE001 - any auth failure (bad token, expired, disabled user) is just "unauthorized"
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
