"""Plan 27's links: state from interface and ping status, CRUD with both ends in scope, one row per pair of ends, a
reseller's view with the far end masked, and the graph. Database only; no device is contacted."""
import json

import asyncpg
import pytest

from app.topology.state import end_state, link_state
from tests.helpers import bearer, make_device, make_group, make_interface, make_user

L = "/api/v1/links"


# --- state ---

@pytest.mark.parametrize("ping, bound, oper, admin, expected", [
    ("down", True, "up", "up", "down"),            # an unreachable device's interface status is stale
    ("up", True, "up", "up", "up"),
    ("up", True, "down", "up", "down"),
    ("up", True, "lowerLayerDown", "up", "down"),
    ("up", True, "notPresent", "up", "down"),
    ("up", True, "up", "down", "down"),            # administratively shut
    ("up", True, "dormant", "up", "unknown"),
    ("up", True, None, None, "unknown"),
    ("unknown", True, "up", "up", "up"),           # a measured interface answers even if the pinger has not yet
    ("up", False, None, None, "up"),               # no interface named: the device's reachability
    ("unknown", False, None, None, "unknown"),
    (None, False, None, None, "unknown"),
])
def test_each_end_takes_its_interface_status_with_ping_first(ping, bound, oper, admin, expected):
    assert end_state(ping=ping, interface_bound=bound, oper_status=oper, admin_status=admin) == expected


def test_a_link_is_up_only_when_both_ends_are():
    assert link_state("up", "up") == "up"
    assert link_state("up", "down") == link_state("unknown", "down") == "down"
    assert link_state("up", "unknown") == "unknown"


# --- helpers ---

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


async def ping(db, device, status):
    await db.execute("insert into device_ping_status (device_id, status) values ($1::uuid, $2) "
                     "on conflict (device_id) do update set status = $2", device, status)


async def pair(db, group=None, group_b=None):
    a = await make_device(db, "core-a", group)
    b = await make_device(db, "access-b", group_b if group_b is not None else group)
    return a, b, await make_interface(db, a, "Gi0/1", 1), await make_interface(db, b, "Gi0/24", 24)


# --- CRUD ---

async def test_a_link_is_created_with_its_state_audited_and_listed_by_device(app_client, db):
    a, b, ia, ib = await pair(db)
    await db.execute("update interfaces set oper_status = 'up', admin_status = 'up'")
    await ping(db, a, "up")
    await ping(db, b, "up")
    _, headers = await login(app_client, db)
    made = await app_client.post(L, headers=headers, json={"src_device_id": a, "src_interface_id": ia, "dest_device_id": b,
                                                           "dest_interface_id": ib, "description": "uplink"})
    assert made.status_code == 201, made.text
    link = made.json()
    assert link["state"] == "up" and link["source"] == "manual"
    assert (link["src"]["name"], link["src"]["interface"], link["dest"]["interface"]) == ("core-a", "Gi0/1", "Gi0/24")
    other = await make_device(db, "other")
    await app_client.post(L, headers=headers, json={"src_device_id": a, "dest_device_id": other})  # does not touch b
    await db.execute("update interfaces set oper_status = 'down' where id = $1::uuid", ib)
    listed = (await app_client.get(f"{L}?device_id={b}", headers=headers)).json()["items"]
    assert [l["id"] for l in listed] == [link["id"]] and listed[0]["state"] == "down" and listed[0]["dest"]["state"] == "down"
    audit = await db.fetchrow("select after from audit_logs where action = 'link.created'")
    assert json.loads(audit["after"])["description"] == "uplink"


async def test_the_same_ends_are_stored_once_whichever_way_round(app_client, db):
    a, b, ia, ib = await pair(db)
    _, headers = await login(app_client, db)
    body = {"src_device_id": a, "src_interface_id": ia, "dest_device_id": b, "dest_interface_id": ib}
    assert (await app_client.post(L, headers=headers, json=body)).status_code == 201
    assert (await app_client.post(L, headers=headers, json=body)).status_code == 409
    reversed_ = {"src_device_id": b, "src_interface_id": ib, "dest_device_id": a, "dest_interface_id": ia}
    assert (await app_client.post(L, headers=headers, json=reversed_)).status_code == 409
    # Device-level links (no interfaces) between the same devices also count once.
    plain = {"src_device_id": a, "dest_device_id": b}
    assert (await app_client.post(L, headers=headers, json=plain)).status_code == 201
    assert (await app_client.post(L, headers=headers, json={"src_device_id": b, "dest_device_id": a})).status_code == 409


async def test_interfaces_must_belong_to_their_device_and_never_link_to_themselves(app_client, db):
    a, b, ia, ib = await pair(db)
    _, headers = await login(app_client, db)
    wrong = await app_client.post(L, headers=headers, json={"src_device_id": a, "src_interface_id": ib, "dest_device_id": b})
    assert wrong.status_code == 422 and "does not belong" in wrong.json()["detail"]
    itself = await app_client.post(L, headers=headers, json={"src_device_id": a, "src_interface_id": ia, "dest_device_id": a,
                                                             "dest_interface_id": ia})
    assert itself.status_code == 422
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("insert into links (src_device_id, src_interface_id, dest_device_id, dest_interface_id) "
                         "values ($1::uuid, $2::uuid, $1::uuid, $2::uuid)", a, ia)


