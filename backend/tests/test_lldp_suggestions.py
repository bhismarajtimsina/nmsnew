"""Plan 27's LLDP link suggestions: one suggestion per adjacency seen from either side, interfaces from the reported
port, existing links left out, conflicts marked, accept refused unless the stored data supports it, and external
neighbour names. Neighbours are stored by hand through the sink; no device is contacted."""
import json

from app.topology.lldp import same_link, suggest_links
from app.topology.sink import ReadingSink
from tests.helpers import bearer, make_device, make_group, make_interface, make_user
from tests.test_lldp import Pool, local, remote

S, L = "/api/v1/topology/lldp/suggestions", "/api/v1/links"


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


async def pair(db, group_a=None, group_b=None):
    """acc-1 Gi0/1 <-> agg-1 Gi0/24; agg-1's interfaces carry its chassis MAC."""
    acc, agg = await make_device(db, "acc-1", group_a), await make_device(db, "agg-1", group_b)
    acc_if = await make_interface(db, acc, "GigaEthernet0/1", 1)
    agg_if = await make_interface(db, agg, "Gi0/24", 24)
    await db.execute("update interfaces set mac_address = '00:1a:2b:3c:4d:5e' where id = $1::uuid", agg_if)
    acc_mac_if = await make_interface(db, acc, "Vlan1", 100)
    await db.execute("update interfaces set mac_address = '00:00:00:00:0a:cc' where id = $1::uuid", acc_mac_if)
    return acc, agg, acc_if, agg_if


async def test_an_adjacency_seen_from_both_sides_is_one_suggestion_with_both_interfaces(app_client, db):
    acc, agg, acc_if, agg_if = await pair(db)
    sink = ReadingSink(Pool(db))
    await sink.write(acc, "p", remote(1, 1) + local(1))  # acc sees agg on Gi0/24
    await sink.write(agg, "p", remote(24, 1, chassis=b"\x00\x00\x00\x00\x0a\xcc", port_id=b"GigaEthernet0/1", name=b"acc-1")
                     + local(24, port_id=b"Gi0/24"))
    _, headers = await login(app_client, db)
    items = (await app_client.get(S, headers=headers)).json()["items"]
    assert len(items) == 1
    s = items[0]
    assert {(s["src_device_id"], s["src_interface_id"]), (s["dest_device_id"], s["dest_interface_id"])} == {(acc, acc_if), (agg, agg_if)}
    assert s["seen_from_both_sides"] is True and s["conflict"] is False


async def test_the_remote_interface_falls_back_to_the_port_description_and_one_sided_reports_fold_in(app_client, db):
    acc, agg, acc_if, agg_if = await pair(db)
    # The port id is a MAC, so the description names the port.
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1, port_id=b"\x00\x1a\x2b\x3c\x4d\x5f", port_sub=3, desc=b"Gi0/24") + local(1))
    _, headers = await login(app_client, db)
    (s,) = (await app_client.get(S, headers=headers)).json()["items"]
    assert s["dest_interface_id"] == agg_if and s["src_interface_id"] == acc_if and s["seen_from_both_sides"] is False


async def test_existing_links_are_left_out_and_a_port_linked_elsewhere_is_a_conflict(app_client, db):
    acc, agg, acc_if, agg_if = await pair(db)
    other = await make_device(db, "other")
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1) + local(1))
    _, headers = await login(app_client, db)
    made = await app_client.post(L, headers=headers, json={"src_device_id": other, "dest_device_id": acc, "dest_interface_id": acc_if})
    assert made.status_code == 201
    (s,) = (await app_client.get(S, headers=headers)).json()["items"]
    assert s["conflict"] is True  # acc's Gi0/1 already goes to "other"
    await app_client.delete(f"{L}/{made.json()['id']}", headers=headers)
    await app_client.post(L, headers=headers, json={"src_device_id": agg, "dest_device_id": acc})  # a device-level link
    assert (await app_client.get(S, headers=headers)).json()["items"] == []


