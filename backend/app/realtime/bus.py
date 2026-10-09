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


ACTIONS_FINISHED = "actions.finished"


async def notify_action_finished(redis: Redis, confirmation_id: str) -> None:
    """Tells the requester's open dialog that a queued device action has run, so it fetches the results now instead of
    on its next poll.

    Carries only the confirmation id. Dispatch is not per user, so every `dangerous_actions.execute` holder with the
    channel open receives it, but the id gives them nothing: GET /actions/results answers 404 to anyone but the
    requester. Never raises: a missed notice only means the dialog waits for its next poll."""
    try:
        await publish(redis, ACTIONS_FINISHED, {"confirmation_id": confirmation_id})
    except Exception:  # noqa: BLE001 - Redis being down must not fail an action that already ran
        logger.warning("could not publish %s (%s)", ACTIONS_FINISHED, confirmation_id, exc_info=True)


DIAGNOSTICS_FINISHED = "diagnostics.finished"


async def notify_diagnostic_finished(redis: Redis, request_id: str) -> None:
    """Tells the requester's page its diagnostic has a result. Only the request id, for the same reason as
    `notify_action_finished`: the result itself is answered to the requester alone. Never raises."""
    try:
        await publish(redis, DIAGNOSTICS_FINISHED, {"request_id": request_id})
    except Exception:  # noqa: BLE001 - Redis being down must not fail a probe that already ran
        logger.warning("could not publish %s (%s)", DIAGNOSTICS_FINISHED, request_id, exc_info=True)


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
