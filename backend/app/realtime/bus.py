"""Redis pub/sub fan-out, ported from `WebSocketServerCommand::startRedisWorker`: every process that accepts
WebSocket connections subscribes to the same Redis channel; a message published to it reaches every such process,
each of which dispatches it to its own locally connected clients (app/realtime/manager.py) via `ConnectionManager`.
"""
from __future__ import annotations

import asyncio
import json
import logging

from redis.asyncio import Redis

from app.realtime.manager import ConnectionManager

logger = logging.getLogger("cybersathy.realtime")

REALTIME_CHANNEL = "cybersathy:realtime"


async def publish(redis: Redis, name: str, data: dict) -> None:
    """Publishes one realtime event. `name` is the fully-qualified channel it belongs to (e.g. "events.created")."""
    await redis.publish(REALTIME_CHANNEL, json.dumps({"name": name, "data": data}))


DEVICES_CHANGED = "devices.changed"


async def notify_devices_changed(redis: Redis, action: str) -> None:
    """Tells subscribed pages that the device list changed, so they reload it through the API.

    Deliberately carries no device data. `ConnectionManager.dispatch` does not apply device scope, so a record here
    would reach every `devices.view` holder, including resellers who must not see that device. The reload goes
    through the scoped API, which shows each user only what they may see. Called after the change has committed, and
    never fails the request that made it: a missed notification only means a page waits for its next reload."""
    try:
        await publish(redis, DEVICES_CHANGED, {"action": action})
    except Exception:  # noqa: BLE001 - Redis being down must not undo a committed change for the caller
        logger.warning("could not publish %s (%s)", DEVICES_CHANGED, action, exc_info=True)


async def run_subscriber(redis: Redis, manager: ConnectionManager, *, stop: asyncio.Event) -> None:
    """Runs until `stop` is set. One of these runs per process alongside its WebSocket connections - started at
    application startup, not per connection."""
    pubsub = redis.pubsub()
    await pubsub.subscribe(REALTIME_CHANNEL)
    try:
        while not stop.is_set():
            message = await pubsub.get_message(ignore_subscribe_messages=True, timeout=1.0)
            if message is None:
                continue
            try:
                payload = json.loads(message["data"])
                name, data = payload["name"], payload.get("data")
            except (KeyError, TypeError, ValueError):
                logger.warning("dropped a malformed realtime message: %r", message.get("data"))
                continue
            await manager.dispatch(name, data)
    finally:
        await pubsub.unsubscribe(REALTIME_CHANNEL)
        await pubsub.aclose()
