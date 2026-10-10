"""Plan 27's tree views: down trees and upward chains from links, with legacy's direction convention (source is
upstream) and without its bugs (rings repeating, unpinged devices vanishing, arbitrary parents). No device contacted."""
import json

from app.topology.tree import MAX_DEPTH, down_tree, top_of, upward_chain
from tests.helpers import bearer, make_device, make_group, make_user


def end(device, name=None, visible=True):
    return {"device_id": device if visible else None, "name": (name or device) if visible else None, "ip": None,
            "interface_id": None, "interface": f"{device}-if" if visible else None, "visible": visible, "state": "up" if visible else None}


def link(lid, src, dest, src_visible=True, dest_visible=True, state="up"):
    return {"id": lid, "src": end(src, visible=src_visible), "dest": end(dest, visible=dest_visible), "state": state}


def names(tree):
    return [(n["name"], n["repeat"], names(n)) for n in tree["nodes"]]


def test_a_down_tree_follows_source_to_destination_in_name_order():
    links = [link("1", "core", "b"), link("2", "core", "a"), link("3", "a", "a1")]
    tree, truncated = down_tree("core", links)
    assert names(tree) == [("a", False, [("a1", False, [])]), ("b", False, [])] and truncated is False
    assert tree["nodes"][0]["uplink_interface"] == "a-if" and tree["nodes"][0]["downlink_interface"] == "core-if"


def test_a_ring_expands_each_device_once():
    ring = [link("1", "core", "a"), link("2", "a", "b"), link("3", "b", "c"), link("4", "c", "core")]
    tree, _ = down_tree("core", ring)
    assert names(tree) == [("a", False, [("b", False, [("c", False, [("core", True, [])])])])]


def test_depth_is_limited_and_says_so():
    chain = [link(str(i), f"d{i:02}", f"d{i + 1:02}") for i in range(MAX_DEPTH + 3)]
    tree, truncated = down_tree("d00", chain)
    depth, node = 0, tree
    while node["nodes"]:
        node, depth = node["nodes"][0], depth + 1
    assert depth == MAX_DEPTH and truncated is True
    assert down_tree("d00", chain[:3])[1] is False


def test_a_hidden_device_is_shown_but_never_expanded_or_walked_through():
    links = [link("1", "a", "x", dest_visible=False), link("2", "x", "b", src_visible=False)]
    tree, _ = down_tree("a", links)
    assert names(tree) == [(None, False, [])]
    assert upward_chain("b", links)[-1]["visible"] is False and len(upward_chain("b", links)) == 1
    assert top_of("b", links) == "b"  # nothing visible above it


def test_walking_up_takes_parents_in_a_fixed_order_and_stops_at_loops():
    links = [link("1", "zeta", "leaf"), link("2", "alpha", "leaf"), link("3", "core", "alpha")]
    chain = upward_chain("leaf", links)
    assert [(s["name"], s["depth"], s["multiple_parents"]) for s in chain] == [("alpha", 1, True), ("core", 2, False)]
    assert top_of("leaf", links) == "core" and top_of("core", links) == "core"
    loop = [link("1", "a", "b"), link("2", "b", "a")]
    steps = upward_chain("a", loop)
    assert [(s["name"], s["loop"]) for s in steps] == [("b", False), ("a", True)]
    assert top_of("a", loop) == "b"


# --- the API ---

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


async def test_trees_from_the_api_include_unpinged_devices_and_find_the_top(app_client, db):
    _, headers = await login(app_client, db)
    core, agg, acc = [await make_device(db, n) for n in ("core", "agg", "acc")]
    for src, dest in ((core, agg), (agg, acc)):
        assert (await app_client.post("/api/v1/links", headers=headers, json={"src_device_id": src, "dest_device_id": dest})).status_code == 201
    down = (await app_client.get(f"/api/v1/topology/tree/{core}", headers=headers)).json()
    assert down["tree"]["nodes"][0]["name"] == "agg" and down["tree"]["nodes"][0]["nodes"][0]["name"] == "acc"  # none has a ping row
    up = (await app_client.get(f"/api/v1/topology/tree/{acc}?direction=up", headers=headers)).json()
    assert (up["build_from"], up["is_top"]) == (core, True) and up["tree"]["name"] == "core"
    chain = (await app_client.get(f"/api/v1/topology/upward/{acc}", headers=headers)).json()["steps"]
    assert [(s["name"], s["depth"]) for s in chain] == [("agg", 1), ("core", 2)]
    assert (await app_client.get(f"/api/v1/topology/tree/{acc}?direction=sideways", headers=headers)).status_code == 422


async def test_a_reseller_tree_stops_at_devices_outside_its_scope(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    _, admin = await login(app_client, db, "adm")
    core = await make_device(db, "core", theirs)
    agg, acc = await make_device(db, "agg", mine), await make_device(db, "acc", mine)
    other = await make_device(db, "other-customer", theirs)
    for src, dest in ((core, agg), (agg, acc), (core, other)):
        await app_client.post("/api/v1/links", headers=admin, json={"src_device_id": src, "dest_device_id": dest})
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("links.view",))
    up = (await app_client.get(f"/api/v1/topology/tree/{acc}?direction=up", headers=reseller)).json()
    assert up["build_from"] == agg and up["is_top"] is False  # something is above, but not theirs to see
    chain = (await app_client.get(f"/api/v1/topology/upward/{acc}", headers=reseller)).json()["steps"]
    assert [s["name"] for s in chain] == ["agg", None] and chain[-1]["visible"] is False
    text = json.dumps(up) + json.dumps(chain)
    assert "core" not in text and "other-customer" not in text and core not in text
    assert (await app_client.get(f"/api/v1/topology/tree/{other}", headers=reseller)).status_code == 404
