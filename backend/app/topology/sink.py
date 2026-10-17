"""Where polled readings go besides the poll's own record (Plans 27 and 10). A poll whose readings include the
bdcom.lldp.* columns replaces that device's stored neighbours; one that includes the interface table updates the
device's interfaces (app/services/interface_poll.py) and then adds a counter sample per interface, for link
utilisation. The inventory goes first, so an interface new on this poll gets its first sample too. Readings of any
other kind are left alone."""
from __future__ import annotations

import asyncpg

from app.polling.engine import Reading
from app.services.interface_poll import rows_from
from app.topology.lldp import LOCAL_COLUMNS, REMOTE_COLUMNS, neighbours
from app.topology.utilization import samples_from

LLDP_NAMES = set(REMOTE_COLUMNS) | set(LOCAL_COLUMNS)


class ReadingSink:
    def __init__(self, pool: asyncpg.Pool) -> None:
        self.pool = pool

    async def write(self, device_id: str, profile_name: str, readings: list[Reading]) -> None:
        lldp = any(r.name in LLDP_NAMES for r in readings)
        interfaces, samples = rows_from(readings), samples_from(readings)
        if not lldp and not interfaces and not samples:
            return
        from app.repositories.links import system_store_samples
        from app.repositories.lldp import system_replace_neighbours
        from app.services.interface_poll import apply

        async with self.pool.acquire() as conn:
            if lldp:
                await system_replace_neighbours(conn, device_id, neighbours(readings))
            await apply(conn, device_id, interfaces)  # nothing polled is a no-op
            await system_store_samples(conn, device_id, samples)  # nothing to store is a no-op
