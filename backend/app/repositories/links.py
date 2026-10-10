"""Topology links (Plan 27), scoped by their ends.

A caller sees a link when at least one end is a device they may see. Each row says which ends are visible; the
service masks the others, so a reseller sees that their uplink goes somewhere, and whether it is up, but not to which
device outside their scope. Writes need both ends visible.
"""
from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories import devices as device_repo
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE
from app.topology.utilization import counter_rate, link_utilization

MAX_LINKS = 5000

_END = """
    l.{end}_device_id as {end}_device_id, l.{end}_device_id in (select id from visible) as {end}_visible,
    {a}d.name as {end}_name, host({a}d.management_ip) as {end}_ip, {a}p.status as {end}_ping,
    l.{end}_interface_id as {end}_interface_id, {a}i.name as {end}_interface, {a}i.oper_status as {end}_oper,
    {a}i.admin_status as {end}_admin
"""

def _select(visible: str) -> str:
    """Links with at least one end the caller may see. Each function passes DEVICE_VISIBLE itself, so the scope guard
    (tests/test_route_guard.py) sees the predicate where the query runs."""
    return _SELECT.replace("{visible}", visible)


_SELECT = f"""
    {GRANTED_GROUPS_CTE}, visible as (select d.id from devices d where {{visible}})
    select l.id, l.source, l.description, l.created_at, l.updated_at,
           {_END.format(end="src", a="s")}, {_END.format(end="dest", a="t")}
    from links l
    join devices sd on sd.id = l.src_device_id
    join devices td on td.id = l.dest_device_id
    left join interfaces si on si.id = l.src_interface_id
    left join interfaces ti on ti.id = l.dest_interface_id
    left join device_ping_status sp on sp.device_id = l.src_device_id
    left join device_ping_status tp on tp.device_id = l.dest_device_id
    where (l.src_device_id in (select id from visible) or l.dest_device_id in (select id from visible))
"""


async def list_links(conn: asyncpg.Connection, user: CurrentUser, *, device_id: str | None = None,
                     limit: int = MAX_LINKS) -> list[asyncpg.Record]:
    return await conn.fetch(
        _select(DEVICE_VISIBLE) + " and ($3::uuid is null or $3::uuid in (l.src_device_id, l.dest_device_id)) order by sd.name, td.name, l.id limit $4",
        user.id, user.scope_all, device_id, min(limit, MAX_LINKS),
    )


