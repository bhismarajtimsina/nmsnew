"""The BDCOM switch polling profile (Plan 13): what the new poller may read from a BDCOM Ethernet switch.

Built from the legacy switcher-core definitions (`configs/oids/bdcom/switch-common.yml`, the file every BDCOM switch
model lists first) and kept only where BDCOM's own MIB agrees. Every numeric OID below is re-derived each test run
from the MIB file named next to it, under `NMS_BDCOM_MIBS/` (tests/test_bdcom_switch_profile.py); a definition whose
MIB object does not resolve to exactly this OID fails the build.

What is deliberately left out:
- Anything writable. The legacy file also declares setters (config save, STP options, loop detection, QoS trust, port
  PVID and more); a polling profile reads only, and the profile tests refuse a non-read-only MIB object.
- The full FDB / MAC table (dot1dTpFdb, dot1qTpFdb), console FDB, and any walk of the private enterprise root: Plan 13
  and the safety policy keep these disabled. A MAC lookup is a separate, bounded query for one MAC.
- Legacy names the MIB contradicts. `stp.port.*` and `stp.bridge.*` in the legacy file point at OIDs that
  NMS-IEEE8023-LAG-MIB defines as link-aggregation objects (dot3adAgg*), not spanning tree; whatever legacy shows
  under those names is LAG data. They are not carried over.
- Legacy names with no MIB definition in the repository (sfp.ddm.present, sys.versionString, the vlan.* and
  undocumented.* names, and others): without a MIB, a numeric OID cannot be checked offline, so it waits for a
  fixture.

Why the profile is seeded as a draft and never activated here: these are vendor-private OIDs, and the safety policy
needs a recorded device fixture per model family before a parser counts as done, plus an operator's hardware sign-off
before a switch is polled. The engine only runs active profiles, so a draft polls nothing. Activation is the
operator's step, recorded in STATUS.md (Plan 13, hardware sign-off). Not verified against a device.
"""
from __future__ import annotations

from dataclasses import dataclass

MIB_DIRECTORY = "NMS_BDCOM_MIBS"
PROFILE_NAME = "bdcom_switch_basic"
VENDOR_SLUG = "bdcom"
FAMILY_SLUG = "bdcom-switch"


@dataclass(frozen=True)
class VendorDefinition:
    logical_name: str
    mib_file: str
    mib_object: str
    numeric_oid: str
    strategy: str  # "get" for a scalar, "walk" for a table column
    max_rows: int | None = None
    timeout_ms: int | None = None
    unit: str | None = None


# Bounds. The point is that every walk has one, not these exact numbers; each is well above what one switch has.
CARD_ROWS = 16          # line cards / CPUs on one chassis
NEIGHBOUR_ROWS = 256    # LLDP neighbours
PORT_ROWS = 512         # optical ports
WALK_TIMEOUT_MS = 8000
GET_TIMEOUT_MS = 3000

