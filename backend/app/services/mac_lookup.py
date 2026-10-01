"""Bounded MAC lookup (Plan 13): where one MAC address is learned on a switch, without walking the FDB.

A full FDB walk can return tens of thousands of rows and is disabled on BDCOM switches by policy. Instead this asks
for exactly the rows the MAC would occupy: Q-BRIDGE-MIB's dot1qTpFdbPort is indexed by { dot1qFdbId,
dot1qTpFdbAddress }, so for a known MAC and a short list of filtering-database ids the row OIDs can be built directly
and fetched in ONE GET, capped at MAX_FDB_IDS. On BDCOM switches the filtering-database id is the VLAN id (shared
learning is not in use on the access switches this targets); that is an assumption to confirm with a fixture, so a
miss means "not learned in these VLANs", never "not on this switch".

Callers pass the transport wrapped in BoundedTransport, which refuses an oversized GET on its own as well. The API
endpoint that exposes this (permission `search.mac`, scoped to the device, rate-limited, audited) is a separate step.
Not verified against a device.
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any

from app.polling.transport import BoundedTransport, Target

DOT1Q_TP_FDB_PORT = "1.3.6.1.2.1.17.7.1.2.2.1.2"  # Q-BRIDGE-MIB dot1qTpFdbPort, re-derived in tests from Q-BRIDGE-MIB.my
MAX_FDB_IDS = 32
VLAN_RANGE = (1, 4094)
_MAC = re.compile(r"^[0-9a-f]{12}$")


def parse_mac(text: str) -> tuple[int, ...]:
    """Accepts aa:bb:cc:dd:ee:ff, aa-bb-..., aabb.ccdd.eeff or aabbccddeeff, in either case. Raises ValueError."""
    digits = re.sub(r"[\s:.\-]", "", text.strip().lower())
    if not _MAC.match(digits):
        raise ValueError("not a MAC address")
    return tuple(int(digits[i:i + 2], 16) for i in range(0, 12, 2))


def fdb_port_oids(mac: str, fdb_ids: list[int]) -> dict[str, int]:
    """dot1qTpFdbPort.<fdbId>.<6 MAC octets> for each id, mapped back to its id. Refuses an empty, oversized or
    out-of-range list instead of trimming it, so a caller never silently looks in fewer VLANs than it asked for."""
    octets = ".".join(str(o) for o in parse_mac(mac))
    unique = sorted(set(fdb_ids))
    if not unique:
        raise ValueError("name at least one VLAN")
    if len(unique) > MAX_FDB_IDS:
        raise ValueError(f"at most {MAX_FDB_IDS} VLANs per lookup")
    if any(not VLAN_RANGE[0] <= v <= VLAN_RANGE[1] for v in unique):
        raise ValueError(f"VLAN ids are {VLAN_RANGE[0]} to {VLAN_RANGE[1]}")
    return {f"{DOT1Q_TP_FDB_PORT}.{v}.{octets}": v for v in unique}


@dataclass(frozen=True)
class MacLocation:
    vlan: int
    bridge_port: int  # a dot1dBasePort number, not an ifIndex: mapping it needs dot1dBasePortIfIndex


async def lookup_mac(transport: BoundedTransport, target: Target, mac: str, fdb_ids: list[int], *, timeout_ms: int = 3000,
                     retries: int = 0) -> list[MacLocation]:
    """One GET, at most MAX_FDB_IDS OIDs. A port of 0 means the switch knows the MAC but not on a port."""
    oids = fdb_port_oids(mac, fdb_ids)
    values: dict[str, Any] = await transport.get(target, list(oids), timeout_ms=timeout_ms, retries=retries)
    found = []
    for oid, vlan in oids.items():
        value = values.get(oid)
        if isinstance(value, int) and not isinstance(value, bool) and value > 0:
            found.append(MacLocation(vlan, value))
    return found
