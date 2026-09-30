from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.device_groups import NotVisible
from app.repositories.scope import GRANTED_GROUPS_CTE, INTERFACE_VISIBLE

INTERFACE_COLUMNS = """
    i.id, i.device_id, d.name as device_name, i.parent_interface_id, i.if_index, i.name, i.alias, i.if_type,
    i.admin_status, i.oper_status, i.speed_bps, i.mac_address::text as mac_address, i.legacy_id, i.created_at, i.updated_at
"""


async def list_interfaces(
    conn: asyncpg.Connection,
    user: CurrentUser,
    *,
    limit: int,
    offset: int,
    device_id: str | None = None,
    oper_status: str | None = None,
    favorite: bool | None = None,
    tag: str | None = None,
) -> tuple[list[asyncpg.Record], int]:
    where = (
        f"where {INTERFACE_VISIBLE} and ($3::uuid is null or i.device_id = $3::uuid) and ($4::text is null or i.oper_status = $4) "
        "and ($5::boolean is null or exists(select 1 from interface_marks m where m.interface_id = i.id) = $5) "
        "and ($6::text is null or exists(select 1 from interface_tags t where t.interface_id = i.id and t.value = $6))"
    )
    args = (user.id, user.scope_all, device_id, oper_status, favorite, tag)
    base = "from interfaces i join devices d on d.id = i.device_id"
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select {INTERFACE_COLUMNS} {base} {where} order by d.name, i.if_index, i.id limit $7 offset $8",
        *args, limit, offset,
    )
    total = await conn.fetchval(f"{GRANTED_GROUPS_CTE} select count(*) {base} {where}", *args)
    return rows, total


async def get_interface(conn: asyncpg.Connection, user: CurrentUser, interface_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(
        f"""{GRANTED_GROUPS_CTE} select {INTERFACE_COLUMNS} from interfaces i join devices d on d.id = i.device_id
            where i.id = $3::uuid and {INTERFACE_VISIBLE}""",
        user.id, user.scope_all, interface_id,
    )


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    return {
        key: (str(value) if key in {"id", "device_id", "parent_interface_id"} and value is not None else value)
        for key, value in dict(row).items()
    }


# Strips exactly the characters the legacy setTags() strips (space, hyphen, quote, comma, hash, semicolon), so a
# tag that survived there survives here identically: str.replace([" ", '-', '"', ",", "#", ';'], '') then trim.
_TAG_STRIP = str.maketrans("", "", ' -",#;')


def _clean_tag(tag: str) -> str:
    return tag.translate(_TAG_STRIP).strip()


async def get_marks(conn: asyncpg.Connection, user: CurrentUser, interface_id: str) -> dict[str, Any]:
    if await get_interface(conn, user, interface_id) is None:
        raise NotVisible("interface")
    favorite = await conn.fetchval("select exists(select 1 from interface_marks where interface_id = $1::uuid)", interface_id)
    tags = [r["value"] for r in await conn.fetch("select value from interface_tags where interface_id = $1::uuid order by value", interface_id)]
    return {"favorite": favorite, "tags": tags}


async def set_favorite(conn: asyncpg.Connection, user: CurrentUser, interface_id: str, favorite: bool) -> None:
    if await get_interface(conn, user, interface_id) is None:
        raise NotVisible("interface")
    if favorite:
        await conn.execute("insert into interface_marks (interface_id) values ($1::uuid) on conflict (interface_id) do nothing", interface_id)
    else:
        await conn.execute("delete from interface_marks where interface_id = $1::uuid", interface_id)


async def set_tags(conn: asyncpg.Connection, user: CurrentUser, interface_id: str, tags: list[str]) -> list[str]:
    if await get_interface(conn, user, interface_id) is None:
        raise NotVisible("interface")
    cleaned = sorted({cleaned for tag in tags if (cleaned := _clean_tag(tag))})
    async with conn.transaction():
        await conn.execute("delete from interface_tags where interface_id = $1::uuid", interface_id)
        if cleaned:
            await conn.executemany(
                "insert into interface_tags (interface_id, value) values ($1::uuid, $2)",
                [(interface_id, tag) for tag in cleaned],
            )
    return cleaned


async def list_known_tags(conn: asyncpg.Connection, user: CurrentUser, *, query: str | None = None, limit: int = 50) -> list[str]:
    """Only tags that appear on at least one interface the caller can see - an autocomplete source, not an admin dump."""
    where = f"where {INTERFACE_VISIBLE} and ($3::text is null or t.value ilike $3 || '%')"
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select distinct t.value from interface_tags t join interfaces i on i.id = t.interface_id "
        f"join devices d on d.id = i.device_id {where} order by t.value limit $4",
        user.id, user.scope_all, query, limit,
    )
    return [r["value"] for r in rows]


async def list_status_history(
    conn: asyncpg.Connection, user: CurrentUser, interface_id: str, *, limit: int, offset: int
) -> tuple[list[asyncpg.Record], int]:
    if await get_interface(conn, user, interface_id) is None:
        raise NotVisible("interface")
    rows = await conn.fetch(
        "select id, changed_at, admin_status, oper_status from interface_status_history "
        "where interface_id = $1::uuid order by changed_at desc limit $2 offset $3",
        interface_id, limit, offset,
    )
    total = await conn.fetchval("select count(*) from interface_status_history where interface_id = $1::uuid", interface_id)
    return rows, total