async def test_accepting_creates_an_lldp_link_once_and_refuses_anything_unsupported(app_client, db):
    acc, agg, acc_if, agg_if = await pair(db)
    stranger = await make_device(db, "stranger")
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1) + local(1))
    _, headers = await login(app_client, db)
    # Either direction is accepted.
    body = {"src_device_id": agg, "src_interface_id": agg_if, "dest_device_id": acc, "dest_interface_id": acc_if}
    made = await app_client.post(f"{S}/accept", headers=headers, json=body)
    assert made.status_code == 201 and made.json()["source"] == "lldp"
    assert (await app_client.post(f"{S}/accept", headers=headers, json=body)).status_code == 409  # no longer a suggestion
    unsupported = await app_client.post(f"{S}/accept", headers=headers, json={"src_device_id": acc, "dest_device_id": stranger})
    assert unsupported.status_code == 409
    assert await db.fetchval("select count(*) from links") == 1
    audit = json.loads(await db.fetchval("select after from audit_logs where action = 'link.created'"))
    assert audit["source"] == "lldp"


async def test_suggestions_stay_inside_the_callers_scope(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    acc, agg, acc_if, agg_if = await pair(db, group_a=mine, group_b=theirs)
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1) + local(1))
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("links.view", "links.edit"))
    assert (await app_client.get(S, headers=reseller)).json()["items"] == []  # the neighbour is not theirs to see
    refused = await app_client.post(f"{S}/accept", headers=reseller, json={"src_device_id": acc, "src_interface_id": acc_if,
                                                                        "dest_device_id": agg, "dest_interface_id": agg_if})
    assert refused.status_code == 409


async def test_external_neighbours_can_be_named_by_whoever_may_see_the_reporting_device(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    acc = await make_device(db, "acc-1", mine)
    hidden = await make_device(db, "core", theirs)
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1, chassis=b"\x00\x00\x00\x00\x00\x33", name=b"isp-upstream"))
    _, headers = await login(app_client, db)
    (item,) = (await app_client.get(f"/api/v1/topology/lldp/{acc}", headers=headers)).json()["items"]
    assert item["remote_device"] is None and item["external_id"] == f"ext:{acc}:00:00:00:00:00:33" and item["external_name"] is None
    assert (await app_client.put(f"/api/v1/topology/lldp/external-names/{item['external_id']}", headers=headers,
                                 json={"name": "Upstream ISP"})).status_code == 200
    (item,) = (await app_client.get(f"/api/v1/topology/lldp/{acc}", headers=headers)).json()["items"]
    assert item["external_name"] == "Upstream ISP"
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("links.view", "links.edit"))
    ext_hidden = f"ext:{hidden}:00:00:00:00:00:44"
    assert (await app_client.put(f"/api/v1/topology/lldp/external-names/{ext_hidden}", headers=reseller, json={"name": "x"})).status_code == 404
    assert (await app_client.put(f"/api/v1/topology/lldp/external-names/{item['external_id']}", headers=reseller,
                                 json={"name": None})).status_code == 200
    assert await db.fetchval("select count(*) from link_external_names") == 0
    assert (await app_client.put("/api/v1/topology/lldp/external-names/ext:bogus", headers=headers, json={"name": "x"})).status_code == 422


def test_parallel_links_on_different_ports_stay_separate_and_same_link_ignores_direction():
    def row(device, port_num, local_if, port_id):
        return {"device_id": device, "local_interface_id": local_if, "local_port_num": port_num, "port_subtype": "interfaceName",
                "port_id": port_id, "port_description": None}
    remote = {"device_id": "B", "name": "b", "matched_by": "chassis_mac"}
    matched = [(row("A", 1, "a1", "b1"), remote), (row("A", 2, "a2", "b2"), remote)]
    out = suggest_links(matched, {"B": {"b1": "B1", "b2": "B2"}}, [])
    assert {(s["src_interface_id"], s["dest_interface_id"]) for s in out} == {("a1", "B1"), ("a2", "B2")}
    assert same_link(out[0], {"src_device_id": "B", "src_interface_id": out[0]["dest_interface_id"], "dest_device_id": "A",
                              "dest_interface_id": out[0]["src_interface_id"]})
    assert not same_link(out[0], {"src_device_id": "A", "src_interface_id": None, "dest_device_id": "B", "dest_interface_id": None})



