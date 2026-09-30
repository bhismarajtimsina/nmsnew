"""Coverage of app/realtime/bus.py against a real Redis (the throwaway instance the rest of the suite already
uses) - `publish` and `run_subscriber` are two separate processes' worth of code in production, so a real
publish/subscribe round trip is the only way to prove the wire format they agree on actually matches."""
from __future__ import annotations

import asyncio

from redis.asyncio import Redis

from app.core.config import settings
from app.realtime.bus import REALTIME_CHANNEL, publish, run_subscriber
from app.realtime.manager import Connection, ConnectionManager


async def test_publish_reaches_a_locally_registered_connection():
    redis = Redis.from_url(settings.redis_dsn)
    manager = ConnectionManager()
    received: list[dict] = []

    async def send(message: dict) -> None:
        received.append(message)

    manager.add(1, Connection(send=send, permissions=frozenset(), scope_all=False, channels={"events.*"}))
    try:
        # The subscriber must be running (and subscribed) before the publish, exactly like production: a message
        # published before a process joins the channel is never redelivered to it.
        stop = asyncio.Event()
        task = asyncio.create_task(run_subscriber(redis, manager, stop=stop))
        await asyncio.sleep(0.2)  # let the SUBSCRIBE actually land before we publish
        try:
            await publish(redis, "events.created", {"id": "42"})
            for _ in range(100):
                if received:
                    break
                await asyncio.sleep(0.05)
        finally:
            stop.set()
            await task
    finally:
        await redis.aclose()

    assert received == [{"type": "event", "channel": "events.created", "data": {"id": "42"}}]


async def test_a_malformed_message_is_dropped_without_stopping_the_subscriber():
    redis = Redis.from_url(settings.redis_dsn)
    manager = ConnectionManager()
    received: list[dict] = []

    async def send(message: dict) -> None:
        received.append(message)

    manager.add(1, Connection(send=send, permissions=frozenset(), scope_all=False, channels={"events.*"}))
    try:
        stop = asyncio.Event()
        task = asyncio.create_task(run_subscriber(redis, manager, stop=stop))
        await asyncio.sleep(0.2)
        try:
            await redis.publish(REALTIME_CHANNEL, "not json at all")
            await redis.publish(REALTIME_CHANNEL, '{"name": "events.created"}')  # missing "data" is fine - .get()
            for _ in range(100):
                if received:
                    break
                await asyncio.sleep(0.05)
        finally:
            stop.set()
            await task
    finally:
        await redis.aclose()

    assert received == [{"type": "event", "channel": "events.created", "data": None}]


async def test_stop_makes_the_subscriber_return():
    redis = Redis.from_url(settings.redis_dsn)
    try:
        manager = ConnectionManager()
        stop = asyncio.Event()
        task = asyncio.create_task(run_subscriber(redis, manager, stop=stop))
        await asyncio.sleep(0.1)
        stop.set()
        await asyncio.wait_for(task, timeout=5)
        assert task.done() and task.exception() is None
    finally:
        await redis.aclose()
