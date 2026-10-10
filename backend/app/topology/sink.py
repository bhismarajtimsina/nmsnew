"""Where polled readings go besides the poll's own record (Plan 27). A poll whose readings include the bdcom.lldp.*
columns replaces that device's stored neighbours; one that includes the interface octet counters adds a counter
sample per known interface, for link utilisation. Readings of any other kind are left alone."""
from __future__ import annotations

import asyncpg

from app.polling.engine import Reading
from app.topology.lldp import LOCAL_COLUMNS, REMOTE_COLUMNS, neighbours
from app.topology.utilization import COUNTER_COLUMNS, samples_from

LLDP_NAMES = set(REMOTE_COLUMNS) | set(LOCAL_COLUMNS)


class ReadingSink:
    def __init__(self, pool: asyncpg.Pool) -> None:
        self.pool = pool

    async def write(self, device_id: str, profile_name: str, readings: list[Reading]) -> None:
        lldp = any(r.name in LLDP_NAMES for r in readings)
        samples = samples_from(readings) if any(r.name in COUNTER_COLUMNS for r in readings) else {}
        if not lldp and not samples:
            return
        from app.repositories.links import system_store_samples
        from app.repositories.lldp import system_replace_neighbours

        async with self.pool.acquire() as conn:
            if lldp:
                await system_replace_neighbours(conn, device_id, neighbours(readings))
            await system_store_samples(conn, device_id, samples)  # nothing to store is a no-op
