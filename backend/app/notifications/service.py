"""The standalone sender loop, ported from `Console/NotificationSenderService.php`'s `service` command: poll for due
notifications, dispatch each one that isn't already in flight, capped at a fixed number running concurrently.

Legacy forks a real OS process per notification (`wca notifications:send-notify <id>`, capped at
`COUNT_PROCS = 20`) because PHP has no cheap concurrency primitive of its own; this uses an `asyncio.Semaphore` at
the same cap instead - same behavior (never more than N in flight, a notification already dispatched is never
dispatched again while it's still running), without spawning a process per message. Each concurrent send gets its
own pooled connection, mirroring how each forked process had its own: two of them running `process_one` on the same
`asyncpg.Connection` at once would corrupt each other's protocol state.
"""
from __future__ import annotations

import asyncio
import logging

import asyncpg

from app.notifications.sender import Channel, get_due, process_one

logger = logging.getLogger("cybersathy.notifications")


async def run_cycle(
    pool: asyncpg.Pool, channel: Channel, *, in_flight: set[str], max_concurrent: int = 20,
    check_previous_message: bool = False, limit: int = 100,
) -> dict[str, int]:
    """One pass: fetch due notifications, skip any already in flight from a previous pass that hasn't finished yet
    (mirrors legacy's own `$processes` map), and process the rest concurrently, capped at `max_concurrent`.
    `in_flight` is owned by the caller and persists across cycles."""
    async with pool.acquire() as conn:
        rows = await get_due(conn, limit=limit)
    todo = [row for row in rows if str(row["id"]) not in in_flight]

    sem = asyncio.Semaphore(max_concurrent)
    outcomes = {"sent": 0, "failed": 0, "canceled": 0}

    async def _one(row: asyncpg.Record) -> None:
        nid = str(row["id"])
        in_flight.add(nid)
        try:
            async with sem, pool.acquire() as conn:
                outcome = await process_one(conn, row, channel, check_previous_message=check_previous_message)
            outcomes[outcome] += 1  # single-threaded asyncio: no lock needed between await points
        finally:
            in_flight.discard(nid)

    await asyncio.gather(*(_one(row) for row in todo))
    return {"found": len(rows), "in_flight_skipped": len(rows) - len(todo), **outcomes}