async def get_link(conn: asyncpg.Connection, user: CurrentUser, link_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(_select(DEVICE_VISIBLE) + " and l.id = $3::uuid", user.id, user.scope_all, link_id)


class LinkRejected(ValueError):
    pass


async def _check_end(conn: asyncpg.Connection, user: CurrentUser, device_id: str, interface_id: str | None, which: str) -> None:
    if await device_repo.get_device(conn, user, device_id) is None:
        raise LookupError(f"the {which} device does not exist or is outside your scope")
    if interface_id is not None and not await conn.fetchval(
        "select exists(select 1 from interfaces where id = $1::uuid and device_id = $2::uuid)", interface_id, device_id
    ):
        raise LinkRejected(f"the {which} interface does not belong to the {which} device")


async def create_link(conn: asyncpg.Connection, user: CurrentUser, data: dict[str, Any]) -> str:
    """Both ends must be devices the caller may see; each interface must belong to its device."""
    await _check_end(conn, user, data["src_device_id"], data.get("src_interface_id"), "source")
    await _check_end(conn, user, data["dest_device_id"], data.get("dest_interface_id"), "destination")
    if data.get("src_interface_id") and data.get("src_interface_id") == data.get("dest_interface_id"):
        raise LinkRejected("a link cannot join an interface to itself")
    return str(await conn.fetchval(
        "insert into links (src_device_id, src_interface_id, dest_device_id, dest_interface_id, source, description, created_by) "
        "values ($1::uuid, $2::uuid, $3::uuid, $4::uuid, $5, $6, $7::uuid) returning id",
        data["src_device_id"], data.get("src_interface_id"), data["dest_device_id"], data.get("dest_interface_id"),
        data.get("source", "manual"), data.get("description"), user.id,
    ))


async def editable_link(conn: asyncpg.Connection, user: CurrentUser, link_id: str) -> asyncpg.Record | None:
    """The link, only when the caller may see both of its ends: editing or deleting needs full scope."""
    row = await get_link(conn, user, link_id)
    return row if row is not None and row["src_visible"] and row["dest_visible"] else None


async def update_link(conn: asyncpg.Connection, user: CurrentUser, link_id: str, changes: dict[str, Any]) -> None:
    row = await editable_link(conn, user, link_id)
    if row is None:
        raise LookupError("Not found")
    for end, which in (("src", "source"), ("dest", "destination")):
        key = f"{end}_interface_id"
        if key in changes:
            await _check_end(conn, user, str(row[f"{end}_device_id"]), changes[key], which)
    src = changes.get("src_interface_id", row["src_interface_id"])
    if src is not None and str(src) == str(changes.get("dest_interface_id", row["dest_interface_id"]) or ""):
        raise LinkRejected("a link cannot join an interface to itself")
    for column in ("src_interface_id", "dest_interface_id", "description"):
        if column in changes:
            await conn.execute(f"update links set {column} = $2, updated_at = now() where id = $1::uuid", link_id, changes[column])


async def delete_link(conn: asyncpg.Connection, user: CurrentUser, link_id: str) -> bool:
    row = await editable_link(conn, user, link_id)
    if row is None:
        return False
    await conn.execute("delete from links where id = $1::uuid", link_id)
    return True


# Link utilisation (app/topology/utilization.py): counter samples in, rates per linked interface out.

_SYSTEM_SELECT = "with visible as (select id from devices)" + _SELECT[_SELECT.index("\n    select l.id"):]


async def system_links(conn: asyncpg.Connection) -> list[asyncpg.Record]:
    """Every link with both ends in full (no caller, so nothing is masked): for the metrics export."""
    return await conn.fetch(_SYSTEM_SELECT + " order by l.id limit $1", MAX_LINKS)


async def system_store_samples(conn: asyncpg.Connection, device_id: str, samples: dict[int, dict[str, int | None]]) -> int:
    """One counter sample per polled interface the inventory knows (by ifIndex); others are skipped. Returns how many."""
    indexes = sorted(samples)
    rows = await conn.fetch(
        """
        insert into interface_counter_samples (interface_id, in_octets, out_octets, speed_bps)
        select i.id, s.in_octets, s.out_octets, s.speed_bps
        from unnest($2::int[], $3::bigint[], $4::bigint[], $5::bigint[]) as s(if_index, in_octets, out_octets, speed_bps)
        join interfaces i on i.device_id = $1::uuid and i.if_index = s.if_index
        on conflict do nothing
        returning interface_id
        """,
        device_id, indexes, [samples[i]["in"] for i in indexes], [samples[i]["out"] for i in indexes],
        [samples[i]["speed"] for i in indexes],
    )
    return len(rows)


async def interface_rates(conn: asyncpg.Connection, interface_ids: list[str], minutes: int) -> dict[str, dict[str, Any]]:
    """In and out bits per second over the last `minutes` for each interface, and its speed: the latest sampled one,
    else the inventory's. Only ever given interface ids taken from rows the caller already may see (or from the
    system export), so it applies no scope of its own."""
    if not interface_ids:
        return {}
    rows = await conn.fetch(
        """
        select s.interface_id::text as interface_id, extract(epoch from s.sampled_at)::float8 as t,
               s.in_octets, s.out_octets, s.speed_bps, i.speed_bps as inventory_speed
        from interface_counter_samples s join interfaces i on i.id = s.interface_id
        where s.interface_id = any($1::uuid[]) and s.sampled_at > now() - make_interval(mins => $2)
        order by s.interface_id, s.sampled_at
        """,
        interface_ids, minutes,
    )
    by_interface: dict[str, list[asyncpg.Record]] = {}
    for r in rows:
        by_interface.setdefault(r["interface_id"], []).append(r)
    out: dict[str, dict[str, Any]] = {}
    for interface_id, samples in by_interface.items():
        speeds = [s["speed_bps"] for s in samples if s["speed_bps"]]
        out[interface_id] = {
            "in_bps": counter_rate([(s["t"], s["in_octets"]) for s in samples if s["in_octets"] is not None]),
            "out_bps": counter_rate([(s["t"], s["out_octets"]) for s in samples if s["out_octets"] is not None]),
            "speed_bps": speeds[-1] if speeds else samples[-1]["inventory_speed"],
        }
    return out


async def utilization_of(conn: asyncpg.Connection, rows: list[asyncpg.Record], minutes: int, *,
                         visible_only: bool) -> dict[str, dict[str, Any] | None]:
    """Each link's utilisation from the interfaces at its ends. With visible_only, an end outside the caller's scope
    is not measured: the caller's own end carries the same traffic, and nothing is read from a device they may not
    see."""
    def ends(row: asyncpg.Record) -> list[tuple[str, str]]:
        return [(side, str(row[f"{side}_interface_id"])) for side in ("src", "dest")
                if row[f"{side}_interface_id"] is not None and (row[f"{side}_visible"] or not visible_only)]

    rates = await interface_rates(conn, sorted({i for row in rows for _, i in ends(row)}), minutes)
    return {str(row["id"]): link_utilization([{"side": side, **rates[i]} for side, i in ends(row) if i in rates])
            for row in rows}
