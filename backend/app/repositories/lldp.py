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


def _hexed(value: str) -> str:
    # Compared as bare lower-case hex, the canonical macaddr text without its colons.
    return "".join(ch for ch in value.lower() if ch in "0123456789abcdef")


async def _owners(conn: asyncpg.Connection, user: CurrentUser, rows: list[asyncpg.Record]) -> tuple[dict[str, Any], dict[str, list[Any]]]:
    """Devices the caller may see that the rows' chassis MACs or system names could point at."""
    macs = sorted({_hexed(r["chassis_id"]) for r in rows if r["chassis_subtype"] == "macAddress" and r["chassis_id"]})
    names = sorted({r["system_name"].lower() for r in rows if r["system_name"]})
    # A device has no MAC of its own here; a switch's chassis MAC shows up as the MAC of its interfaces (often all of them).
    # interfaces.mac_address is a macaddr, which PostgreSQL stores in one canonical form whatever notation it was given.
    by_mac_rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select distinct d.id, d.name, replace(i.mac_address::text, ':', '') as mac "
        f"from devices d join interfaces i on i.device_id = d.id "
        f"where {DEVICE_VISIBLE} and replace(i.mac_address::text, ':', '') = any($3::text[])",
        user.id, user.scope_all, macs,
    )
    by_name_rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select d.id, d.name, lower(d.name) as lname from devices d where {DEVICE_VISIBLE} and lower(d.name) = any($3::text[])",
        user.id, user.scope_all, names,
    )
    owners: dict[str, set[str]] = {}
    for c in by_mac_rows:
        owners.setdefault(c["mac"], set()).add(str(c["id"]))
    # A MAC that belongs to interfaces on more than one device identifies none of them.
    by_mac = {c["mac"]: {"device_id": str(c["id"]), "name": c["name"]} for c in by_mac_rows if len(owners[c["mac"]]) == 1}
    by_name: dict[str, list[Any]] = {}
    for c in by_name_rows:
        by_name.setdefault(c["lname"], []).append({"device_id": str(c["id"]), "name": c["name"]})
    return by_mac, by_name


def match_device(row: Any, by_mac: dict[str, Any], by_name: dict[str, list[Any]]) -> dict[str, Any] | None:
    """The device a neighbour row points at, never the reporting device itself."""
    own = str(row["device_id"])
    if row["chassis_subtype"] == "macAddress" and row["chassis_id"] and _hexed(row["chassis_id"]) in by_mac:
        found = by_mac[_hexed(row["chassis_id"])]
        if found["device_id"] != own:
            return {**found, "matched_by": "chassis_mac"}
    named = [d for d in by_name.get((row["system_name"] or "").lower(), []) if d["device_id"] != own]
    if row["system_name"] and len(named) == 1:
        return {**named[0], "matched_by": "system_name"}
    return None


async def device_neighbours(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> list[dict[str, Any]] | None:
    """The device's neighbours, each with the matching device among those the caller may see (by chassis MAC found on
    exactly one device's interfaces, else by a unique system name), or None for the match. None overall when the device
    itself is not visible."""
    if await device_repo.get_device(conn, user, device_id) is None:
        return None
    rows = await conn.fetch(
        "select n.*, i.name as local_interface from lldp_neighbours n left join interfaces i on i.id = n.local_interface_id "
        "where n.device_id = $1::uuid order by n.local_port_num, n.remote_index", device_id)
    by_mac, by_name = await _owners(conn, user, rows)
    out = []
    for r in rows:
        item = {k: r[k] for k in ("local_port_num", "local_port", "chassis_subtype", "chassis_id", "port_subtype", "port_id",
                                  "port_description", "system_name", "seen_at")}
        item["local_interface_id"] = str(r["local_interface_id"]) if r["local_interface_id"] else None
        item["local_interface"] = r["local_interface"]
        item["remote_device"] = match_device(r, by_mac, by_name)
        item["external_id"] = external_id(str(r["device_id"]), r) if item["remote_device"] is None else None
        out.append(item)
    names = await external_names(conn, [i["external_id"] for i in out if i["external_id"]])
    for item in out:
        item["external_name"] = names.get(item["external_id"]) if item["external_id"] else None
    return out


def external_id(device_id: str, row: Any) -> str | None:
    """legacy's id for a neighbour that is not in the inventory: `ext:<local device id>:<chassis MAC>`; None without a MAC."""
    if row["chassis_subtype"] != "macAddress" or not row["chassis_id"]:
        return None
    raw = _hexed(row["chassis_id"])
    return f"ext:{device_id}:" + ":".join(raw[i:i + 2] for i in range(0, 12, 2)) if len(raw) == 12 else None


async def external_names(conn: asyncpg.Connection, ids: list[str]) -> dict[str, str]:
    """Names for external neighbours; an id embeds its local device, which the caller has already been shown."""
    if not ids:
        return {}
    return {r["id"]: r["name"] for r in await conn.fetch("select id, name from link_external_names where id = any($1::text[])", ids)}


async def set_external_name(conn: asyncpg.Connection, user: CurrentUser, ext_id: str, name: str | None) -> bool:
    """Name (or with None, unname) an external neighbour. Its local device must be inside the caller's scope."""
    device_id = ext_id.split(":")[1]
    if await device_repo.get_device(conn, user, device_id) is None:
        return False
    if name is None:
        await conn.execute("delete from link_external_names where id = $1", ext_id)
    else:
        await conn.execute("insert into link_external_names (id, name) values ($1, $2) "
                           "on conflict (id) do update set name = $2, updated_at = now()", ext_id, name)
    return True


async def suggestion_inputs(conn: asyncpg.Connection, user: CurrentUser, device_id: str | None = None) -> dict[str, Any]:
    """Everything link suggestions need, all inside the caller's scope: the neighbours reported by visible devices (or
    one of them), the device each points at, and the interfaces of those devices."""
    rows = await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select n.* from lldp_neighbours n join devices d on d.id = n.device_id "
        f"where {DEVICE_VISIBLE} and ($3::uuid is null or n.device_id = $3::uuid) order by n.device_id, n.local_port_num, n.remote_index",
        user.id, user.scope_all, device_id,
    )
    by_mac, by_name = await _owners(conn, user, rows)
    matched = [(r, match_device(r, by_mac, by_name)) for r in rows]
    remote_ids = sorted({m["device_id"] for _, m in matched if m})
    interfaces: dict[str, dict[str, str]] = {}
    for r in await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select i.id, i.name, i.device_id from interfaces i join devices d on d.id = i.device_id "
        f"where {DEVICE_VISIBLE} and i.device_id = any($3::uuid[])", user.id, user.scope_all, remote_ids,
    ):
        interfaces.setdefault(str(r["device_id"]), {})[r["name"]] = str(r["id"])
    return {"matched": matched, "interfaces": interfaces}
