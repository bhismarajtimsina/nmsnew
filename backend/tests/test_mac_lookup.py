"""Bounded MAC lookup (Plan 13): one GET for one MAC across at most MAX_FDB_IDS VLANs, never an FDB walk. Exercised
with the fake transport only; no device is contacted."""
from pathlib import Path

import pytest

from app.polling.fake import FakeTransport
from app.polling.transport import BoundedTransport, Credentials, Target
from app.registry.mib import resolve
from app.services.mac_lookup import DOT1Q_TP_FDB_PORT, MAX_FDB_IDS, MacLocation, fdb_port_oids, lookup_mac, parse_mac

TARGET = Target("10.97.0.1", Credentials("v2c", community="test-only"))
MAC = "00:1a:2b:3c:4d:5e"
OCTETS = "0.26.43.60.77.94"


@pytest.mark.parametrize("text", ["00:1a:2b:3c:4d:5e", "00-1A-2B-3C-4D-5E", "001a.2b3c.4d5e", "001A2B3C4D5E", " 00:1a:2b:3c:4d:5e "])
def test_every_common_mac_spelling_parses_the_same(text):
    assert parse_mac(text) == (0, 26, 43, 60, 77, 94)


@pytest.mark.parametrize("text", ["", "00:1a:2b:3c:4d", "00:1a:2b:3c:4d:5e:6f", "zz:1a:2b:3c:4d:5e", "1.3.6.1"])
def test_anything_else_is_refused(text):
    with pytest.raises(ValueError):
        parse_mac(text)


def test_row_oids_are_built_for_each_vlan_without_walking():
    assert fdb_port_oids(MAC, [20, 10, 20]) == {
        f"{DOT1Q_TP_FDB_PORT}.10.{OCTETS}": 10,
        f"{DOT1Q_TP_FDB_PORT}.20.{OCTETS}": 20,
    }


@pytest.mark.parametrize("vlans", [[], list(range(1, MAX_FDB_IDS + 2)), [0], [4095]])
def test_an_empty_oversized_or_out_of_range_vlan_list_is_refused_not_trimmed(vlans):
    with pytest.raises(ValueError):
        fdb_port_oids(MAC, vlans)


async def test_a_lookup_is_exactly_one_bounded_get_and_reports_where_the_mac_is_learned():
    fake = FakeTransport()
    fake.script_get("10.97.0.1", {f"{DOT1Q_TP_FDB_PORT}.20.{OCTETS}": 7, f"{DOT1Q_TP_FDB_PORT}.30.{OCTETS}": 0})
    bounded = BoundedTransport(fake)
    found = await lookup_mac(bounded, TARGET, MAC, [10, 20, 30])
    assert found == [MacLocation(vlan=20, bridge_port=7)]  # port 0 = known to the switch but not on a port
    assert [c[0] for c in fake.calls] == ["get"] and len(fake.calls[0][2]) == 3
    assert not any(c[0] == "walk" for c in fake.calls)
    assert bounded.requests[0].kind == "get"


async def test_the_largest_allowed_lookup_is_still_one_get_within_the_transport_cap():
    fake = FakeTransport()
    await lookup_mac(BoundedTransport(fake), TARGET, MAC, list(range(1, MAX_FDB_IDS + 1)))
    assert len(fake.calls) == 1 and len(fake.calls[0][2]) == MAX_FDB_IDS


def test_the_fdb_port_oid_is_the_one_q_bridge_mib_assigns():
    """Q-BRIDGE-MIB's chain starts at dot1dBridge, which BRIDGE-MIB (not in the repository) defines. The one line RFC
    4188 gives it is supplied here; everything below it comes from the real Q-BRIDGE-MIB.my text."""
    root = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / "BDCOM_MIBS").is_dir()), None)
    if root is None:
        pytest.skip("BDCOM_MIBS is not mounted in this test environment")
    # The repository's Q-BRIDGE-MIB.my holds two modules, both named Q-BRIDGE-MIB: the first is P-BRIDGE-MIB content
    # rooted at { dot1dBridge 6 } that reuses the qBridgeMIB names, the second is the real Q-BRIDGE-MIB at
    # { dot1dBridge 7 }. Resolving the whole file lets the first module's names win, so only the real one is used.
    text = (root / "BDCOM_MIBS" / "Q-BRIDGE-MIB.my").read_text(errors="ignore")
    modules = [m + "END\n" for m in text.split("\nEND") if "DEFINITIONS ::= BEGIN" in m]
    (q_bridge,) = [m for m in modules if "::= { dot1dBridge 7 }" in m]
    sources = {
        "Q-BRIDGE-MIB.my": q_bridge,
        "BRIDGE-MIB-STUB": "BRIDGE-MIB-STUB DEFINITIONS ::= BEGIN\ndot1dBridge OBJECT IDENTIFIER ::= { mib-2 17 }\nEND\n",
    }
    entries = {e.definition.name: e.numeric_oid for e in resolve(sources).files["Q-BRIDGE-MIB.my"].entries}
    assert entries["dot1qTpFdbPort"] == DOT1Q_TP_FDB_PORT
