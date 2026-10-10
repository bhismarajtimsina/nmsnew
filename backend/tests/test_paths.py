"""Plan 27's transport paths: hop, path and group state (legacy's rules plus link state), the route check, the
scheduler's stored states and last-change times, legacy's metrics, and scope. Database only; no device is contacted."""
import json

import pytest

from app.core.config import settings
from app.topology.path_service import refresh_states, render_metrics
from app.topology.paths import Endpoint, Hop, chain_gaps, group_state, hop_state, path_state
from tests.helpers import bearer, make_device, make_group, make_interface, make_user

P, L = "/api/v1/paths", "/api/v1/links"


def hop(link_state="up", pings=("up", "up"), latencies=(5.0, 5.0), position=1):
    return Hop(position, f"l{position}", link_state, tuple(Endpoint(f"d{i}", p, lat) for i, (p, lat) in enumerate(zip(pings, latencies))))


# --- the calculator ---

@pytest.mark.parametrize("h, expected", [
    (hop(), ("up", None)),
    (hop(link_state=None), ("unknown", "link_missing")),
    (hop(link_state="down"), ("down", "link_down")),
    (hop(pings=("up", "down"), latencies=(5.0, None)), ("down", "device_unreachable")),
    (hop(pings=("up", "unknown")), ("unknown", "unmeasured")),
    (hop(pings=("up", None), latencies=(5.0, None)), ("unknown", "unmeasured")),
    (hop(latencies=(5.0, None)), ("unknown", "unmeasured")),
    (hop(link_state="unknown"), ("unknown", "unmeasured")),
    (hop(latencies=(5.0, 151.0)), ("degraded", "slow")),
    (hop(latencies=(150.0, 150.0)), ("up", None)),        # at the threshold is not above it
])
def test_each_hop_follows_legacys_rules_plus_its_links_state(h, expected):
    assert hop_state(h, 150) == expected


def test_a_threshold_of_zero_turns_degradation_off():
    assert hop_state(hop(latencies=(5000.0, 5.0)), 0) == ("up", None)


def test_a_path_is_its_worst_hop_and_never_up_while_part_is_unmeasured():
    assert path_state([], 150)[0] == "unknown" and path_state([], 150)[1]["reason"] == "no_segments"
    assert path_state([hop(), hop(latencies=(5.0, 400.0), position=2)], 150)[0] == "degraded"
    assert path_state([hop(latencies=(5.0, 400.0)), hop(pings=("up", "unknown"), position=2)], 150)[0] == "unknown"
    assert path_state([hop(pings=("up", "unknown")), hop(link_state="down", position=2)], 150)[0] == "down"
    state, detail = path_state([hop(position=2), hop(link_state="down", position=1)], 150)
    assert [h["position"] for h in detail["hops"]] == [1, 2] and detail["hops"][0]["reason"] == "link_down"


def test_groups_are_judged_by_how_many_paths_are_usable():
    assert group_state([("a", "A", 1, "up"), ("b", "B", 2, "degraded")])["state"] == "protected"
    assert group_state([("a", "A", 1, "up"), ("b", "B", 2, "down")])["state"] == "unprotected"
    assert group_state([("a", "A", 1, "unknown"), ("b", "B", 2, "down")])["state"] == "outage"
    single = group_state([("a", "A", 1, "up")])
    assert single["state"] == "up" and single["redundant"] is False and single["protected"] is False
    assert [m["name"] for m in group_state([("b", "B", 2, "up"), ("a", "A", 1, "up")])["members"]] == ["A", "B"]


def test_the_route_check_walks_links_either_way_round():
    assert chain_gaps("A", "C", [("A", "B"), ("C", "B")]) == []
    assert chain_gaps("A", "C", [("B", "A"), ("B", "C")]) == []
    assert chain_gaps("A", "C", [("A", "B"), ("D", "C")]) == ["segment 2 does not start where the route has reached"]
    assert chain_gaps("A", "C", [("A", "B")]) == ["the route does not end at endpoint B"]
    assert chain_gaps("A", "C", []) == []


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


async def ping(db, device, status="up", latency=5.0):
    await db.execute("insert into device_ping_status (device_id, status, latency_ms) values ($1::uuid, $2, $3) "
                     "on conflict (device_id) do update set status = $2, latency_ms = $3", device, status, latency)


