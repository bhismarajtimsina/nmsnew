from __future__ import annotations

from fastapi import Request
from redis.asyncio import Redis

from app.core.config import settings


def create_redis() -> Redis:
    return Redis.from_url(settings.redis_dsn, decode_responses=True)


def get_redis(request: Request) -> Redis:
    return request.app.state.redis


async def check_redis(client: Redis) -> dict[str, str]:
    pong = await client.ping()
    return {"status": "ok" if pong else "error"}
