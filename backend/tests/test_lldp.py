"""Plan 27's LLDP neighbours: decoding by subtype, rows by the MIB's indexes, local ports matched to interfaces, the
polling sink replacing a device's neighbours, and neighbours matched to devices only inside the reader's scope. Readings
are built by hand; no device is contacted."""
import re
from pathlib import Path

import pytest

from app.polling.engine import Reading
from app.topology import lldp
from app.topology.sink import ReadingSink
from tests.helpers import bearer, make_device, make_group, make_interface, make_user

REM, LOC = "1.3.6.1.4.1.3320.127.1.4.1.1", "1.3.6.1.4.1.3320.127.1.3.7.1"
_ROOT = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / "NMS_BDCOM_MIBS").is_dir()), None)


def remote(port, index, *, chassis=b"\x00\x1a\x2b\x3c\x4d\x5e", chassis_sub=4, port_id=b"Gi0/24", port_sub=5, name=b"agg-1", desc=b"uplink"):
    suffix = f"0.{port}.{index}"
    values = {"remote_chassis_id_subtype": (4, chassis_sub), "remote_chassis_id": (5, chassis), "remote_port_id_subtype": (6, port_sub),
              "remote_port_id": (7, port_id), "remote_system_name": (9, name), "remote_port_description": (8, desc)}
    return [Reading(f"bdcom.lldp.{k}", f"{REM}.{col}.{suffix}", v) for k, (col, v) in values.items() if v is not None]


def local(port, port_id=b"GigaEthernet0/1", subtype=5, desc=b"to agg"):
    return [Reading("bdcom.lldp.local_port_id_subtype", f"{LOC}.2.{port}", subtype),
            Reading("bdcom.lldp.local_port_id", f"{LOC}.3.{port}", port_id),
            Reading("bdcom.lldp.local_port_description", f"{LOC}.4.{port}", desc)]


# --- decoding ---

def test_ids_are_decoded_by_their_subtype():
    assert lldp.decode_id(b"\x00\x1a\x2b\x3c\x4d\x5e", 4, mac_subtype=4, address_subtype=5) == "00:1a:2b:3c:4d:5e"
    for text in ("00:1A:2B:3C:4D:5E", "001a2b3c4d5e", "0x001a2b3c4d5e", "00-1a-2b-3c-4d-5e"):
        assert lldp.mac(text) == "00:1a:2b:3c:4d:5e", text
    assert lldp.decode_id(b"\x01\x0a\x00\x00\x01", 5, mac_subtype=4, address_subtype=5) == "10.0.0.1"
    assert lldp.decode_id(b"Gi0/24 ", 5, mac_subtype=3, address_subtype=4) == "Gi0/24"
    assert lldp.decode_id(b"\x00\xff\x10", 7, mac_subtype=3, address_subtype=4) == "00:ff:10"  # not printable: hex
    assert lldp.decode_id(b"short", 4, mac_subtype=4, address_subtype=5) == "short"  # a "MAC" that is not six octets stays visible
    assert lldp.decode_id(b"\x00\x01\x02", 7, mac_subtype=3, address_subtype=4) == "00:01:02"  # valid UTF-8, not printable
    assert lldp.decode_id(None, 4, mac_subtype=4, address_subtype=5) is None


def test_the_subtype_names_are_the_mibs_own():
    if _ROOT is None:
        pytest.skip("NMS_BDCOM_MIBS is not mounted in this test environment")
    text = (_ROOT / "NMS_BDCOM_MIBS" / "NMS-LLDP-MIB.MIB").read_text(errors="ignore")
    for convention, mapping in (("LldpChassisIdSubtype", lldp.CHASSIS_SUBTYPES), ("LldpPortIdSubtype", lldp.PORT_SUBTYPES)):
        body = re.search(rf"^{convention} ::= TEXTUAL-CONVENTION.*?SYNTAX\s+INTEGER\s*\{{(.*?)\}}", text, re.S | re.M).group(1)
        assert {int(n): name for name, n in re.findall(r"(\w+)\((\d+)\)", body)} == mapping


# --- rows ---

