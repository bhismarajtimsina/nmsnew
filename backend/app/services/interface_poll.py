"""Interfaces from an interface_basic poll (Plan 10): the RFC 1213 interface table decoded into the inventory.

Not a repository, for the same reason as app/services/interface_status.py: it runs on a device's own poll on behalf of
no signed-in caller. The polling sink (app/topology/sink.py) calls it.

What a poll changes:
- An ifIndex the inventory does not have yet becomes a new interface, named by its ifDescr. A row without an ifDescr
  is not created, since it would have no name.
- A known interface takes the polled name, type, speed and MAC address. A value the poll did not give, or gave in a
  form that cannot be read, leaves the stored one alone; so does a speed at ifSpeed's ceiling (a port faster than the
  column can hold, whose real speed is in ifHighSpeed, not read yet).
- Status goes through app/services/interface_status.record_interface_status, so the history gets one row per real
  change. A poll that read only one of the two statuses keeps the other.
- An interface the poll did not return is left as it is. A walk can be cut at its row cap, and history and marks
  hang off the row, so absence is not taken as removal.
- The alias, the protected flag and any legacy id are never touched.

Values come from RFC1213-MIB.my in this repository: its ifType names (1 to 32) and its status names. RFC 2863 adds
four operational states (unknown, dormant, notPresent, lowerLayerDown) that RFC 1213 lacks; IF-MIB is not in the
repository, so those four are written here from the RFC, under the names app/topology/state.py already reads. Newer
ifType codes (IANAifType-MIB is not in the repository either) are kept as their number rather than a guessed name.
tests/test_interface_poll.py re-reads the MIB file and fails if the names here disagree with it.

Not verified against a device: the build's SNMP transport is the disabled one (D-17).
"""
from __future__ import annotations

from typing import Any, Iterable

import asyncpg

from app.services.interface_status import record_interface_status
from app.topology.utilization import IF_SPEED_MAX

COLUMNS = {
    "interface.if_descr": "name",
    "interface.if_type": "type",
    "interface.if_speed": "speed",
    "interface.if_phys_address": "mac",
    "interface.if_admin_status": "admin",
    "interface.if_oper_status": "oper",
}

# RFC1213-MIB ifType, as the file enumerates it.
IF_TYPES = {
    1: "other", 2: "regular1822", 3: "hdh1822", 4: "ddn-x25", 5: "rfc877-x25", 6: "ethernet-csmacd",
    7: "iso88023-csmacd", 8: "iso88024-tokenBus", 9: "iso88025-tokenRing", 10: "iso88026-man", 11: "starLan",
    12: "proteon-10Mbit", 13: "proteon-80Mbit", 14: "hyperchannel", 15: "fddi", 16: "lapb", 17: "sdlc", 18: "ds1",
    19: "e1", 20: "basicISDN", 21: "primaryISDN", 22: "propPointToPointSerial", 23: "ppp", 24: "softwareLoopback",
    25: "eon", 26: "ethernet-3Mbit", 27: "nsip", 28: "slip", 29: "ultra", 30: "ds3", 31: "sip", 32: "frame-relay",
}
ADMIN = {1: "up", 2: "down", 3: "testing"}  # RFC1213-MIB ifAdminStatus
OPER = {1: "up", 2: "down", 3: "testing",  # RFC1213-MIB ifOperStatus
        4: "unknown", 5: "dormant", 6: "notPresent", 7: "lowerLayerDown"}  # RFC 2863 additions
NAME_MAX = 160  # interfaces.name


def _index(column_oid: str, oid: str) -> int | None:
    prefix = column_oid + "."
    rest = oid[len(prefix):] if oid.startswith(prefix) else ""
    return int(rest) if rest.isdigit() else None


def _int(value: Any) -> int | None:
    return value if isinstance(value, int) and not isinstance(value, bool) else None


