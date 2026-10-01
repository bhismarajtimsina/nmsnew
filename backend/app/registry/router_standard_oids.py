"""Vendor-neutral router profile for Plan 19: the IP address table (RFC 1213 ipAddrTable), from which connected
networks are derived, re-derived from BDCOM_MIBS/RFC1213-MIB.my by tests/test_router.py.

This is what legacy's "routes" module reads too (DirectRoutes: addresses and masks, never the routing table), so a
router carrying a full BGP table is never walked for it. Seeded as a DRAFT, like the switch profiles, until measured
against the rate budget.

RFC 1213's ARP table (ipNetToMediaTable) and route table (ipRouteTable) are read-write, so the registry will not poll
them (decision D-30); ARP comes from the RouterOS API instead (app/vendors/router.py, METRIC_SOURCES).
"""
from __future__ import annotations

from app.registry.switch_standard_oids import GET_TIMEOUT_MS, MIB_DIRECTORY, WALK_TIMEOUT_MS, StandardSwitchDefinition  # noqa: F401

RFC1213_FILE = "RFC1213-MIB.my"
ADDRESS_PROFILE = "router_addresses"
ADDRESS_ROWS = 1024  # IPv4 addresses on one router


def _addr(name: str, obj: str, column: int) -> StandardSwitchDefinition:
    return StandardSwitchDefinition(f"ip.{name}", RFC1213_FILE, obj, f"1.3.6.1.2.1.4.20.1.{column}", "walk",
                                    ADDRESS_ROWS, WALK_TIMEOUT_MS)


ADDRESS_DEFINITIONS = [
    _addr("address", "ipAdEntAddr", 1),
    _addr("if_index", "ipAdEntIfIndex", 2),
    _addr("net_mask", "ipAdEntNetMask", 3),
]

PROFILES = {ADDRESS_PROFILE: ADDRESS_DEFINITIONS}
DESCRIPTIONS = {
    ADDRESS_PROFILE: "IPv4 addresses and masks (RFC 1213 ipAddrTable), for connected networks; never the routing table. "
                     "Vendor-neutral. Draft until measured against the rate budget (Plan 19).",
}