def test_neighbours_come_from_the_mib_indexes_with_their_local_port():
    readings = remote(1, 7) + remote(3, 2, chassis=b"\x00\x00\x00\x00\x00\x09", name=b"acc-9", port_id=b"\x00\x00\x00\x00\x00\x01", port_sub=3) + local(1) + local(3, desc=b"to acc")
    found = lldp.neighbours(readings)
    assert [(n.local_port_num, n.remote_index) for n in found] == [(1, 7), (3, 2)]
    first = found[0]
    assert (first.local_port, first.chassis_subtype, first.chassis_id, first.port_subtype, first.port_id, first.system_name,
            first.port_description) == ("GigaEthernet0/1", "macAddress", "00:1a:2b:3c:4d:5e", "interfaceName", "Gi0/24", "agg-1", "uplink")
    assert found[1].port_id == "00:00:00:00:00:01" and found[1].port_subtype == "macAddress"
    # A chassis MAC whose octets happen to be printable is still a MAC, not the text "ABCDEF".
    (printable,) = lldp.neighbours(remote(5, 1, chassis=b"ABCDEF"))
    assert printable.chassis_id == "41:42:43:44:45:46"


def test_rows_that_do_not_fit_the_mib_index_are_dropped_and_a_missing_local_port_falls_back_to_its_description():
    readings = remote(1, 1) + [Reading("bdcom.lldp.remote_system_name", f"{REM}.9.0.5", b"bad-index"),
                               Reading("bdcom.lldp.remote_system_name", f"{REM}.9.0.x.1", b"bad-index"),
                               Reading("bdcom.lldp.remote_system_name", "1.3.6.1.2.1.1.5.0", b"elsewhere")]
    readings += [Reading("bdcom.lldp.local_port_description", f"{LOC}.4.1", b"Gi0/1")]
    # The right column name under another tree, with an index that would otherwise fit: dropped.
    readings += [Reading("bdcom.lldp.remote_system_name", "1.3.6.1.4.1.3320.127.1.4.1.2.9.0.4.1", b"wrong-tree")]
    found = lldp.neighbours(readings)
    assert len(found) == 1 and found[0].system_name == "agg-1" and found[0].local_port == "Gi0/1"


def test_interfaces_are_matched_exactly_or_by_one_case_insensitive_name_never_partially():
    interfaces = {"Gi0/1": "a", "Gi0/10": "b", "TE1": "c", "te1": "d"}
    assert lldp.match_interface("Gi0/1", interfaces) == "a"
    assert lldp.match_interface("gi0/10", interfaces) == "b"
    assert lldp.match_interface("Te1", interfaces) is None  # two case-insensitive candidates
    assert lldp.match_interface("te1", interfaces) == "d"  # but an exact name wins
    assert lldp.match_interface("Gi0/", interfaces) is None and lldp.match_interface(None, interfaces) is None


# --- storage and reading ---

async def login(app_client, db, name="op", role="ISP Admin", group=None, grant=()):
    user = await make_user(db, name, role)
    for code in grant:
        await db.execute("insert into role_permissions (role_id, permission_id) select role_id, (select id from permissions where code = $2) "
                         "from users where id = $1::uuid on conflict do nothing", user, code)
    if group is not None:
        await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user, headers


class Pool:
    """The sink only needs acquire(); hand it the test connection."""

    def __init__(self, conn):
        self.conn = conn

    def acquire(self):
        conn = self.conn

        class _Ctx:
            async def __aenter__(self):
                return conn

            async def __aexit__(self, *exc):
                return False
        return _Ctx()


async def test_the_sink_replaces_a_devices_neighbours_and_ignores_other_readings(db):
    switch = await make_device(db, "acc-1")
    port = await make_interface(db, switch, "GigaEthernet0/1", 1)
    sink = ReadingSink(Pool(db))
    await sink.write(switch, "bdcom_switch_basic", remote(1, 1) + remote(2, 1, name=b"other") + local(1))
    # A poll with no LLDP readings (another profile) leaves the stored neighbours alone.
    await sink.write(switch, "bdcom_switch_basic", [Reading("bdcom.system.serial_number", "1.3.6.1.4.1.3320.9.225.1.2.0", "X")])
    rows = await db.fetch("select local_port_num, local_interface_id, system_name from lldp_neighbours order by local_port_num")
    assert [(r["local_port_num"], str(r["local_interface_id"]) if r["local_interface_id"] else None, r["system_name"]) for r in rows] == [
        (1, port, "agg-1"), (2, None, "other")]
    await sink.write(switch, "bdcom_switch_basic", remote(1, 1))  # the next poll sees one neighbour: the other is gone
    assert await db.fetchval("select count(*) from lldp_neighbours") == 1


