"""Queries about what has been assigned to a user, and whether scope targets exist."""
from __future__ import annotations

import asyncpg

_TARGET_TABLES = {"device_groups": "device_groups", "devices": "devices", "interfaces": "interfaces"}


async def count_existing(conn: asyncpg.Connection, target: str, ids: list[str]) -> int:
    """How many of `ids` exist in `target`. `target` is a fixed vocabulary, never caller-supplied text."""
    table = _TARGET_TABLES[target]
    return await conn.fetchval(f"select count(*) from {table} where id = any($1::uuid[])", ids)


async def assigned_scope(conn: asyncpg.Connection, user_id: str) -> dict[str, list[dict[str, str | None]]]:
    groups = await conn.fetch(
        """select dgs.device_group_id as id, dg.name, dgs.scope_level from user_device_group_scopes dgs
           join device_groups dg on dg.id = dgs.device_group_id where dgs.user_id = $1::uuid order by dg.name""", user_id)
    devices = await conn.fetch(
        """select ds.device_id as id, d.name, ds.scope_level from user_device_scopes ds
           join devices d on d.id = ds.device_id where ds.user_id = $1::uuid order by d.name""", user_id)
    interfaces = await conn.fetch(
        """select ins.interface_id as id, i.name, ins.scope_level from user_interface_scopes ins
           join interfaces i on i.id = ins.interface_id where ins.user_id = $1::uuid order by i.name""", user_id)
    shape = lambda rows: [{"id": str(r["id"]), "name": r["name"], "scope_level": r["scope_level"]} for r in rows]  # noqa: E731
    return {"device_groups": shape(groups), "devices": shape(devices), "interfaces": shape(interfaces)}
