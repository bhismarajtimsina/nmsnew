from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.scope import GRANTED_GROUPS_CTE

GROUP_VISIBLE = "($2::boolean or g.id in (select id from granted))"
GROUP_COLUMNS = """
    g.id, g.parent_id, g.name, g.description, g.legacy_id, g.created_at,
    (select count(*) from devices d where d.group_id = g.id) as devices
"""


class NotVisible(LookupError):
    """The object does not exist or is outside the caller's scope. The two are never told apart."""


class Conflict(ValueError):
    pass


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    return {k: (str(v) if k in {"id", "parent_id"} and v is not None else v) for k, v in dict(row).items()}


async def list_groups(conn: asyncpg.Connection, user: CurrentUser) -> list[asyncpg.Record]:
    return await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select {GROUP_COLUMNS} from device_groups g where {GROUP_VISIBLE} order by g.name, g.id",
        user.id, user.scope_all,
    )


async def get_group(conn: asyncpg.Connection, user: CurrentUser, group_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(
        f"{GRANTED_GROUPS_CTE} select {GROUP_COLUMNS} from device_groups g where g.id = $3::uuid and {GROUP_VISIBLE}",
        user.id, user.scope_all, group_id,
    )


async def _descendants(conn: asyncpg.Connection, group_id: str) -> set[str]:
    rows = await conn.fetch(
        """
        with recursive tree(id) as (
            select id from device_groups where id = $1::uuid
            union select g.id from device_groups g join tree on g.parent_id = tree.id
        ) select id from tree
        """,
        group_id,
    )
    return {str(r["id"]) for r in rows}


async def create_group(conn: asyncpg.Connection, user: CurrentUser, name: str, parent_id: str | None, description: str | None) -> str:
    if parent_id is not None:
        if await get_group(conn, user, parent_id) is None:
            raise NotVisible("parent group")
    elif not user.scope_all:
        raise Conflict("a restricted user can only create a group inside a group they were given")
    if await conn.fetchval("select exists(select 1 from device_groups where name = $1 and parent_id is not distinct from $2::uuid)", name, parent_id):
        raise Conflict("a group with this name already exists here")
    return str(await conn.fetchval(
        "insert into device_groups (name, parent_id, description) values ($1, $2::uuid, $3) returning id", name, parent_id, description))


async def update_group(conn: asyncpg.Connection, user: CurrentUser, group_id: str, changes: dict[str, Any]) -> None:
    if await get_group(conn, user, group_id) is None:
        raise NotVisible("group")
    if "parent_id" in changes:
        parent = changes["parent_id"]
        if parent is not None:
            if await get_group(conn, user, parent) is None:
                raise NotVisible("parent group")
            if parent in await _descendants(conn, group_id):
                raise Conflict("a group cannot be moved inside itself or one of its own subgroups")
        elif not user.scope_all:
            raise Conflict("a restricted user cannot move a group to the top level")
        await conn.execute("update device_groups set parent_id = $2::uuid where id = $1::uuid", group_id, parent)
    for column in ("name", "description"):
        if column in changes:
            await conn.execute(f"update device_groups set {column} = $2 where id = $1::uuid", group_id, changes[column])


async def delete_group(conn: asyncpg.Connection, user: CurrentUser, group_id: str) -> None:
    if await get_group(conn, user, group_id) is None:
        raise NotVisible("group")
    if await conn.fetchval("select exists(select 1 from device_groups where parent_id = $1::uuid)", group_id):
        raise Conflict("the group has subgroups")
    if await conn.fetchval("select exists(select 1 from devices where group_id = $1::uuid)", group_id):
        raise Conflict("the group still has devices")
    await conn.execute("delete from device_groups where id = $1::uuid", group_id)