async def chain(app_client, db, headers, groups=(None, None, None)):
    """Three devices A - B - C, two links with interfaces, all up."""
    a, b, c = [await make_device(db, n, g) for n, g in zip(("site-a", "site-b", "site-c"), groups)]
    ifs = {}
    for dev, names in ((a, ["a1"]), (b, ["b1", "b2"]), (c, ["c1"])):
        for i, n in enumerate(names, 1):
            ifs[n] = await make_interface(db, dev, n, i)
    await db.execute("update interfaces set oper_status = 'up', admin_status = 'up'")
    for dev in (a, b, c):
        await ping(db, dev)
    ab = (await app_client.post(L, headers=headers, json={"src_device_id": a, "src_interface_id": ifs["a1"], "dest_device_id": b,
                                                          "dest_interface_id": ifs["b1"]})).json()["id"]
    cb = (await app_client.post(L, headers=headers, json={"src_device_id": c, "src_interface_id": ifs["c1"], "dest_device_id": b,
                                                          "dest_interface_id": ifs["b2"]})).json()["id"]
    return a, b, c, ab, cb, ifs


async def new_path(app_client, headers, a, c, **extra):
    made = await app_client.post(P, headers=headers, json={"name": extra.pop("name", "A-C"), "endpoint_a_id": a, "endpoint_b_id": c, **extra})
    assert made.status_code == 201, made.text
    return made.json()


# --- the API ---

async def test_a_path_is_built_from_an_unbroken_route_and_its_state_computed_now(app_client, db):
    _, headers = await login(app_client, db)
    a, b, c, ab, cb, ifs = await chain(app_client, db, headers)
    path = await new_path(app_client, headers, a, c)
    assert path["live_state"] == "unknown" and path["hops"] == []
    broken = await app_client.put(f"{P}/{path['id']}/segments", headers=headers, json={"link_ids": [cb, ab]})
    assert broken.status_code == 422 and "segment 1" in broken.json()["detail"]
    assert await db.fetchval("select count(*) from path_segments") == 0  # the refused change left nothing behind
    assert (await app_client.put(f"{P}/{path['id']}/segments", headers=headers, json={"link_ids": [ab, ab]})).status_code == 422
    done = await app_client.put(f"{P}/{path['id']}/segments", headers=headers, json={"link_ids": [ab, cb]})
    assert done.status_code == 200, done.text
    body = done.json()
    assert body["live_state"] == "up" and [h["position"] for h in body["hops"]] == [1, 2] and body["segments"] == 2
    await ping(db, c, latency=400.0)
    assert (await app_client.get(f"{P}/{path['id']}", headers=headers)).json()["live_state"] == "degraded"
    await db.execute("update interfaces set oper_status = 'down' where id = $1::uuid", ifs["b2"])
    detail = (await app_client.get(f"{P}/{path['id']}", headers=headers)).json()
    assert detail["live_state"] == "down" and detail["hops"][1]["reason"] == "link_down"
    actions = [r["action"] for r in await db.fetch("select action from audit_logs where action like 'path.%' order by occurred_at")]
    assert actions == ["path.created", "path.segments_set"]


async def test_the_scheduler_stores_states_moves_last_change_only_on_change_and_drops_disabled_paths(app_client, db):
    _, headers = await login(app_client, db)
    a, b, c, ab, cb, ifs = await chain(app_client, db, headers)
    path = await new_path(app_client, headers, a, c, group_key="a-c")
    await app_client.put(f"{P}/{path['id']}/segments", headers=headers, json={"link_ids": [ab, cb]})
    assert await refresh_states(db, 150) == (1, 1)
    first = await db.fetchrow("select state, last_change from path_states")
    assert first["state"] == "up"
    assert await refresh_states(db, 150) == (1, 0)
    assert await db.fetchval("select last_change from path_states") == first["last_change"]
    await ping(db, b, "down", None)
    assert await refresh_states(db, 150) == (1, 1)
    second = await db.fetchrow("select state, last_change from path_states")
    assert second["state"] == "down" and second["last_change"] > first["last_change"]
    listed = (await app_client.get(P, headers=headers)).json()["items"]
    assert listed[0]["state"] == "down"
    await app_client.put(f"{P}/{path['id']}", headers=headers, json={"enabled": False})
    await refresh_states(db, 150)
    assert await db.fetchval("select count(*) from path_states") == 0


