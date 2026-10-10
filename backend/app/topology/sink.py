"""Where polled readings go besides the poll's own record (Plan 27). Only LLDP for now: a poll whose readings include
the bdcom.lldp.* columns replaces that device's stored neighbours. Readings of any other kind are left alone."""
from __future__ import annotations

import asyncpg

from app.polling.engine import Reading
from app.topology.lldp import LOCAL_COLUMNS, REMOTE_COLUMNS, neighbours

LLDP_NAMES = set(REMOTE_COLUMNS) | set(LOCAL_COLUMNS)


class ReadingSink:
    def __init__(self, pool: asyncpg.Pool) -> None:
        self.pool = pool

    async def write(self, device_id: str, profile_name: str, readings: list[Reading]) -> None:
        if not any(r.name in LLDP_NAMES for r in readings):
            return
        from app.repositories.lldp import system_replace_neighbours

        async with self.pool.acquire() as conn:
            await system_replace_neighbours(conn, device_id, neighbours(readings))
