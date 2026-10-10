"""Transport paths (Plan 27). A caller sees a path when both endpoint devices are inside their scope; its hops are
then shown through the link masking (app/topology/links.py), so a hop through a device outside their scope reveals
nothing about that device. Changing a path, or its segments, needs paths.edit and every link involved fully in scope.

`system_*` functions read or write every path for the scheduler's state job and the metrics export, with no caller:
they are listed as unscoped in tests/test_route_guard.py, and nothing that answers a user calls them.
"""
from __future__ import annotations

import json
from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories import devices as device_repo
from app.repositories import links as link_repo
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE

_PATHS = """
    {cte}
    select p.id, p.name, p.group_key, p.priority, p.endpoint_a_id, p.endpoint_b_id, p.enabled, p.description, p.created_at,
           p.updated_at, a.name as endpoint_a_name, b.name as endpoint_b_name, s.state as stored_state, s.last_change,
           s.updated_at as state_updated_at,
           (select count(*) from path_segments ps where ps.path_id = p.id) as segments
    from paths p
    join devices a on a.id = p.endpoint_a_id
    join devices b on b.id = p.endpoint_b_id
    left join path_states s on s.path_id = p.id
"""

_HOP_END = """
    l.{e}_device_id as {e}_device_id, {vis} as {e}_visible, {a}d.name as {e}_name, host({a}d.management_ip) as {e}_ip,
    {a}p.status as {e}_ping, {a}p.latency_ms as {e}_latency, l.{e}_interface_id as {e}_interface_id, {a}i.name as {e}_interface,
    {a}i.oper_status as {e}_oper, {a}i.admin_status as {e}_admin
"""

_HOPS = """
    {cte}
    select ps.path_id, ps.position, ps.link_id as id, l.source, l.description, l.created_at, l.updated_at,
           {src}, {dest}
    from path_segments ps
    join links l on l.id = ps.link_id
    join devices sd on sd.id = l.src_device_id
    join devices td on td.id = l.dest_device_id
    left join interfaces si on si.id = l.src_interface_id
    left join interfaces ti on ti.id = l.dest_interface_id
    left join device_ping_status sp on sp.device_id = l.src_device_id
    left join device_ping_status tp on tp.device_id = l.dest_device_id
    where ps.path_id = ANY($PATHS::uuid[])
    order by ps.path_id, ps.position
"""


def _scoped_paths(visible: str) -> str:
    cte = f"{GRANTED_GROUPS_CTE}, visible as (select d.id from devices d where {visible})"
    return _PATHS.format(cte=cte) + " where p.endpoint_a_id in (select id from visible) and p.endpoint_b_id in (select id from visible)"


def _scoped_hops(visible: str) -> str:
    cte = f"{GRANTED_GROUPS_CTE}, visible as (select d.id from devices d where {visible})"
    src = _HOP_END.format(e="src", a="s", vis="l.src_device_id in (select id from visible)")
    dest = _HOP_END.format(e="dest", a="t", vis="l.dest_device_id in (select id from visible)")
    return _HOPS.format(cte=cte, src=src, dest=dest).replace("$PATHS", "$3")


async def list_paths(conn: asyncpg.Connection, user: CurrentUser) -> list[asyncpg.Record]:
    return await conn.fetch(_scoped_paths(DEVICE_VISIBLE) + " order by p.group_key nulls last, p.priority, p.name",
                            user.id, user.scope_all)