DEFINITIONS: list[VendorDefinition] = [
    # System identity (scalars).
    VendorDefinition("bdcom.system.serial_number", "NMS-SNMP.my", "neSerialNo", "1.3.6.1.4.1.3320.9.225.1.2", "get"),
    VendorDefinition("bdcom.system.hardware_version", "NMS-SNMP.my", "neHardwareversion", "1.3.6.1.4.1.3320.9.225.1.7", "get"),
    VendorDefinition("bdcom.system.software_version", "NMS-SNMP.my", "neSoftwareversion", "1.3.6.1.4.1.3320.9.225.1.8", "get"),
    VendorDefinition("bdcom.system.mac_address", "NMS-SNMP.my", "neMacAddress", "1.3.6.1.4.1.3320.9.225.1.9", "get"),
    # Resources, per card / CPU (table columns).
    VendorDefinition("bdcom.resources.card_cpu_utilization", "NMS-CHASSIS-MIB.my", "nmscardCPUUtilization",
                     "1.3.6.1.4.1.3320.3.6.10.1.11", "walk", CARD_ROWS, WALK_TIMEOUT_MS, "%"),
    VendorDefinition("bdcom.resources.card_memory_utilization", "NMS-CHASSIS-MIB.my", "nmscardMEMUtilization",
                     "1.3.6.1.4.1.3320.3.6.10.1.12", "walk", CARD_ROWS, WALK_TIMEOUT_MS, "%"),
    VendorDefinition("bdcom.resources.cpu_5s", "NMS-PROCESS-MIB.my", "nmspmCPUTotal5sec",
                     "1.3.6.1.4.1.3320.9.109.1.1.1.1.3", "walk", CARD_ROWS, WALK_TIMEOUT_MS, "%"),
    VendorDefinition("bdcom.resources.cpu_1m", "NMS-PROCESS-MIB.my", "nmspmCPUTotal1min",
                     "1.3.6.1.4.1.3320.9.109.1.1.1.1.4", "walk", CARD_ROWS, WALK_TIMEOUT_MS, "%"),
    VendorDefinition("bdcom.resources.cpu_5m", "NMS-PROCESS-MIB.my", "nmspmCPUTotal5min",
                     "1.3.6.1.4.1.3320.9.109.1.1.1.1.5", "walk", CARD_ROWS, WALK_TIMEOUT_MS, "%"),
    # LLDP neighbours, from BDCOM's private copy of the IEEE LLDP-MIB (nms 127), bounded.
    VendorDefinition("bdcom.lldp.remote_chassis_id", "NMS-LLDP-MIB.MIB", "lldpRemChassisId",
                     "1.3.6.1.4.1.3320.127.1.4.1.1.5", "walk", NEIGHBOUR_ROWS, WALK_TIMEOUT_MS),
    VendorDefinition("bdcom.lldp.remote_port_id", "NMS-LLDP-MIB.MIB", "lldpRemPortId",
                     "1.3.6.1.4.1.3320.127.1.4.1.1.7", "walk", NEIGHBOUR_ROWS, WALK_TIMEOUT_MS),
    VendorDefinition("bdcom.lldp.remote_port_description", "NMS-LLDP-MIB.MIB", "lldpRemPortDesc",
                     "1.3.6.1.4.1.3320.127.1.4.1.1.8", "walk", NEIGHBOUR_ROWS, WALK_TIMEOUT_MS),
    VendorDefinition("bdcom.lldp.remote_system_name", "NMS-LLDP-MIB.MIB", "lldpRemSysName",
                     "1.3.6.1.4.1.3320.127.1.4.1.1.9", "walk", NEIGHBOUR_ROWS, WALK_TIMEOUT_MS),
    # SFP digital diagnostics per optical port, bounded. Units are the MIB's own words.
    VendorDefinition("bdcom.sfp.tx_power", "NMS-IF-MIB.my", "txPower",
                     "1.3.6.1.4.1.3320.9.63.1.7.1.2", "walk", PORT_ROWS, WALK_TIMEOUT_MS, "0.1 dBm"),
    VendorDefinition("bdcom.sfp.rx_power", "NMS-IF-MIB.my", "rxPower",
                     "1.3.6.1.4.1.3320.9.63.1.7.1.3", "walk", PORT_ROWS, WALK_TIMEOUT_MS, "0.1 dBm"),
    VendorDefinition("bdcom.sfp.temperature", "NMS-IF-MIB.my", "temperature",
                     "1.3.6.1.4.1.3320.9.63.1.7.1.4", "walk", PORT_ROWS, WALK_TIMEOUT_MS, "1/256 degC"),
    VendorDefinition("bdcom.sfp.voltage", "NMS-IF-MIB.my", "vlotage",  # sic: the MIB's own spelling
                     "1.3.6.1.4.1.3320.9.63.1.7.1.5", "walk", PORT_ROWS, WALK_TIMEOUT_MS, "0.1 mV"),
]

# Subtrees no BDCOM switch profile may touch (Plan 13, safety policy section 6).
FORBIDDEN_ROOTS: dict[str, str] = {
    "1.3.6.1.2.1.17.4.3": "BRIDGE-MIB dot1dTpFdbTable (full FDB)",
    "1.3.6.1.2.1.17.7.1.2.2": "Q-BRIDGE-MIB dot1qTpFdbTable (full FDB)",
}
ENTERPRISE_ROOT = "1.3.6.1.4.1.3320"
# A walk this close to the enterprise root would be a near-full private-enterprise walk.
MIN_PRIVATE_WALK_DEPTH = len(ENTERPRISE_ROOT.split(".")) + 3
