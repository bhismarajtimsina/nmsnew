"""Plan 10: interfaces from an interface_basic poll. The decoder's names are re-read from RFC1213-MIB.my in this
repository, and the writer is exercised through the polling sink with readings built here; no device is contacted."""
import re
from pathlib import Path

import pytest

from app.polling.engine import Reading
from app.services.interface_poll import ADMIN, IF_TYPES, OPER, apply, rows_from
from app.topology.sink import ReadingSink
from app.topology.utilization import IF_SPEED_MAX
from tests.helpers import make_device, make_interface
from tests.test_lldp import Pool

COL = {"descr": "1.3.6.1.2.1.2.2.1.2", "type": "1.3.6.1.2.1.2.2.1.3", "speed": "1.3.6.1.2.1.2.2.1.5",
       "mac": "1.3.6.1.2.1.2.2.1.6", "admin": "1.3.6.1.2.1.2.2.1.7", "oper": "1.3.6.1.2.1.2.2.1.8"}
NAMES = {"descr": "interface.if_descr", "type": "interface.if_type", "speed": "interface.if_speed",
         "mac": "interface.if_phys_address", "admin": "interface.if_admin_status", "oper": "interface.if_oper_status"}


def walk(column, rows):
    return Reading(NAMES[column], COL[column], [(f"{COL[column]}.{i}", v) for i, v in rows])


_MIB = next((p / "BDCOM_MIBS" / "RFC1213-MIB.my" for p in (Path(__file__).resolve().parents[2], Path("/repo"))
             if (p / "BDCOM_MIBS" / "RFC1213-MIB.my").is_file()), None)


def _enum(source, name):
    clause = re.search(rf"^\s*{name} OBJECT-TYPE\s+SYNTAX\s+INTEGER\s*\{{(.*?)\}}", source, re.S | re.M).group(1)
    return {int(n): k for k, n in re.findall(r"([A-Za-z0-9-]+)\((\d+)\)", clause)}


def test_the_names_are_the_ones_rfc1213_gives():
    if _MIB is None:
        pytest.skip("RFC1213-MIB.my is not mounted in this test environment")
    source = _MIB.read_text(errors="ignore")
    assert _enum(source, "ifType") == IF_TYPES
    assert _enum(source, "ifAdminStatus") == ADMIN
    rfc1213_oper = _enum(source, "ifOperStatus")
    assert {k: OPER[k] for k in rfc1213_oper} == rfc1213_oper
    # The rest are RFC 2863's, under the names link state reads (app/topology/state.py).
    assert {k: v for k, v in OPER.items() if k not in rfc1213_oper} == {4: "unknown", 5: "dormant", 6: "notPresent", 7: "lowerLayerDown"}


def test_the_decoder_keeps_only_what_it_can_read():
    rows = rows_from([
        walk("descr", [(1, b"GigaEthernet0/1\x00"), (2, "  Vlan1 "), (3, b"\xff\xfe"), (4, ""), (5, 7)]),
        walk("type", [(1, 6), (2, 135), (3, True)]),
        walk("speed", [(1, 1_000_000_000), (2, IF_SPEED_MAX), (3, 0)]),
        walk("mac", [(1, b"\x00\x1a\x2b\x3c\x4d\x5e"), (2, "0x001A2B3C4D5F"), (3, b"\x00" * 6), (4, b"\x01\x02"), (5, "zz")]),
        walk("admin", [(1, 1), (2, 2), (3, 9)]),
        walk("oper", [(1, 1), (2, 7), (3, 99), (6, "up")]),
        Reading("interface.if_descr", COL["descr"], [(f"{COL['descr']}.1.5", b"x"), (f"{COL['type']}.6", b"y")]),
        Reading("interface.if_mtu", "1.3.6.1.2.1.2.2.1.4", [("1.3.6.1.2.1.2.2.1.4.1", 1500)]),
        Reading("interface.if_descr", COL["descr"], "not a walk"),
    ])
    assert rows[1] == {"name": "GigaEthernet0/1", "type": "ethernet-csmacd", "speed": 1_000_000_000, "mac": "00:1a:2b:3c:4d:5e",
                       "admin": "up", "oper": "up"}
    assert rows[2] == {"name": "Vlan1", "type": "135", "mac": "00:1a:2b:3c:4d:5f", "admin": "down", "oper": "lowerLayerDown"}
    assert rows[3] == {"name": "\ufffd\ufffd", "admin": "unknown", "oper": "unknown"}  # an unknown code is unknown, not dropped
    assert 4 not in rows and 5 not in rows and 6 not in rows  # empty name, number for a name, bad MAC, text for a status
    assert set(rows) == {1, 2, 3}


def test_a_long_name_is_cut_to_the_column():
    assert len(rows_from([walk("descr", [(1, "x" * 400)])])[1]["name"]) == 160