async def test_a_link_can_be_rebound_and_deleted_with_audit(app_client, db):
    a, b, ia, ib = await pair(db)
    ib2 = await make_interface(db, b, "Gi0/23", 23)
    _, headers = await login(app_client, db)
    link = (await app_client.post(L, headers=headers, json={"src_device_id": a, "src_interface_id": ia, "dest_device_id": b,
                                                            "dest_interface_id": ib})).json()
    moved = await app_client.put(f"{L}/{link['id']}", headers=headers, json={"dest_interface_id": ib2, "description": "moved"})
    assert moved.status_code == 200 and moved.json()["dest"]["interface"] == "Gi0/23"
    unbound = await app_client.put(f"{L}/{link['id']}", headers=headers, json={"src_interface_id": None})
    assert unbound.json()["src"]["interface_id"] is None
    wrong = await app_client.put(f"{L}/{link['id']}", headers=headers, json={"src_interface_id": ib})
    assert wrong.status_code == 422
    assert (await app_client.delete(f"{L}/{link['id']}", headers=headers)).status_code == 200
    assert (await app_client.get(f"{L}/{link['id']}", headers=headers)).status_code == 404
    actions = [r["action"] for r in await db.fetch("select action from audit_logs where action like 'link.%' order by occurred_at")]
    assert actions == ["link.created", "link.updated", "link.updated", "link.deleted"]


async def test_deleting_a_device_removes_its_links_and_an_interface_only_unbinds(app_client, db):
    a, b, ia, ib = await pair(db)
    c = await make_device(db, "spare")
    _, headers = await login(app_client, db)
    first = (await app_client.post(L, headers=headers, json={"src_device_id": a, "src_interface_id": ia, "dest_device_id": b,
                                                             "dest_interface_id": ib})).json()
    await app_client.post(L, headers=headers, json={"src_device_id": c, "dest_device_id": b})
    await db.execute("delete from interfaces where id = $1::uuid", ia)
    assert (await app_client.get(f"{L}/{first['id']}", headers=headers)).json()["src"]["interface_id"] is None
    await db.execute("delete from devices where id = $1::uuid", c)
    assert await db.fetchval("select count(*) from links") == 1


# --- scope ---

async def test_a_reseller_sees_its_side_of_a_link_and_only_the_state_of_the_far_side(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    a, b, ia, ib = await pair(db, group=theirs, group_b=mine)  # core-a outside, access-b inside
    hidden_only = await make_device(db, "elsewhere", theirs)
    await db.execute("update interfaces set oper_status = 'up', admin_status = 'up'")
    await ping(db, a, "down")
    await ping(db, b, "up")
    _, admin = await login(app_client, db, "adm")
    uplink = (await app_client.post(L, headers=admin, json={"src_device_id": a, "src_interface_id": ia, "dest_device_id": b,
                                                            "dest_interface_id": ib})).json()
    await app_client.post(L, headers=admin, json={"src_device_id": a, "dest_device_id": hidden_only})
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("links.view", "links.edit"))
    items = (await app_client.get(L, headers=reseller)).json()["items"]
    assert [l["id"] for l in items] == [uplink["id"]]  # a link with no visible end is not listed at all
    link = items[0]
    assert link["src"] == {"device_id": None, "name": None, "ip": None, "interface_id": None, "interface": None, "visible": False, "state": None}
    assert link["dest"]["name"] == "access-b" and link["state"] == "down"  # the uplink is down; why is not theirs to see
    text = json.dumps(items)
    assert "core-a" not in text and a not in text and ia not in text
    # Changing or deleting needs both ends in scope.
    assert (await app_client.put(f"{L}/{uplink['id']}", headers=reseller, json={"description": "x"})).status_code == 404
    assert (await app_client.delete(f"{L}/{uplink['id']}", headers=reseller)).status_code == 404
    assert (await app_client.post(L, headers=reseller, json={"src_device_id": b, "dest_device_id": a})).status_code == 404


async def test_the_graph_hides_outside_devices_behind_one_placeholder_per_link(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    outside = await make_device(db, "core", theirs)
    b1, b2 = await make_device(db, "acc-1", mine), await make_device(db, "acc-2", mine)
    _, admin = await login(app_client, db, "adm")
    for dest in (b1, b2):
        await app_client.post(L, headers=admin, json={"src_device_id": outside, "dest_device_id": dest})
    await app_client.post(L, headers=admin, json={"src_device_id": b1, "dest_device_id": b2})
    full = (await app_client.get("/api/v1/topology/graph", headers=admin)).json()
    assert len(full["nodes"]) == 3 and len(full["edges"]) == 3 and all(n["visible"] for n in full["nodes"])
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("links.view",))
    graph = (await app_client.get("/api/v1/topology/graph", headers=reseller)).json()
    hidden = [n for n in graph["nodes"] if not n["visible"]]
    assert len(hidden) == 2 and all(n["name"] is None and n["ip"] is None for n in hidden)  # not merged into one "core"
    assert {n["name"] for n in graph["nodes"] if n["visible"]} == {"acc-1", "acc-2"}
    assert {(e["from"], e["to"]) for e in graph["edges"]} >= {(b1, b2)}
    assert outside not in json.dumps(graph)


async def test_links_need_their_permissions(app_client, db):
    a, b, _, _ = await pair(db)
    user, headers = await login(app_client, db)
    await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                     "and permission_id in (select id from permissions where code in ('links.edit', 'links.view'))", user)
    assert (await app_client.post(L, headers=headers, json={"src_device_id": a, "dest_device_id": b})).status_code == 403
    assert (await app_client.get(L, headers=headers)).status_code == 403
    assert (await app_client.get("/api/v1/topology/graph", headers=headers)).status_code == 403
