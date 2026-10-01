"""Vendor-neutral switch profiles for Plan 18: RMON Ethernet error counters and a VLAN summary, from the IETF
RMON-MIB and IEEE Q-BRIDGE-MIB files in BDCOM_MIBS/. Like app/registry/standard_oids.py these are published
standards, so every numeric OID is re-derived from the MIB text by tests/test_switch_standard_profiles.py rather than
taken from a device.

Unlike system_basic and interface_basic they are seeded as DRAFTS. Walking etherStatsTable and dot1qVlanCurrentTable
on many access switches at once has a load cost Plan 18 wants measured against the rate budget first (its hardware
sign-off), so an operator activates them.

Only read-only objects are used, which leaves two gaps, recorded rather than worked around (decision D-30):
- etherStatsDataSource, the column that says which interface an RMON row counts, is read-write. Without it a row is
  known only by etherStatsIndex; many switches number it after the ifIndex, but nothing guarantees that.
- dot1qVlanStaticName (the VLAN's name) is read-create, so the summary has VLAN ids and status but no names.
The registry refuses to poll any writable object (app/registry/oid.py), and this module does not weaken that.
"""
from __future__ import annotations

from dataclasses import dataclass

MIB_DIRECTORY = "BDCOM_MIBS"
RMON_FILE = "RMON-MIB.my"
Q_BRIDGE_FILE = "Q-BRIDGE-MIB.my"

RMON_PROFILE = "rmon_errors"
VLAN_PROFILE = "vlan_summary"

PORT_ROWS = 512    # etherStatsTable rows: one per monitored port
VLAN_ROWS = 4096   # every possible VLAN id
WALK_TIMEOUT_MS = 8000
VLAN_WALK_TIMEOUT_MS = 15000
GET_TIMEOUT_MS = 3000


@dataclass(frozen=True)
class StandardSwitchDefinition:
    logical_name: str
    mib_file: str
    mib_object: str
    numeric_oid: str
    strategy: str  # "get" for a scalar, "walk" for a table column
    max_rows: int | None = None
    timeout_ms: int | None = None
    unit: str | None = None


def _rmon(name: str, obj: str, column: int, unit: str | None = "packets") -> StandardSwitchDefinition:
    return StandardSwitchDefinition(f"rmon.{name}", RMON_FILE, obj, f"1.3.6.1.2.1.16.1.1.1.{column}", "walk",
                                    PORT_ROWS, WALK_TIMEOUT_MS, unit)


RMON_DEFINITIONS: list[StandardSwitchDefinition] = [
    _rmon("index", "etherStatsIndex", 1, None),
    _rmon("drop_events", "etherStatsDropEvents", 3, "events"),
    _rmon("crc_align_errors", "etherStatsCRCAlignErrors", 8),
    _rmon("undersize_packets", "etherStatsUndersizePkts", 9),
    _rmon("oversize_packets", "etherStatsOversizePkts", 10),
    _rmon("fragments", "etherStatsFragments", 11),
    _rmon("jabbers", "etherStatsJabbers", 12),
    _rmon("collisions", "etherStatsCollisions", 13, "collisions"),
]

VLAN_DEFINITIONS: list[StandardSwitchDefinition] = [
    StandardSwitchDefinition("vlan.max_vlan_id", Q_BRIDGE_FILE, "dot1qMaxVlanId", "1.3.6.1.2.1.17.7.1.1.2", "get"),
    StandardSwitchDefinition("vlan.max_supported", Q_BRIDGE_FILE, "dot1qMaxSupportedVlans", "1.3.6.1.2.1.17.7.1.1.3", "get"),
    StandardSwitchDefinition("vlan.count", Q_BRIDGE_FILE, "dot1qNumVlans", "1.3.6.1.2.1.17.7.1.1.4", "get"),
    # dot1qVlanCurrentTable, indexed <TimeMark>.<VLAN id>: one row per VLAN in use, with how it came to exist.
    StandardSwitchDefinition("vlan.status", Q_BRIDGE_FILE, "dot1qVlanStatus", "1.3.6.1.2.1.17.7.1.4.2.1.6", "walk",
                             VLAN_ROWS, VLAN_WALK_TIMEOUT_MS),
]

PROFILES: dict[str, list[StandardSwitchDefinition]] = {RMON_PROFILE: RMON_DEFINITIONS, VLAN_PROFILE: VLAN_DEFINITIONS}
DESCRIPTIONS = {
    RMON_PROFILE: "RMON Ethernet error counters (RFC 2819), vendor-neutral. Draft until measured against the rate "
                  "budget (Plan 18). Rows are keyed by etherStatsIndex only (D-30).",
    VLAN_PROFILE: "VLAN count, limits and per-VLAN status (IEEE Q-BRIDGE-MIB), vendor-neutral. No VLAN names: "
                  "dot1qVlanStaticName is read-create (D-30). Draft until measured against the rate budget (Plan 18).",
}