def _name(value: Any) -> str | None:
    if isinstance(value, bytes):
        value = value.decode("utf-8", errors="replace")
    if not isinstance(value, str):
        return None
    text = "".join(ch for ch in value if ch.isprintable()).strip()
    return text[:NAME_MAX] or None


def _mac(value: Any) -> str | None:
    if isinstance(value, str):
        hexed = value.lower().removeprefix("0x").replace(":", "").replace("-", "").replace(" ", "")
        try:
            value = bytes.fromhex(hexed)
        except ValueError:
            return None
    if not isinstance(value, bytes) or len(value) != 6 or not any(value):
        return None
    return ":".join(f"{b:02x}" for b in value)


def _decode(key: str, value: Any) -> Any:
    if key == "name":
        return _name(value)
    if key == "mac":
        return _mac(value)
    number = _int(value)
    if number is None:
        return None
    if key == "type":
        return IF_TYPES.get(number, str(number))
    if key == "speed":
        return number if 0 < number < IF_SPEED_MAX else None
    if key == "admin":
        return ADMIN.get(number, "unknown")
    return OPER.get(number, "unknown")


def rows_from(readings: Iterable[Any]) -> dict[int, dict[str, Any]]:
    """What the poll says about each ifIndex: only the values it gave in a readable form."""
    out: dict[int, dict[str, Any]] = {}
    for r in readings:
        key = COLUMNS.get(r.name)
        if key is None or not isinstance(r.value, list):
            continue
        for oid, raw in r.value:
            index, value = _index(r.oid, oid), _decode(key, raw)
            if index is not None and value is not None:
                out.setdefault(index, {})[key] = value
    return out


async def apply(conn: asyncpg.Connection, device_id: str, rows: dict[int, dict[str, Any]]) -> dict[str, int]:
    """Bring the device's interfaces in line with the poll. Returns how many were created, updated and how many
    changed status."""
    counts = {"created": 0, "updated": 0, "status_changed": 0}
    if not rows:
        return counts
    async with conn.transaction():
        existing = {r["if_index"]: r for r in await conn.fetch(
            "select id::text as id, if_index, name, if_type, speed_bps, mac_address::text as mac, admin_status, oper_status "
            "from interfaces where device_id = $1::uuid and if_index = any($2::int[])", device_id, sorted(rows))}
        for index, polled in sorted(rows.items()):
            row = existing.get(index)
            if row is None:
                if "name" not in polled:
                    continue
                interface_id = await conn.fetchval(
                    "insert into interfaces (device_id, if_index, name, if_type, speed_bps, mac_address) "
                    "values ($1::uuid, $2, $3, $4, $5, $6::macaddr) returning id::text",
                    device_id, index, polled["name"], polled.get("type"), polled.get("speed"), polled.get("mac"))
                counts["created"] += 1
                current = {"admin_status": "unknown", "oper_status": "unknown"}
            else:
                interface_id = row["id"]
                wanted = {"name": polled.get("name", row["name"]), "if_type": polled.get("type", row["if_type"]),
                          "speed_bps": polled.get("speed", row["speed_bps"]), "mac": polled.get("mac", row["mac"])}
                if any(wanted[k] != row[k] for k in wanted):
                    await conn.execute(
                        "update interfaces set name = $2, if_type = $3, speed_bps = $4, mac_address = $5::macaddr, "
                        "updated_at = now() where id = $1::uuid",
                        interface_id, wanted["name"], wanted["if_type"], wanted["speed_bps"], wanted["mac"])
                    counts["updated"] += 1
                current = {"admin_status": row["admin_status"], "oper_status": row["oper_status"]}
            if "admin" in polled or "oper" in polled:
                admin, oper = polled.get("admin", current["admin_status"]), polled.get("oper", current["oper_status"])
                if (admin, oper) != (current["admin_status"], current["oper_status"]):
                    await record_interface_status(conn, interface_id, admin, oper)
                    counts["status_changed"] += 1
    return counts
