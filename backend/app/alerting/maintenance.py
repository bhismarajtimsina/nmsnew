"""Maintenance windows, the system side: is a device under one right now, and releasing what they held back.

A window covers a device directly or through a device group, including every group below it. While a device is
covered, a new event on it is still recorded but marked `suppressed_by_maintenance`, and the notification pipeline
queues nothing for it - neither the alert nor, since nobody was alerted, the resolved notification.

When the window ends or is canceled, `release_suppressed` hands every suppressed event that is **still open** to
the pipeline as a normal alert: whatever the work broke must not stay silent because it started inside a window.
An event that opened and closed inside the window stays quiet; the flag remains on it as history.

These functions act for the system, not for a user, so they apply no user scope; the API that creates and lists
windows (app/repositories/maintenance.py) does.
"""
from __future__ import annotations

import asyncpg

from app.notifications.pipeline import queue_for_event

# $1 = device id. True when a window that has started, has not ended and was not canceled covers the device.
_COVERED = """
with recursive device_groups_up(id, parent_id) as (
    select g.id, g.parent_id from devices d join device_groups g on g.id = d.group_id where d.id = $1::uuid
    union
    select g.id, g.parent_id from device_groups g join device_groups_up up on g.id = up.parent_id
)
select exists(
    select 1 from maintenance_windows w
    where w.canceled_at is null and w.starts_at <= now() and now() < w.ends_at
      and (w.device_id = $1::uuid or w.device_group_id in (select id from device_groups_up))
)
"""


async def device_in_maintenance(conn: asyncpg.Connection, device_id: str | None) -> bool:
    if device_id is None:
        return False
    return await conn.fetchval(_COVERED, device_id)


async def release_suppressed(conn: asyncpg.Connection) -> int:
    """Every open, suppressed event whose device is no longer covered by any active window gets its notifications
    now. Returns how many events were released. Safe to run as often as needed: a released event is no longer
    suppressed, so it is never released twice."""
    rows = await conn.fetch(
        "select id, device_id from events where suppressed_by_maintenance and resolved_at is null"
    )
    released = 0
    for row in rows:
        device_id = str(row["device_id"]) if row["device_id"] else None
        if await device_in_maintenance(conn, device_id):
            continue
        async with conn.transaction():
            status = await conn.execute(
                "update events set suppressed_by_maintenance = false where id = $1 and suppressed_by_maintenance",
                row["id"],
            )
            if status.endswith(" 0"):
                continue
            await queue_for_event(conn, str(row["id"]))
        released += 1
    return released