def _row(device, port_num, local_if, port_id, subtype="interfaceName"):
    return {"device_id": device, "local_interface_id": local_if, "local_port_num": port_num, "port_subtype": subtype,
            "port_id": port_id, "port_description": None}


A = {"device_id": "A", "name": "a", "matched_by": "system_name"}
B = {"device_id": "B", "name": "b", "matched_by": "chassis_mac"}
IFACES = {"A": {"a1": "A1"}, "B": {"b1": "B1", "b2": "B2"}}


def test_a_report_that_knows_less_folds_into_one_that_knows_more_and_both_sides_count():
    out = suggest_links([(_row("A", 1, "a1", "b1"), B), (_row("B", 7, None, "unknown-port"), A)], IFACES, [])
    assert len(out) == 1 and out[0]["seen_from_both_sides"] is True
    assert {(out[0]["src_interface_id"]), out[0]["dest_interface_id"]} == {"a1", "B1"}


def test_a_report_that_contradicts_another_is_kept_apart():
    out = suggest_links([(_row("A", 1, "a1", "b1"), B), (_row("B", 2, "b2-local", "unknown"), A)], IFACES, [])
    assert len(out) == 2


def test_two_reports_that_each_know_one_end_are_both_kept():
    """(A,a1)-(B,?) and (B,b1)-(A,?): neither covers the other, so both stay for an operator to judge."""
    out = suggest_links([(_row("A", 1, "a1", "nothing"), B), (_row("B", 5, "b1", "nothing"), A)], {"A": {}, "B": {}}, [])
    assert len(out) == 2


def test_a_port_id_that_is_not_a_name_is_not_matched_as_one():
    (s,) = suggest_links([(_row("A", 1, "a1", "b1", subtype="macAddress"), B)], IFACES, [])
    assert s["dest_interface_id"] is None


def test_same_link_needs_both_ends():
    (s,) = suggest_links([(_row("A", 1, "a1", "b1"), B)], IFACES, [])
    assert not same_link(s, {"src_device_id": "A", "src_interface_id": "a1", "dest_device_id": "A", "dest_interface_id": "a1"})


async def test_a_switch_never_matches_itself_by_its_own_mac_or_name(app_client, db):
    acc, agg, acc_if, agg_if = await pair(db)
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1, chassis=b"\x00\x00\x00\x00\x0a\xcc", name=b"x")
                                      + remote(2, 1, chassis=b"\x00\x00\x00\x00\x00\x01", name=b"ACC-1"))
    _, headers = await login(app_client, db)
    items = (await app_client.get(f"/api/v1/topology/lldp/{acc}", headers=headers)).json()["items"]
    assert [i["remote_device"] for i in items] == [None, None]


async def test_only_a_mac_chassis_id_gets_an_external_id(app_client, db):
    acc = await make_device(db, "acc-1")
    await ReadingSink(Pool(db)).write(acc, "p", remote(1, 1, chassis=b"aabbccddeeff", chassis_sub=7, name=b"x"))
    _, headers = await login(app_client, db)
    (item,) = (await app_client.get(f"/api/v1/topology/lldp/{acc}", headers=headers)).json()["items"]
    assert item["external_id"] is None


async def test_accepting_a_self_link_is_refused_in_words(app_client, db):
    acc, agg, acc_if, agg_if = await pair(db)
    _, headers = await login(app_client, db)
    # Not a suggestion at all, so it is refused before any link is attempted.
    body = {"src_device_id": acc, "src_interface_id": acc_if, "dest_device_id": acc, "dest_interface_id": acc_if}
    assert (await app_client.post(f"{S}/accept", headers=headers, json=body)).status_code in (409, 422)
