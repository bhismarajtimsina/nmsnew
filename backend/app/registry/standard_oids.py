"""Standard, IETF-defined OID definitions: RFC 1213 (MIB-II) system group and interface table.

Unlike a vendor's private MIB branch, these numeric OIDs are a public, vendor-neutral standard: every compliant SNMP
agent implements the same meaning at the same address, so there is nothing vendor-specific here that a device capture
could tell us and a published standard cannot. That is the basis for authoring these without the fixture a vendor-
private OID needs (see docs/cybersathy-nms-migration/06-oid-profile-registry.md and decisions.md D-25): the safe
discovery OIDs (sysDescr, sysObjectID, sysUpTime, sysName) already rest on exactly this reasoning, hardcoded and
enabled since Plan 9 with no per-device fixture ever required. This module extends the same category to the rest of
the system group and to the interface table, and to nothing vendor-private.

Every numeric OID below is re-derived, not typed from memory: tests/test_standard_oids.py resolves each one fresh from
the real `RFC1213-MIB.my` file in this repository (via app.registry.mib, read from a single named file rather than the
ambiguous merged-corpus lookup — see the warning on ResolveResult.by_name) and fails if it ever disagrees with what is
declared here. `python -m app.cli mib check` (Plan 7's `check_definition`) gives the same cross-check at the database
level once RFC1213-MIB.my is imported.
"""
from __future__ import annotations

from dataclasses import dataclass

SOURCE_FILE = "RFC1213-MIB.my"


@dataclass(frozen=True)
class StandardDefinition:
    logical_name: str
    mib_object: str
    numeric_oid: str
    unit: str | None = None


# System group (RFC 1213 §6.1). All ACCESS read-only, STATUS mandatory in the real MIB text.
SYSTEM_DEFINITIONS: list[StandardDefinition] = [
    StandardDefinition("system.sys_descr", "sysDescr", "1.3.6.1.2.1.1.1"),
    StandardDefinition("system.sys_object_id", "sysObjectID", "1.3.6.1.2.1.1.2"),
    StandardDefinition("system.sys_up_time", "sysUpTime", "1.3.6.1.2.1.1.3", "centiseconds"),
    StandardDefinition("system.sys_contact", "sysContact", "1.3.6.1.2.1.1.4"),
    StandardDefinition("system.sys_name", "sysName", "1.3.6.1.2.1.1.5"),
    StandardDefinition("system.sys_location", "sysLocation", "1.3.6.1.2.1.1.6"),
]

# Interface table, ifEntry = 1.3.6.1.2.1.2.2.1 (RFC 1213 §6.4). Each is a column: walking its OID returns one row per
# interface. All ACCESS read-only, STATUS mandatory. ifSpecific and the counters RFC 2863 deprecated (ifInNUcastPkts,
# ifOutNUcastPkts, ifOutQLen) are left out; a 64-bit counter table (IF-MIB's ifXTable) is a later addition, not RFC 1213.
INTERFACE_DEFINITIONS: list[StandardDefinition] = [
    StandardDefinition("interface.if_index", "ifIndex", "1.3.6.1.2.1.2.2.1.1"),
    StandardDefinition("interface.if_descr", "ifDescr", "1.3.6.1.2.1.2.2.1.2"),
    StandardDefinition("interface.if_type", "ifType", "1.3.6.1.2.1.2.2.1.3"),
    StandardDefinition("interface.if_mtu", "ifMtu", "1.3.6.1.2.1.2.2.1.4", "bytes"),
    StandardDefinition("interface.if_speed", "ifSpeed", "1.3.6.1.2.1.2.2.1.5", "bits/s"),
    StandardDefinition("interface.if_phys_address", "ifPhysAddress", "1.3.6.1.2.1.2.2.1.6"),
    StandardDefinition("interface.if_admin_status", "ifAdminStatus", "1.3.6.1.2.1.2.2.1.7"),
    StandardDefinition("interface.if_oper_status", "ifOperStatus", "1.3.6.1.2.1.2.2.1.8"),
    StandardDefinition("interface.if_last_change", "ifLastChange", "1.3.6.1.2.1.2.2.1.9", "centiseconds"),
    StandardDefinition("interface.if_in_octets", "ifInOctets", "1.3.6.1.2.1.2.2.1.10", "bytes"),
    StandardDefinition("interface.if_in_ucast_pkts", "ifInUcastPkts", "1.3.6.1.2.1.2.2.1.11", "packets"),
    StandardDefinition("interface.if_in_discards", "ifInDiscards", "1.3.6.1.2.1.2.2.1.13", "packets"),
    StandardDefinition("interface.if_in_errors", "ifInErrors", "1.3.6.1.2.1.2.2.1.14", "packets"),
    StandardDefinition("interface.if_out_octets", "ifOutOctets", "1.3.6.1.2.1.2.2.1.16", "bytes"),
    StandardDefinition("interface.if_out_ucast_pkts", "ifOutUcastPkts", "1.3.6.1.2.1.2.2.1.17", "packets"),
    StandardDefinition("interface.if_out_discards", "ifOutDiscards", "1.3.6.1.2.1.2.2.1.19", "packets"),
    StandardDefinition("interface.if_out_errors", "ifOutErrors", "1.3.6.1.2.1.2.2.1.20", "packets"),
]

# Bounds for the interface_basic profile's walk entries. A device is very unlikely to have more than this many
# interfaces; the point of the cap is not this specific number but that there always is one (migration 0007 refuses a
# walk entry with none at all).
INTERFACE_TABLE_MAX_ROWS = 512
INTERFACE_TABLE_TIMEOUT_MS = 8000
SYSTEM_GET_TIMEOUT_MS = 3000

SYSTEM_BASIC_PROFILE = "system_basic"
INTERFACE_BASIC_PROFILE = "interface_basic"
