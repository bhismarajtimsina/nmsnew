"""Plan 19: BGP state normalization, bounded router tables, the per-metric API-or-SNMP record, and the vendor-neutral
router_addresses profile re-derived from RFC1213-MIB.my. No device is contacted; polls use the fake transport."""
import re
from pathlib import Path

import pytest

from app.registry.mib import resolve
from app.registry.oid import Definition, Entry, profile_problems
from app.registry.router_standard_oids import ADDRESS_PROFILE, ADDRESS_ROWS, MIB_DIRECTORY, PROFILES, RFC1213_FILE
from app.vendors.router import (
    BGP_STATES,
    FSM,
    METRIC_SOURCES,
    SNMP_PEER_STATE,
    TABLE_BOUNDS,
    bgp_from_routeros,
    bgp_from_snmp,
    take_bounded,
)
from tests.polling_helpers import ctx, make_pollable  # noqa: F401

_ROOT = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / MIB_DIRECTORY).is_dir()), None)


# --- BGP ---

@pytest.mark.parametrize("code, label, state", [
    (1, "idle", "idle"), (2, "connect", "connecting"), (3, "active", "connecting"), (4, "opensent", "connecting"),
    (5, "openconfirm", "connecting"), (6, "established", "established"),
])
def test_every_bgp4_mib_peer_state_maps_to_the_common_set(code, label, state):
    result = bgp_from_snmp(code, 2)
    assert (result.label, result.state, result.up) == (label, state, state == "established")


def test_a_stopped_peer_is_disabled_whatever_its_fsm_state_and_unknown_codes_are_never_up():
    assert bgp_from_snmp(6, 1).state == "disabled" and bgp_from_snmp(6, 1).up is False
    assert bgp_from_snmp(None, 1).state == "disabled"
    assert (bgp_from_snmp(9, 2).state, bgp_from_snmp(0).state) == ("unknown", "unknown")
    assert bgp_from_snmp(None, None) is None and bgp_from_snmp(6).state == "established"


@pytest.mark.parametrize("peer, label, state", [
    ({"state": "established", "disabled": "false"}, "established", "established"),
    ({"state": "Active"}, "active", "connecting"), ({"state": "opensent"}, "opensent", "connecting"),
    ({"state": "idle"}, "idle", "idle"), ({"state": "established", "disabled": "true"}, "disabled", "disabled"),
    ({"state": "established", "disabled": True}, "disabled", "disabled"),  # a client library that returns booleans
    ({"state": "established", "disabled": "TRUE"}, "disabled", "disabled"), ({"disabled": False}, "down", "idle"),
    ({"disabled": "false"}, "down", "idle"), ({"state": "  "}, "down", "idle"), ({"state": "teleporting"}, "teleporting", "unknown"),
])
def test_routeros_peer_rows_normalize_like_legacy(peer, label, state):
    result = bgp_from_routeros(peer)
    assert (result.label, result.state) == (label, state)


def test_one_table_serves_both_sources():
    assert set(SNMP_PEER_STATE.values()) == set(FSM) and set(FSM.values()) <= set(BGP_STATES)


# --- bounded tables ---

def test_a_table_over_its_cap_is_cut_and_flagged_and_the_rest_is_never_read():
    consumed = []

    def stream():
        for i in range(10_000_000):
            consumed.append(i)
            yield {"address": f"10.0.{i // 256}.{i % 256}"}

    result = take_bounded("arp", stream())
    assert len(result.rows) == TABLE_BOUNDS["arp"] and result.truncated is True
    assert len(consumed) == TABLE_BOUNDS["arp"] + 1  # one probe row, then nothing more


def test_a_table_at_or_under_its_cap_is_complete():
    assert take_bounded("bgp_peers", range(TABLE_BOUNDS["bgp_peers"])).truncated is False
    assert take_bounded("bgp_peers", range(3)) .rows == [0, 1, 2]
    with pytest.raises(KeyError):
        take_bounded("full_routing_table", range(3))


