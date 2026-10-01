"""Maintenance windows, the user side: scoped listing, creation and cancellation. A caller sees and manages a window
only when it targets a device or a device group inside their own scope. See app/alerting/maintenance.py for what a
window does to events and notifications."""
from __future__ import annotations

from datetime import datetime
from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.device_groups import get_group
from app.repositories.devices import get_device
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE

WINDOW_VISIBLE = f"""(
    $2::boolean
    or w.device_id in (select d.id from devices d where {DEVICE_VISIBLE})
    or w.device_group_id in (select id from granted)
)"""

WINDOW_COLUMNS = """
    w.id, w.device_id, w.device_group_id, w.starts_at, w.ends_at, w.reason, w.created_by_user_id, w.created_at,
    w.canceled_at, w.canceled_by_user_id,
    (w.canceled_at is null and w.starts_at <= now() and now() < w.ends_at) as active
"""


class NotVisible(LookupError):
    """The window, device or group does not exist or is outside the caller's scope. The two are never told apart."""


class Invalid(ValueError):
    pass


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    data = dict(row)
    for key in ("id", "device_id", "device_group_id", "created_by_user_id", "canceled_by_user_id"):
        if data.get(key) is not None:
            data[key] = str(data[key])
    return data


async def list_windows(
    conn: asyncpg.Connection, user: CurrentUser, *, current_only: bool, device_id: str | None, limit: int, offset: int
) -> list[asyncpg.Record]:
    """`current_only`: windows that are active now or have not started yet, and were not canceled."""
    return await conn.fetch(
        f"""{GRANTED_GROUPS_CTE} select {WINDOW_COLUMNS} from maintenance_windows w
        where {WINDOW_VISIBLE}
          and ($3::boolean = false or (w.canceled_at is null and w.ends_at > now()))
          and ($4::uuid is null or w.device_id = $4::uuid)
        order by w.starts_at desc limit $5 offset $6""",
        user.id, user.scope_all, current_only, device_id, limit, offset,
    )


async def get_window(conn: asyncpg.Connection, user: CurrentUser, window_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(
        f"{GRANTED_GROUPS_CTE} select {WINDOW_COLUMNS} from maintenance_windows w where w.id = $3::uuid and {WINDOW_VISIBLE}",
        user.id, user.scope_all, window_id,
    )


async def create_window(
    conn: asyncpg.Connection, user: CurrentUser, *, device_id: str | None, device_group_id: str | None,
    starts_at: datetime, ends_at: datetime, reason: str,
) -> str:
    if (device_id is None) == (device_group_id is None):
        raise Invalid("give exactly one of device_id or device_group_id")
    if device_id is not None and await get_device(conn, user, device_id) is None:
        raise NotVisible("device")
    if device_group_id is not None and await get_group(conn, user, device_group_id) is None:
        raise NotVisible("device group")
    if ends_at <= starts_at:
        raise Invalid("ends_at must be after starts_at")
    if (ends_at - starts_at).total_seconds() > 7 * 86400:
        raise Invalid("a maintenance window may last at most 7 days")
    if await conn.fetchval("select $1::timestamptz <= now()", ends_at):
        raise Invalid("ends_at is already in the past")
    return str(await conn.fetchval(
        "insert into maintenance_windows (device_id, device_group_id, starts_at, ends_at, reason, created_by_user_id) "
        "values ($1::uuid, $2::uuid, $3, $4, $5, $6::uuid) returning id",
        device_id, device_group_id, starts_at, ends_at, reason, user.id,
    ))


async def cancel_window(conn: asyncpg.Connection, user: CurrentUser, window_id: str) -> bool:
    """Ends a window now, or drops one that has not started. False when it was already canceled or already over."""
    if await get_window(conn, user, window_id) is None:
        raise NotVisible("maintenance window")
    status = await conn.execute(
        "update maintenance_windows set canceled_at = now(), canceled_by_user_id = $2::uuid "
        "where id = $1::uuid and canceled_at is null and ends_at > now()",
        window_id, user.id,
    )
    return not status.endswith(" 0")
