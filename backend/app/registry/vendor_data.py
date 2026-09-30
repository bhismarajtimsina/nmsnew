"""Vendors, model families and capabilities the system knows about.

This is the catalogue of what is planned, not a claim about what works. Every family capability starts `unverified`;
a capability becomes `supported` only when a recorded fixture proves it (enforced by the database), so nothing here
enables polling of anything.
"""
from __future__ import annotations

# slug, name
VENDORS: list[tuple[str, str]] = [
    ("bdcom", "BDCOM"), ("cdata", "C-Data"), ("huawei", "Huawei"), ("zte", "ZTE"), ("vsolution", "VSolution"),
    ("gcom", "GCOM"), ("mikrotik", "MikroTik"), ("d-link", "D-Link"), ("cisco", "Cisco"), ("juniper", "Juniper"),
    ("dcn", "DCN"), ("raisecom", "Raisecom"), ("eltex", "Eltex"), ("edgecore", "Edgecore"), ("hp-aruba", "HP/Aruba"),
    ("arista", "Arista"), ("dell", "Dell"), ("alcatel", "Alcatel"), ("allied-telesis", "Allied Telesis"),
    ("tp-link", "TP-Link"), ("ubnt", "UBNT"), ("generic-snmp", "Generic SNMP"),
]

# vendor slug, family slug, family name, device type
FAMILIES: list[tuple[str, str, str, str]] = [
    # BDCOM switches and OLTs share an enterprise number but are different families with different pollers (risk K-09).
    ("bdcom", "bdcom-switch", "BDCOM switches", "switch"),
    ("bdcom", "bdcom-olt", "BDCOM OLTs (GPON and EPON)", "olt"),
    ("cdata", "cdata-fd-olt", "C-Data FD series OLTs", "olt"),
    ("huawei", "huawei-olt", "Huawei OLTs (GPON and EPON)", "olt"),
    ("zte", "zte-c-olt", "ZTE C-series OLTs (C300, C600)", "olt"),
    ("vsolution", "vsolution-v1600-olt", "VSolution V1600 OLTs", "olt"),
    ("gcom", "gcom-el5610-olt", "GCOM EL5610 OLTs", "olt"),
    ("mikrotik", "mikrotik-routeros", "MikroTik RouterOS", "router"),
    ("d-link", "d-link-switch", "D-Link switches", "switch"),
    ("cisco", "cisco-switch", "Cisco switches", "switch"),
    ("juniper", "juniper-switch", "Juniper switches", "switch"),
    ("dcn", "dcn-switch", "DCN switches", "switch"),
    ("raisecom", "raisecom-switch", "Raisecom switches", "switch"),
    ("eltex", "eltex-switch", "Eltex switches", "switch"),
    ("edgecore", "edgecore-switch", "Edgecore switches", "switch"),
    ("hp-aruba", "hp-aruba-switch", "HP/Aruba switches", "switch"),
    ("arista", "arista-switch", "Arista switches", "switch"),
    ("dell", "dell-switch", "Dell switches", "switch"),
    ("alcatel", "alcatel-switch", "Alcatel switches", "switch"),
    ("allied-telesis", "allied-telesis-switch", "Allied Telesis switches", "switch"),
    ("tp-link", "tp-link-switch", "TP-Link switches", "switch"),
    ("ubnt", "ubnt-switch", "UBNT switches", "switch"),
    ("generic-snmp", "generic-snmp", "Generic SNMP devices", "other"),
]

# code, description, risk, enabled by default. `high` is never enabled by default (a database rule).
CAPABILITIES: list[tuple[str, str, str, bool]] = [
    ("system", "System group: name, description, uptime, object id", "low", True),
    ("interfaces", "Interface inventory", "low", True),
    ("interface_status", "Interface administrative and operational status", "low", True),
    ("interface_counters", "Interface traffic counters", "low", True),
    ("interface_errors", "Interface error counters", "low", True),
    ("resources", "CPU, memory and temperature", "low", True),
    ("lldp", "LLDP neighbours (bounded)", "bounded", False),
    ("vlan_summary", "VLAN summary", "bounded", False),
    ("sfp_ddm", "SFP optical diagnostics", "bounded", False),
    ("rmon", "RMON statistics", "bounded", False),
    ("fdb_bounded", "Lookup of a single MAC address in the forwarding table", "bounded", False),
    ("fdb_full", "Full forwarding table walk", "high", False),
    ("private_enterprise_walk", "Walk of a vendor's private enterprise tree", "high", False),
    ("pon_ports", "PON port inventory and state", "bounded", False),
    ("pon_loading", "PON port loading", "bounded", False),
    ("onu_list", "ONU list", "bounded", False),
    ("onu_status", "ONU status", "bounded", False),
    ("onu_identity", "ONU serial, vendor, model and firmware", "bounded", False),
    ("onu_optical", "ONU optical receive and transmit levels", "bounded", False),
    ("onu_distance", "ONU distance", "bounded", False),
    ("unregistered_onus", "Unregistered ONUs", "bounded", False),
    ("bgp_sessions", "BGP sessions", "bounded", False),
    ("arp_bounded", "ARP table with a row limit", "bounded", False),
    ("routes_bounded", "Routing table with a row limit", "bounded", False),
    ("dhcp_leases_bounded", "DHCP leases with a row limit", "bounded", False),
    ("sensors", "Sensor readings", "bounded", False),
]