async def test_metrics_follow_legacy_names_and_values_and_drop_stale_states(app_client, db):
    _, headers = await login(app_client, db)
    a, b, c, ab, cb, ifs = await chain(app_client, db, headers)
    one = await new_path(app_client, headers, a, c, name='Main "north"', group_key="a-c")
    two = await new_path(app_client, headers, a, c, name="Backup", group_key="a-c")
    solo = await new_path(app_client, headers, a, b, name="Solo", group_key="alone")
    await app_client.put(f"{P}/{one['id']}/segments", headers=headers, json={"link_ids": [ab, cb]})
    await app_client.put(f"{P}/{solo['id']}/segments", headers=headers, json={"link_ids": [ab]})
    await refresh_states(db, 150)  # Backup has no segments: unknown, so the group is unprotected
    text = (await app_client.get("/metrics")).text
    assert f'path_state{{path_id="{one["id"]}",path_name="Main \\"north\\"",group_key="a-c",endpoint_a_name="site-a",endpoint_b_name="site-c"}} 1' in text
    assert f'path_state{{path_id="{two["id"]}",path_name="Backup",group_key="a-c",endpoint_a_name="site-a",endpoint_b_name="site-c"}} -1' in text
    assert 'path_group_protected{group_key="a-c"} 0' in text and 'path_group_up_count{group_key="a-c"} 1' in text
    assert 'path_group_total{group_key="a-c"} 2' in text and 'group_key="alone"} ' not in text.split("path_group_protected", 1)[1]
    await db.execute("update path_states set updated_at = now() - make_interval(secs => $1)", settings.paths_state_metric_ttl_seconds + 5)
    assert "path_state{" not in (await app_client.get("/metrics")).text


def test_metric_rendering_counts_down_segments_and_escapes_labels():
    rows = [{"id": "p1", "name": "a\\b\nc", "group_key": None, "endpoint_a_name": "x", "endpoint_b_name": "y", "state": "down",
             "detail": json.dumps({"hops": [{"state": "down"}, {"state": "up"}, {"state": "down"}]})}]
    text = render_metrics(rows)
    assert 'path_name="a\\\\b\\nc"' in text and 'group_key="none"' in text
    assert 'path_segments_down{path_id="p1",path_name="a\\\\b\\nc",group_key="none"} 2' in text and "} 0\n" in text
    assert render_metrics([]) == ""


async def test_groups_show_the_paths_the_caller_may_see(app_client, db):
    _, headers = await login(app_client, db)
    a, b, c, ab, cb, ifs = await chain(app_client, db, headers)
    one = await new_path(app_client, headers, a, c, name="Main", group_key="a-c", priority=10)
    backup = await new_path(app_client, headers, a, c, name="Backup", group_key="a-c", priority=20)
    await app_client.put(f"{P}/{one['id']}/segments", headers=headers, json={"link_ids": [ab, cb]})
    await refresh_states(db, 150)
    groups = (await app_client.get(f"{P}/groups", headers=headers)).json()["items"]
    assert groups[0]["group_key"] == "a-c" and groups[0]["state"] == "unprotected" and groups[0]["usable"] == 1
    assert [m["name"] for m in groups[0]["members"]] == ["Main", "Backup"]
    # A disabled path is out of service: it neither protects the group nor counts against it.
    await app_client.put(f"{P}/{backup['id']}", headers=headers, json={"enabled": False})
    groups = (await app_client.get(f"{P}/groups", headers=headers)).json()["items"]
    assert (groups[0]["state"], groups[0]["total"], groups[0]["redundant"]) == ("up", 1, False)


async def test_a_reseller_sees_a_path_only_with_both_endpoints_and_hidden_hops_stay_masked(app_client, db):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    _, admin = await login(app_client, db, "adm")
    a, b, c, ab, cb, ifs = await chain(app_client, db, admin, groups=(mine, theirs, mine))  # the middle site is outside
    through = await new_path(app_client, admin, a, c, name="Through B")
    await app_client.put(f"{P}/{through['id']}/segments", headers=admin, json={"link_ids": [ab, cb]})
    to_b = await new_path(app_client, admin, a, b, name="To B")
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine, grant=("paths.view", "paths.edit", "links.view"))
    listed = [p["name"] for p in (await app_client.get(P, headers=reseller)).json()["items"]]
    assert listed == ["Through B"]
    detail = (await app_client.get(f"{P}/{through['id']}", headers=reseller)).json()
    assert detail["live_state"] == "up" and all(h["link"]["dest"]["visible"] is False for h in detail["hops"])
    assert "site-b" not in json.dumps(detail) and b not in json.dumps(detail)
    assert (await app_client.get(f"{P}/{to_b['id']}", headers=reseller)).status_code == 404
    # Changing the route needs every link fully in scope.
    refused = await app_client.put(f"{P}/{through['id']}/segments", headers=reseller, json={"link_ids": [ab, cb]})
    assert refused.status_code == 404


async def test_paths_need_their_permissions_and_two_endpoints(app_client, db):
    a, b = await make_device(db, "x"), await make_device(db, "y")
    user, headers = await login(app_client, db)
    assert (await app_client.post(P, headers=headers, json={"name": "loop", "endpoint_a_id": a, "endpoint_b_id": a})).status_code == 422
    await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                     "and permission_id in (select id from permissions where code in ('paths.view', 'paths.edit'))", user)
    assert (await app_client.get(P, headers=headers)).status_code == 403
    assert (await app_client.post(P, headers=headers, json={"name": "n", "endpoint_a_id": a, "endpoint_b_id": b})).status_code == 403