async def test_a_poll_creates_new_interfaces_and_updates_known_ones(db):
    device = await make_device(db, "sw-1")
    known = await make_interface(db, device, "old-name", 1)
    await db.execute("update interfaces set alias = 'uplink', protected = true, legacy_id = 42 where id = $1::uuid", known)
    await ReadingSink(Pool(db)).write(device, "interface_basic", [
        walk("descr", [(1, "Gi0/1"), (2, "Gi0/2")]), walk("type", [(1, 6), (2, 6)]), walk("speed", [(1, 1_000_000_000)]),
        walk("mac", [(1, b"\x00\x1a\x2b\x3c\x4d\x5e")]), walk("admin", [(1, 1), (2, 1), (3, 1)]), walk("oper", [(1, 1), (2, 2)]),
    ])
    rows = {r["if_index"]: dict(r) for r in await db.fetch(
        "select if_index, name, alias, if_type, speed_bps, mac_address::text as mac, admin_status, oper_status, protected, legacy_id "
        "from interfaces where device_id = $1::uuid", device)}
    assert rows[1] == {"if_index": 1, "name": "Gi0/1", "alias": "uplink", "if_type": "ethernet-csmacd", "speed_bps": 1_000_000_000,
                       "mac": "00:1a:2b:3c:4d:5e", "admin_status": "up", "oper_status": "up", "protected": True, "legacy_id": 42}
    assert rows[2]["name"] == "Gi0/2" and (rows[2]["admin_status"], rows[2]["oper_status"]) == ("up", "down")
    assert 3 not in rows  # a status alone names no interface
    history = await db.fetch("select i.if_index, h.admin_status, h.oper_status from interface_status_history h "
                             "join interfaces i on i.id = h.interface_id where i.device_id = $1::uuid order by i.if_index", device)
    assert [tuple(h) for h in history] == [(1, "up", "up"), (2, "up", "down")]


async def test_a_missing_value_keeps_the_stored_one_and_only_real_changes_count(db):
    device = await make_device(db, "sw-2")
    iface = await make_interface(db, device, "Gi0/1", 1)
    await db.execute("update interfaces set speed_bps = 100000000, if_type = 'ethernet-csmacd', mac_address = '00:00:00:00:00:01', "
                     "admin_status = 'up', oper_status = 'up' where id = $1::uuid", iface)
    same = await apply(db, device, rows_from([walk("descr", [(1, "Gi0/1")]), walk("oper", [(1, 1)]), walk("speed", [(1, IF_SPEED_MAX)])]))
    assert same == {"created": 0, "updated": 0, "status_changed": 0}
    changed = await apply(db, device, rows_from([walk("oper", [(1, 2)])]))  # admin not read this time: kept
    assert changed == {"created": 0, "updated": 0, "status_changed": 1}
    row = await db.fetchrow("select name, speed_bps, if_type, mac_address::text as mac, admin_status, oper_status from interfaces where id = $1::uuid", iface)
    assert dict(row) == {"name": "Gi0/1", "speed_bps": 100000000, "if_type": "ethernet-csmacd", "mac": "00:00:00:00:00:01",
                         "admin_status": "up", "oper_status": "down"}
    renamed = await apply(db, device, rows_from([walk("descr", [(1, "GigaEthernet0/1")])]))
    assert renamed["updated"] == 1


async def test_an_interface_absent_from_the_poll_is_left_alone(db):
    device = await make_device(db, "sw-3")
    kept = await make_interface(db, device, "Gi0/9", 9)
    await apply(db, device, rows_from([walk("descr", [(1, "Gi0/1")])]))
    assert await db.fetchval("select name from interfaces where id = $1::uuid", kept) == "Gi0/9"
    assert await apply(db, device, {}) == {"created": 0, "updated": 0, "status_changed": 0}


async def test_a_new_interface_gets_its_first_counter_sample_on_the_same_poll(db):
    device = await make_device(db, "sw-4")
    await ReadingSink(Pool(db)).write(device, "interface_basic", [
        walk("descr", [(7, "Gi0/7")]),
        Reading("interface.if_in_octets", "1.3.6.1.2.1.2.2.1.10", [("1.3.6.1.2.1.2.2.1.10.7", 10)]),
    ])
    assert await db.fetchval("select count(*) from interface_counter_samples s join interfaces i on i.id = s.interface_id "
                             "where i.device_id = $1::uuid and i.if_index = 7", device) == 1


async def test_another_devices_interfaces_are_never_touched(db):
    mine, other = await make_device(db, "sw-5"), await make_device(db, "sw-6")
    theirs = await make_interface(db, other, "theirs", 1)
    await apply(db, mine, rows_from([walk("descr", [(1, "mine")]), walk("oper", [(1, 2)])]))
    assert await db.fetchval("select name || '/' || oper_status from interfaces where id = $1::uuid", theirs) == "theirs/unknown"