async def get_path(conn: asyncpg.Connection, user: CurrentUser, path_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(_scoped_paths(DEVICE_VISIBLE) + " and p.id = $3::uuid", user.id, user.scope_all, path_id)


async def path_hops(conn: asyncpg.Connection, user: CurrentUser, path_id: str) -> list[asyncpg.Record]:
    """The path's hops with each end's status, for a path `get_path` has shown the caller may see."""
    if await get_path(conn, user, path_id) is None:
        return []
    return await conn.fetch(_scoped_hops(DEVICE_VISIBLE), user.id, user.scope_all, [path_id])


class PathRejected(ValueError):
    pass


async def create_path(conn: asyncpg.Connection, user: CurrentUser, data: dict[str, Any]) -> str:
    for key in ("endpoint_a_id", "endpoint_b_id"):
        if await device_repo.get_device(conn, user, data[key]) is None:
            raise LookupError("an endpoint does not exist or is outside your scope")
    if data["endpoint_a_id"] == data["endpoint_b_id"]:
        raise PathRejected("a path needs two different endpoints")
    return str(await conn.fetchval(
        "insert into paths (name, group_key, priority, endpoint_a_id, endpoint_b_id, enabled, description, created_by) "
        "values ($1, $2, $3, $4::uuid, $5::uuid, $6, $7, $8::uuid) returning id",
        data["name"], data.get("group_key"), data.get("priority", 100), data["endpoint_a_id"], data["endpoint_b_id"],
        data.get("enabled", True), data.get("description"), user.id,
    ))


async def update_path(conn: asyncpg.Connection, user: CurrentUser, path_id: str, changes: dict[str, Any]) -> None:
    if await get_path(conn, user, path_id) is None:
        raise LookupError("Not found")
    for column in ("name", "group_key", "priority", "enabled", "description"):
        if column in changes:
            await conn.execute(f"update paths set {column} = $2, updated_at = now() where id = $1::uuid", path_id, changes[column])


async def delete_path(conn: asyncpg.Connection, user: CurrentUser, path_id: str) -> bool:
    if await get_path(conn, user, path_id) is None:
        return False
    await conn.execute("delete from paths where id = $1::uuid", path_id)
    return True


async def set_segments(conn: asyncpg.Connection, user: CurrentUser, path_id: str, link_ids: list[str]) -> list[tuple[str, str]]:
    """Replace the path's hops. Every link must be fully inside the caller's scope. Returns each link's (src, dest)
    devices in order, for the route check."""
    if await get_path(conn, user, path_id) is None:
        raise LookupError("Not found")
    if len(set(link_ids)) != len(link_ids):
        raise PathRejected("a link can appear only once in a path")
    ends = []
    for link_id in link_ids:
        row = await link_repo.editable_link(conn, user, link_id)
        if row is None:
            raise LookupError("a link does not exist or is not fully inside your scope")
        ends.append((str(row["src_device_id"]), str(row["dest_device_id"])))
    await conn.execute("delete from path_segments where path_id = $1::uuid", path_id)
    for position, link_id in enumerate(link_ids, 1):
        await conn.execute("insert into path_segments (path_id, position, link_id) values ($1::uuid, $2, $3::uuid)",
                           path_id, position, link_id)
    await conn.execute("update paths set updated_at = now() where id = $1::uuid", path_id)
    return ends


# --- system-wide, for the scheduler and the metrics export only ---

async def system_paths(conn: asyncpg.Connection) -> tuple[list[asyncpg.Record], list[asyncpg.Record]]:
    """Every enabled path, and every hop of those paths with full end detail (no caller, so nothing is masked)."""
    paths = await conn.fetch(_PATHS.format(cte="") + " where p.enabled order by p.name")
    src = _HOP_END.format(e="src", a="s", vis="true")
    dest = _HOP_END.format(e="dest", a="t", vis="true")
    hops = await conn.fetch(_HOPS.format(cte="", src=src, dest=dest).replace("$PATHS", "$1"), [p["id"] for p in paths])
    return paths, hops


async def system_save_states(conn: asyncpg.Connection, states: dict[str, tuple[str, dict[str, Any]]]) -> int:
    """Store each enabled path's state; a changed state moves last_change. Disabled paths lose their row, so they export
    no metric and raise no alarm. Returns how many changed."""
    changed = 0
    async with conn.transaction():
        for path_id, (state, detail) in states.items():
            previous = await conn.fetchval("select state from path_states where path_id = $1::uuid", path_id)
            if previous != state:
                changed += 1
            await conn.execute(
                "insert into path_states (path_id, state, last_change, updated_at, detail) values ($1::uuid, $2, now(), now(), $3::jsonb) "
                "on conflict (path_id) do update set state = $2, detail = $3::jsonb, updated_at = now(), "
                "last_change = case when path_states.state is distinct from $2 then now() else path_states.last_change end",
                path_id, state, json.dumps(detail),
            )
        await conn.execute("delete from path_states s using paths p where p.id = s.path_id and not p.enabled")
    return changed


async def system_fresh_states(conn: asyncpg.Connection, ttl_seconds: int) -> list[asyncpg.Record]:
    """States refreshed within the TTL, with the labels the metrics need. A stale state exports nothing, as legacy's
    metric TTL does, so a stopped state job silences alarms rather than freezing them."""
    return await conn.fetch(
        "select p.id, p.name, p.group_key, a.name as endpoint_a_name, b.name as endpoint_b_name, s.state, s.detail "
        "from path_states s join paths p on p.id = s.path_id join devices a on a.id = p.endpoint_a_id "
        "join devices b on b.id = p.endpoint_b_id where p.enabled and s.updated_at > now() - make_interval(secs => $1) order by p.name",
        ttl_seconds,
    )