async def test_neighbours_are_matched_to_visible_devices_by_mac_then_by_a_unique_name(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    switch = await make_device(db, "acc-1", mine)
    agg = await make_device(db, "agg-1", mine)
    agg_port = await make_interface(db, agg, "Gi0/24", 24)
    await db.execute("update interfaces set mac_address = '001A.2B3C.4D5E' where id = $1::uuid", agg_port)  # another notation
    named = await make_device(db, "acc-9", mine)
    hidden = await make_device(db, "core-hidden", theirs)
    hidden_port = await make_interface(db, hidden, "Te1/1", 1)
    await db.execute("update interfaces set mac_address = '00:00:00:00:00:77' where id = $1::uuid", hidden_port)
    await ReadingSink(Pool(db)).write(switch, "p", remote(1, 1) + remote(2, 1, chassis=b"\x00\x00\x00\x00\x00\x99", name=b"ACC-9")
                                      + remote(3, 1, chassis=b"\x00\x00\x00\x00\x00\x77", name=b"core-hidden"))
    _, admin = await login(app_client, db, "adm")
    items = (await app_client.get(f"/api/v1/topology/lldp/{switch}", headers=admin)).json()["items"]
    matched = [(i["system_name"], i["remote_device"] and (i["remote_device"]["name"], i["remote_device"]["matched_by"])) for i in items]
    assert matched == [("agg-1", ("agg-1", "chassis_mac")), ("ACC-9", ("acc-9", "system_name")), ("core-hidden", ("core-hidden", "chassis_mac"))]
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("links.view",))
    items = (await app_client.get(f"/api/v1/topology/lldp/{switch}", headers=reseller)).json()["items"]
    assert items[2]["remote_device"] is None and hidden not in str(items)  # what LLDP reported is theirs; our record of it is not
    assert (await app_client.get(f"/api/v1/topology/lldp/{hidden}", headers=reseller)).status_code == 404
    assert named  # matched by name above


async def test_an_ambiguous_name_matches_nothing(app_client, db):
    switch = await make_device(db, "acc-1")
    await make_device(db, "twin")
    await db.execute("update devices set name = 'TWIN' where name = 'twin'")
    await make_device(db, "twin")
    await ReadingSink(Pool(db)).write(switch, "p", remote(1, 1, chassis=b"\x00\x00\x00\x00\x00\x55", name=b"twin"))
    _, admin = await login(app_client, db, "adm")
    assert (await app_client.get(f"/api/v1/topology/lldp/{switch}", headers=admin)).json()["items"][0]["remote_device"] is None


async def test_a_mac_shared_by_two_devices_matches_neither(app_client, db):
    switch = await make_device(db, "acc-1")
    for name in ("left", "right"):
        dev = await make_device(db, name)
        port = await make_interface(db, dev, "Gi0/1", 1)
        await db.execute("update interfaces set mac_address = '00:00:00:00:00:44' where id = $1::uuid", port)
    await ReadingSink(Pool(db)).write(switch, "p", remote(1, 1, chassis=b"\x00\x00\x00\x00\x00\x44", name=b"nobody"))
    _, admin = await login(app_client, db, "adm")
    assert (await app_client.get(f"/api/v1/topology/lldp/{switch}", headers=admin)).json()["items"][0]["remote_device"] is None


async def test_an_id_that_is_not_a_mac_never_matches_by_mac(app_client, db):
    switch = await make_device(db, "acc-1")
    dev = await make_device(db, "target")
    port = await make_interface(db, dev, "Gi0/1", 1)
    await db.execute("update interfaces set mac_address = '00:00:00:00:00:77' where id = $1::uuid", port)
    # A locally assigned chassis id that reads like that MAC, beside a real MAC neighbour with the same digits on
    # another port: the real one matches, the look-alike does not.
    await ReadingSink(Pool(db)).write(switch, "p", remote(1, 1, chassis=b"000000000077", chassis_sub=7, name=b"unknown")
                                      + remote(2, 1, chassis=b"\x00\x00\x00\x00\x00\x77", name=b"real"))
    _, admin = await login(app_client, db, "adm")
    items = (await app_client.get(f"/api/v1/topology/lldp/{switch}", headers=admin)).json()["items"]
    assert items[0]["remote_device"] is None and items[1]["remote_device"]["name"] == "target"
