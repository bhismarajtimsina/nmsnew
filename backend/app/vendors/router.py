"""Router logic for Plan 19 that does not depend on an unverified OID or a device: BGP state normalization through one
table, bounded reads of router tables with truncation flagged, and the per-metric choice between the RouterOS API
and SNMP. Ported from legacy switcher-core (`Modules/RouterOS/*`, `Modules/Juniper/BgpPeers.php`, `DirectRoutes.php`)
and `configs/oids/global.oids.yml`, read from `origin/main` without merging it.

Kept visible, not guessed away:
- Legacy reads RouterOS BGP from `/routing/bgp/peer/print`, which is RouterOS 6. RouterOS 7 replaced that menu
  (connections and sessions), so the legacy module returns nothing on a v7 router. Recorded, not verified on a device.
- Legacy RouterOS ARP and DHCP-lease reads are a plain `print` with no row limit; here every table read is bounded
  (TABLE_BOUNDS) and a cut table is flagged.

Not verified against a device.
"""
from __future__ import annotations

from dataclasses import dataclass
from itertools import islice
from typing import Any, Iterable, Mapping

# --- BGP ------------------------------------------------------------------------------------------------------------

BGP_STATES = ("established", "connecting", "idle", "disabled", "unknown")

# The BGP finite-state machine (RFC 4271 section 8), as BGP4-MIB's bgpPeerState numbers it (RFC 4273) and as legacy's
# global.oids.yml labels it, then the common state. The same words are RouterOS 6's `state` values.
FSM: dict[str, str] = {
    "idle": "idle", "connect": "connecting", "active": "connecting", "opensent": "connecting",
    "openconfirm": "connecting", "established": "established",
}
SNMP_PEER_STATE = {1: "idle", 2: "connect", 3: "active", 4: "opensent", 5: "openconfirm", 6: "established"}
SNMP_ADMIN_STOP = 1  # bgpPeerAdminStatus: stop(1), start(2); legacy labels 1 "disabled"


@dataclass(frozen=True)
class BgpState:
    label: str   # the router's own word
    state: str   # one of BGP_STATES

    @property
    def up(self) -> bool:
        return self.state == "established"


def bgp_from_snmp(peer_state: int | None, admin_status: int | None = None) -> BgpState | None:
    """bgpPeerState with bgpPeerAdminStatus. An administratively stopped peer is `disabled` whatever its FSM state;
    an unlisted state is `unknown`, never established."""
    if peer_state is None and admin_status is None:
        return None
    if admin_status == SNMP_ADMIN_STOP:
        return BgpState("stop", "disabled")
    label = SNMP_PEER_STATE.get(peer_state) if peer_state is not None else None
    if label is None:
        return BgpState(f"state {peer_state}", "unknown")
    return BgpState(label, FSM[label])


def bgp_from_routeros(peer: Mapping[str, Any]) -> BgpState:
    """One `/routing/bgp/peer/print` row (RouterOS 6). `disabled=true` wins; a peer with no `state` is legacy's
    "down", which is idle; an unrecognised state is unknown."""
    if str(peer.get("disabled", "")).lower() == "true":
        return BgpState("disabled", "disabled")
    raw = peer.get("state")
    if raw is None or not str(raw).strip():
        return BgpState("down", "idle")
    label = str(raw).strip().lower()
    return BgpState(label, FSM.get(label, "unknown"))


# --- bounded tables -------------------------------------------------------------------------------------------------

# Row caps per router table. A router that has more is cut at the cap and flagged, never read further.
TABLE_BOUNDS = {
    "arp": 4096,
    "dhcp_leases": 4096,
    "connected_networks": 1024,
    "bgp_peers": 256,
    "simple_queues": 2048,
}


@dataclass(frozen=True)
class BoundedTable:
    rows: list[Any]
    truncated: bool


def take_bounded(table: str, rows: Iterable[Any]) -> BoundedTable:
    """At most TABLE_BOUNDS[table] rows from an iterable (a RouterOS API reply stream, say), read lazily: one row past
    the cap is taken only to know the table was cut, and nothing after it is consumed."""
    cap = TABLE_BOUNDS[table]
    taken = list(islice(iter(rows), cap + 1))
    return BoundedTable(taken[:cap], len(taken) > cap)


# --- where each metric comes from -----------------------------------------------------------------------------------

@dataclass(frozen=True)
class MetricSource:
    routeros: str        # "api", "snmp" or "none"
    l3_snmp: str         # for an SNMP-managed L3 router (Juniper, Dell, Extreme): "snmp" or "none"
    why: str


METRIC_SOURCES: dict[str, MetricSource] = {
    "interfaces": MetricSource("snmp", "snmp", "RFC 1213 interface table (interface_basic): standard, already "
                                               "active, the same on every vendor"),
    "resources": MetricSource("api", "none", "CPU load and memory from RouterOS `/system/resource/print` (legacy): "
                                             "MIKROTIK-MIB has none and HOST-RESOURCES-MIB is not in the repository; "
                                             "health (temperatures, voltage, power) is SNMP in mikrotik_routeros. L3 "
                                             "vendors keep CPU and memory in private MIBs (K-25)"),
    "connected_networks": MetricSource("snmp", "snmp", "RFC 1213 ipAddrTable (router_addresses), read-only; legacy "
                                                       "derives connected routes the same way, never from the routing table"),
    "arp": MetricSource("api", "none", "RFC 1213 ipNetToMediaTable is read-write, so the registry will not poll it "
                                       "(D-30); RouterOS `/ip/arp/print`, bounded"),
    "bgp_sessions": MetricSource("api", "snmp", "RouterOS 6 `/routing/bgp/peer/print`; L3 vendors through BGP4-MIB "
                                                "bgpPeerTable, whose MIB file is not in the repository yet"),
    "dhcp_leases": MetricSource("api", "none", "RouterOS `/ip/dhcp-server/lease/print`, bounded; MIKROTIK-MIB gives only "
                                               "the lease count (mtxrDHCPLeaseCount, in mikrotik_routeros)"),
    "simple_queues": MetricSource("snmp", "none", "MIKROTIK-MIB mtxrQueueSimpleTable (mikrotik_routeros), bounded at "
                                                 "2048 rows; RouterOS only"),
}