def test_every_metric_has_a_recorded_source_and_reason():
    assert set(METRIC_SOURCES) == {"interfaces", "resources", "connected_networks", "arp", "bgp_sessions", "dhcp_leases", "simple_queues"}
    for metric, source in METRIC_SOURCES.items():
        assert source.routeros in ("api", "snmp", "none") and source.l3_snmp in ("snmp", "none") and source.why, metric
    assert METRIC_SOURCES["arp"].routeros == "api" and "D-30" in METRIC_SOURCES["arp"].why
    assert all(m in TABLE_BOUNDS for m in ("arp", "dhcp_leases", "connected_networks", "simple_queues"))


# --- router_addresses profile ---

@pytest.fixture(scope="module")
def rfc1213():
    if _ROOT is None:
        pytest.skip(f"{MIB_DIRECTORY} is not mounted in this test environment")
    sources = {p.name: p.read_text(errors="ignore") for p in sorted((_ROOT / MIB_DIRECTORY).iterdir())
               if p.is_file() and p.name != "Q-BRIDGE-MIB.my"}
    return sources, resolve(sources)


@pytest.mark.parametrize("d", PROFILES[ADDRESS_PROFILE], ids=lambda d: d.logical_name)
def test_every_address_oid_matches_rfc1213_and_is_a_read_only_column(d, rfc1213):
    sources, resolution = rfc1213
    entry = {e.definition.name: e for e in resolution.files[RFC1213_FILE].entries}[d.mib_object]
    assert entry.numeric_oid == d.numeric_oid and entry.definition.parent == "ipAddrEntry" and d.strategy == "walk"
    clause = re.search(rf"^\s*{d.mib_object}\s+OBJECT-TYPE(.*?)::=", sources[RFC1213_FILE], re.S | re.M).group(1)
    assert re.search(r"ACCESS\s+([\w-]+)", clause).group(1) == "read-only"


def test_the_arp_and_route_tables_are_writable_which_is_why_they_are_not_used(rfc1213):
    sources, _ = rfc1213
    for obj in ("ipNetToMediaPhysAddress", "ipRouteNextHop"):
        clause = re.search(rf"^\s*{obj}\s+OBJECT-TYPE(.*?)::=", sources[RFC1213_FILE], re.S | re.M).group(1)
        assert re.search(r"ACCESS\s+([\w-]+)", clause).group(1) == "read-write"
    assert not any(d.numeric_oid.startswith(("1.3.6.1.2.1.4.21", "1.3.6.1.2.1.4.22")) for d in PROFILES[ADDRESS_PROFILE])


def test_the_profile_passes_the_registry_rules():
    defs = PROFILES[ADDRESS_PROFILE]
    assert profile_problems([Entry(Definition(d.logical_name, d.numeric_oid), d.strategy, d.max_rows, d.timeout_ms) for d in defs]) == []


async def test_it_is_seeded_as_a_vendor_neutral_draft_that_polls_nothing(ctx, db):
    from app.polling.engine import poll_device

    row = await db.fetchrow(
        "select p.status, p.vendor_id, count(e.*) as n from oid_profiles p left join oid_profile_entries e on e.profile_id = p.id "
        "where p.name = $1 group by p.id", ADDRESS_PROFILE)
    assert (row["status"], row["vendor_id"], row["n"]) == ("draft", None, len(PROFILES[ADDRESS_PROFILE]))
    device = await make_pollable(db, "10.94.0.1")
    outcome = await poll_device(ctx, device, ADDRESS_PROFILE)
    assert outcome.status != "ok" and ctx.transport.calls == []


async def test_once_active_a_router_with_more_addresses_than_the_cap_is_cut_and_flagged(ctx, db):
    from app.polling.engine import poll_device

    await db.execute("update oid_profiles set status = 'active' where name = $1", ADDRESS_PROFILE)
    device = await make_pollable(db, "10.94.0.2")
    first = PROFILES[ADDRESS_PROFILE][0].numeric_oid
    ctx.transport.script_table("10.94.0.2", first, [(f"{first}.10.0.{i // 256}.{i % 256}", i) for i in range(ADDRESS_ROWS + 50)])
    outcome = await poll_device(ctx, device, ADDRESS_PROFILE)
    assert outcome.status == "ok" and outcome.truncated is True and outcome.truncated_columns == [first]
    assert {(root, rows - 1) for _, root, rows, _ in ctx.transport.walk_limits} == {(d.numeric_oid, ADDRESS_ROWS) for d in PROFILES[ADDRESS_PROFILE]}
