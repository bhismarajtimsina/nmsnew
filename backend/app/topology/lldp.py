"""LLDP neighbours from a poll (Plan 27): the bdcom.lldp.* readings of the BDCOM switch profile turned into one record
per neighbour, with each id decoded by its subtype, and the local LLDP port matched to an interface we know.

Row indexes, from the MIB: a neighbour row is `<lldpRemTimeMark>.<lldpRemLocalPortNum>.<lldpRemIndex>`, a local port
row is `<lldpLocPortNum>`. Subtypes are the MIB's LldpChassisIdSubtype and LldpPortIdSubtype enumerations.

Values arrive as the transport returns an OCTET STRING: bytes, or text. A MAC-address id is six octets; a network
address is an IANA address family octet followed by the address. Anything else is shown as text when printable and as
hex when not. Nothing here contacts a device.
"""
from __future__ import annotations

import ipaddress
import re
from dataclasses import dataclass
from typing import Any

from app.polling.engine import Reading

CHASSIS_SUBTYPES = {1: "chassisComponent", 2: "interfaceAlias", 3: "portComponent", 4: "macAddress", 5: "networkAddress",
                    6: "interfaceName", 7: "local"}
PORT_SUBTYPES = {1: "interfaceAlias", 2: "portComponent", 3: "macAddress", 4: "networkAddress", 5: "interfaceName",
                 6: "agentCircuitId", 7: "local"}

# Column roots in the profile (app/registry/bdcom_switch_oids.py).
_REM = "1.3.6.1.4.1.3320.127.1.4.1.1."
_LOC = "1.3.6.1.4.1.3320.127.1.3.7.1."
REMOTE_COLUMNS = {"bdcom.lldp.remote_chassis_id_subtype": "chassis_subtype", "bdcom.lldp.remote_chassis_id": "chassis_id",
                  "bdcom.lldp.remote_port_id_subtype": "port_subtype", "bdcom.lldp.remote_port_id": "port_id",
                  "bdcom.lldp.remote_port_description": "port_description", "bdcom.lldp.remote_system_name": "system_name"}
LOCAL_COLUMNS = {"bdcom.lldp.local_port_id_subtype": "subtype", "bdcom.lldp.local_port_id": "port_id",
                 "bdcom.lldp.local_port_description": "description"}
_HEX = re.compile(r"^(0x)?([0-9a-fA-F]{2}([:\- ]?)){5}[0-9a-fA-F]{2}$")


def _octets(value: Any) -> bytes:
    if isinstance(value, (bytes, bytearray)):
        return bytes(value)
    text = str(value).strip()
    if _HEX.match(text):  # a transport that renders octets as hex ("00:1a:2b:3c:4d:5e" or "0x001a2b3c4d5e")
        return bytes.fromhex(re.sub(r"[^0-9a-fA-F]", "", text[2:] if text.lower().startswith("0x") else text))
    return text.encode("utf-8", "replace")


def mac(value: Any) -> str | None:
    raw = _octets(value)
    return ":".join(f"{b:02x}" for b in raw) if len(raw) == 6 else None


def _text(raw: bytes) -> str:
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        return raw.hex(":")
    return text if text.isprintable() else raw.hex(":")


def decode_id(value: Any, subtype: int | None, *, mac_subtype: int, address_subtype: int) -> str | None:
    if value is None:
        return None
    raw = _octets(value)
    if subtype == mac_subtype:
        return mac(raw) or _text(raw)
    if subtype == address_subtype and len(raw) in (5, 17) and raw[0] in (1, 2):  # IANA family 1 = IPv4, 2 = IPv6
        return str(ipaddress.ip_address(raw[1:]))
    return _text(raw).strip() or None


def _code(value: Any) -> int | None:
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


@dataclass(frozen=True)
class Neighbour:
    local_port_num: int
    remote_index: int
    local_port: str | None
    chassis_subtype: str | None
    chassis_id: str | None
    port_subtype: str | None
    port_id: str | None
    port_description: str | None
    system_name: str | None


def _rows(readings: list[Reading], columns: dict[str, str], root: str, index_parts: int) -> dict[tuple[int, ...], dict[str, Any]]:
    rows: dict[tuple[int, ...], dict[str, Any]] = {}
    for r in readings:
        field = columns.get(r.name)
        if field is None or not r.oid.lstrip(".").startswith(root):
            continue
        parts = r.oid.lstrip(".")[len(root):].split(".")[1:]  # drop the column number
        if len(parts) != index_parts or not all(p.isdigit() for p in parts):
            continue  # a row index that does not fit the MIB is dropped, never guessed at
        rows.setdefault(tuple(int(p) for p in parts), {})[field] = r.value
    return rows


def neighbours(readings: list[Reading]) -> list[Neighbour]:
    local = {idx[0]: row for idx, row in _rows(readings, LOCAL_COLUMNS, _LOC, 1).items()}
    out = []
    for (_, port_num, rem_index), row in sorted(_rows(readings, REMOTE_COLUMNS, _REM, 3).items(), key=lambda kv: kv[0][1:]):
        chassis_sub, port_sub = _code(row.get("chassis_subtype")), _code(row.get("port_subtype"))
        loc = local.get(port_num, {})
        local_port = decode_id(loc.get("port_id"), _code(loc.get("subtype")), mac_subtype=3, address_subtype=4)
        out.append(Neighbour(
            local_port_num=port_num, remote_index=rem_index, local_port=local_port or (loc.get("description") and _text(_octets(loc["description"]))),
            chassis_subtype=CHASSIS_SUBTYPES.get(chassis_sub) if chassis_sub else None,
            chassis_id=decode_id(row.get("chassis_id"), chassis_sub, mac_subtype=4, address_subtype=5),
            port_subtype=PORT_SUBTYPES.get(port_sub) if port_sub else None,
            port_id=decode_id(row.get("port_id"), port_sub, mac_subtype=3, address_subtype=4),
            port_description=_text(_octets(row["port_description"])).strip() or None if row.get("port_description") is not None else None,
            system_name=_text(_octets(row["system_name"])).strip() or None if row.get("system_name") is not None else None,
        ))
    return out


def match_interface(port: str | None, interfaces: dict[str, str]) -> str | None:
    """Our interface for an LLDP port name: exact name first, then a unique case-insensitive match. `interfaces` maps
    name -> id. Never a partial match: "Gi0/1" must not land on "Gi0/10"."""
    if not port:
        return None
    if port in interfaces:
        return interfaces[port]
    folded = [i for name, i in interfaces.items() if name.lower() == port.lower()]
    return folded[0] if len(folded) == 1 else None
