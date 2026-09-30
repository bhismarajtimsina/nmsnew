"""Scoped reads of trap history. A trap on a device the caller cannot see is invisible - the same rule every other
scoped table uses. A trap whose device was since deleted (device_id set null by the FK) is visible only to a caller
whose role sees everything, the same rule an events row with no device already follows (Plan 20)."""
from __future__ import annotations

import json
from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE

TRAP_VISIBLE = f"(d.id is not null and {DEVICE_VISIBLE})"

TRAP_COLUMNS = """
    t.id, t.received_at, host(t.source_ip) as source_ip, t.device_id, d.name as device_name, t.vendor,
    t.trap_name, t.trap_oid, t.version, t.varbinds
"""


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    data = dict(row)
    for key in ("id", "device_id"):
        if data.get(key) is not None:
            data[key] = str(data[key])
    if isinstance(data.get("varbinds"), str):
        data["varbinds"] = json.loads(data["varbinds"])
    return data


async def list_trap_history(
    conn: asyncpg.Connection,
    user: CurrentUser,
    *,
    limit: int,
    offset: int,
    device_id: str | None = None,
    known_only: bool | None = None,
) -> tuple[list[asyncpg.Record], int]:
    where = (
        f"where {TRAP_VISIBLE} and ($3::uuid is null or t.device_id = $3::uuid) "
        "and ($4::boolean is null or (t.trap_profile_id is not null) = $4)"
    )
    join = "left join devices d on d.id = t.device_id"
    args = (user.id, user.scope_all, device_id, known_only)
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select {TRAP_COLUMNS} from trap_history t {join} {where} "
        "order by t.received_at desc limit $5 offset $6",
        *args, limit, offset,
    )
    total = await conn.fetchval(f"{GRANTED_GROUPS_CTE} select count(*) from trap_history t {join} {where}", *args)
    return rows, total


async def list_trap_profiles(conn: asyncpg.Connection) -> list[asyncpg.Record]:
    """The trap definition registry itself: vendor knowledge, not scoped to any device."""
    return await conn.fetch(
        "select id, vendor, name, oid, is_interface, modules, description from trap_profiles order by vendor, name"
    )
