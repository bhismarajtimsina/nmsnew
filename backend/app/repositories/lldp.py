"""LLDP neighbours (Plan 27). Reads are scoped by the reporting device, and a neighbour is matched to one of our devices
only among the devices the reader may see: a reseller never learns that a neighbour is someone else's device.

`system_replace_neighbours` is written by the polling sink with no caller (listed as unscoped in
tests/test_route_guard.py); nothing that answers a user calls it.
"""
from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories import devices as device_repo
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE
from app.topology.lldp import Neighbour, match_interface


def _cut(value: str | None) -> str | None:
    return value[:255] if value else value


async def system_replace_neighbours(conn: asyncpg.Connection, device_id: str, found: list[Neighbour]) -> int:
    interfaces = {r["name"]: str(r["id"]) for r in await conn.fetch("select id, name from interfaces where device_id = $1::uuid", device_id)}
    async with conn.transaction():
        await conn.execute("delete from lldp_neighbours where device_id = $1::uuid", device_id)
        for n in found:
            await conn.execute(
                "insert into lldp_neighbours (device_id, local_port_num, remote_index, local_interface_id, local_port, chassis_subtype, "
                "chassis_id, port_subtype, port_id, port_description, system_name) "
                "values ($1::uuid, $2, $3, $4::uuid, $5, $6, $7, $8, $9, $10, $11)",
                device_id, n.local_port_num, n.remote_index, match_interface(n.local_port, interfaces), _cut(n.local_port),
                n.chassis_subtype, _cut(n.chassis_id), n.port_subtype, _cut(n.port_id), _cut(n.port_description), _cut(n.system_name),
            )
    return len(found)


async def device_neighbours(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> list[dict[str, Any]] | None:
    """The device's neighbours, each with the matching device among those the caller may see (by chassis MAC found on
    exactly one device's interfaces, else by a unique system name), or None for the match. None overall when the device itself is not visible."""
    if await device_repo.get_device(conn, user, device_id) is None:
        return None
    rows = await conn.fetch(
        "select n.*, i.name as local_interface from lldp_neighbours n left join interfaces i on i.id = n.local_interface_id "
        "where n.device_id = $1::uuid order by n.local_port_num, n.remote_index", device_id)
    # Compared as bare lower-case hex, the canonical macaddr text without its colons.
    hexed = lambda value: "".join(ch for ch in value.lower() if ch in "0123456789abcdef")  # noqa: E731
    macs = sorted({hexed(r["chassis_id"]) for r in rows if r["chassis_subtype"] == "macAddress" and r["chassis_id"]})
    names = sorted({r["system_name"].lower() for r in rows if r["system_name"]})
    # A device has no MAC of its own here; a switch's chassis MAC shows up as the MAC of its interfaces (often all of them).
    # interfaces.mac_address is a macaddr, which PostgreSQL stores in one canonical form whatever notation it was given.
    by_mac_rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select distinct d.id, d.name, replace(i.mac_address::text, ':', '') as mac "
        f"from devices d join interfaces i on i.device_id = d.id "
        f"where {DEVICE_VISIBLE} and d.id <> $3::uuid and replace(i.mac_address::text, ':', '') = any($4::text[])",
        user.id, user.scope_all, device_id, macs,
    )
    by_name_rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select d.id, d.name, lower(d.name) as lname from devices d "
        f"where {DEVICE_VISIBLE} and d.id <> $3::uuid and lower(d.name) = any($4::text[])",
        user.id, user.scope_all, device_id, names,
    )
    owners: dict[str, set[str]] = {}
    for c in by_mac_rows:
        owners.setdefault(c["mac"], set()).add(str(c["id"]))
    # A MAC that belongs to interfaces on more than one device identifies none of them.
    by_mac = {c["mac"]: c for c in by_mac_rows if len(owners[c["mac"]]) == 1}
    by_name: dict[str, list[asyncpg.Record]] = {}
    for c in by_name_rows:
        by_name.setdefault(c["lname"], []).append(c)
    out = []
    for r in rows:
        match, how = None, None
        if r["chassis_subtype"] == "macAddress" and r["chassis_id"] and hexed(r["chassis_id"]) in by_mac:
            match, how = by_mac[hexed(r["chassis_id"])], "chassis_mac"
        elif r["system_name"] and len(by_name.get(r["system_name"].lower(), [])) == 1:
            match, how = by_name[r["system_name"].lower()][0], "system_name"
        item = {k: r[k] for k in ("local_port_num", "local_port", "chassis_subtype", "chassis_id", "port_subtype", "port_id",
                                  "port_description", "system_name", "seen_at")}
        item["local_interface_id"] = str(r["local_interface_id"]) if r["local_interface_id"] else None
        item["local_interface"] = r["local_interface"]
        item["remote_device"] = {"device_id": str(match["id"]), "name": match["name"], "matched_by": how} if match else None
        out.append(item)
    return out
