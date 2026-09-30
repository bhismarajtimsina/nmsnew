from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.devices import get_device


async def poll_history(conn: asyncpg.Connection, user: CurrentUser, device_id: str, limit: int = 50) -> dict[str, Any] | None:
    """Recent polls and the breaker state of a device the caller may see; None when it is not visible."""
    if await get_device(conn, user, device_id) is None:
        return None
    results = await conn.fetch(
        "select id, profile_name, profile_version, started_at, duration_ms, rows, truncated, outcome, error "
        "from polling_results where device_id = $1::uuid order by started_at desc limit $2", device_id, limit)
    state = await conn.fetchrow(
        "select consecutive_failures, breaker_open_until, breaker_open_until > now() as breaker_open, last_polled_at, last_success_at, last_failure_at, last_error "
        "from device_poll_state where device_id = $1::uuid", device_id)
    return {
        "state": dict(state) if state else None,
        "results": [{**dict(r), "id": str(r["id"])} for r in results],
    }
