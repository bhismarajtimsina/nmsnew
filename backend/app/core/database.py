from __future__ import annotations

from typing import AsyncIterator

import asyncpg
from fastapi import Request

from app.core.config import settings


async def create_pool() -> asyncpg.Pool:
    return await asyncpg.create_pool(
        dsn=settings.postgres_dsn,
        min_size=settings.postgres_pool_min,
        max_size=settings.postgres_pool_max,
        command_timeout=30,
    )


async def get_conn(request: Request) -> AsyncIterator[asyncpg.Connection]:
    """One pooled connection per request. FastAPI caches dependencies, so every dependency and the handler share it."""
    async with request.app.state.pool.acquire() as conn:
        yield conn


async def check_postgres(pool: asyncpg.Pool) -> dict[str, str]:
    async with pool.acquire() as conn:
        value = await conn.fetchval("select 1")
    return {"status": "ok" if value == 1 else "error"}
