"""Scoped reads of events and incidents. A restricted user sees only events on a device inside their assigned scope; an
event with no device (a system-wide alarm like `system_not_enough_pollers`) is never visible to a restricted user —
the same rule the legacy system used before this one."""
from __future__ import annotations

from typing import Any

import asyncpg

from app.alerting.incidents import OpenEvent
from app.core.security import CurrentUser
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE

EVENT_VISIBLE = f"(d.id is not null and {DEVICE_VISIBLE})"

EVENT_COLUMNS = """
    e.id, e.occurred_at, e.name, e.dedup_key, e.labels, e.description, e.severity, e.device_id,
    d.name as device_name, host(d.management_ip) as device_ip, e.resolved_at, e.resolved_by_user_id
"""


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    import json

    data = dict(row)
    for key in ("id", "device_id", "resolved_by_user_id"):
        if data.get(key) is not None:
            data[key] = str(data[key])
    if isinstance(data.get("labels"), str):
        data["labels"] = json.loads(data["labels"])
    return data


async def list_events(
    conn: asyncpg.Connection, user: CurrentUser, *, open_only: bool, device_id: str | None, name: str | None, limit: int, offset: int
) -> tuple[list[asyncpg.Record], int]:
    visible = "($2::boolean or true)" if user.scope_all else EVENT_VISIBLE
    join = "left join devices d on d.id = e.device_id"
    where = f"where {visible} and ($3::boolean = false or e.resolved_at is null) and ($4::uuid is null or e.device_id = $4::uuid) and ($5::text is null or e.name = $5)"
    args = (user.id, user.scope_all, open_only, device_id, name)
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select {EVENT_COLUMNS} from events e {join} {where} order by e.occurred_at desc limit $6 offset $7",
        *args, limit, offset,
    )
    total = await conn.fetchval(f"{GRANTED_GROUPS_CTE} select count(*) from events e {join} {where}", *args)
    return rows, total


async def get_event(conn: asyncpg.Connection, user: CurrentUser, event_id: str) -> asyncpg.Record | None:
    visible = "($2::boolean or true)" if user.scope_all else EVENT_VISIBLE
    return await conn.fetchrow(
        f"""{GRANTED_GROUPS_CTE} select {EVENT_COLUMNS} from events e left join devices d on d.id = e.device_id
            where e.id = $3::uuid and {visible}""",
        user.id, user.scope_all, event_id,
    )


class NotVisible(LookupError):
    pass


async def resolve_event(conn: asyncpg.Connection, user: CurrentUser, event_id: str, resolved_by_user_id: str) -> bool:
    if await get_event(conn, user, event_id) is None:
        raise NotVisible("event")
    status = await conn.execute(
        "update events set resolved_at = now(), resolved_by_user_id = $2::uuid where id = $1::uuid and resolved_at is null",
        event_id, resolved_by_user_id,
    )
    return not status.endswith(" 0")


async def open_events_for_grouping(conn: asyncpg.Connection, user: CurrentUser, device_id: str | None, limit: int = 5000) -> list[OpenEvent]:
    visible = "($2::boolean or true)" if user.scope_all else EVENT_VISIBLE
    join = "left join devices d on d.id = e.device_id"
    where = f"where {visible} and e.resolved_at is null and ($3::uuid is null or e.device_id = $3::uuid)"
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select e.id, e.name, e.severity, e.device_id, e.labels, e.occurred_at "
        f"from events e {join} {where} order by e.occurred_at limit $4",
        user.id, user.scope_all, device_id, limit,
    )
    import json

    return [
        OpenEvent(id=str(r["id"]), name=r["name"], severity=r["severity"], device_id=str(r["device_id"]) if r["device_id"] else None,
                 labels=json.loads(r["labels"]) if isinstance(r["labels"], str) else (r["labels"] or {}), occurred_at=r["occurred_at"])
        for r in rows
    ]
