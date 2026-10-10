"""Plan 27's link utilisation: counter samples from interface_basic polls, rates that treat a reset as a reset, the
busiest direction per link, scope, and legacy's metrics for the ported alarms. Counter samples are written by hand or
through the sink with readings built here; no device is contacted."""
import pytest

from app.core.config import _utilization_minutes, settings
from app.polling.engine import Reading
from app.topology.sink import ReadingSink
from app.topology.utilization import IF_SPEED_MAX, counter_rate, link_utilization, render_metrics, samples_from
from tests.helpers import bearer, make_device, make_group, make_interface, make_user
from tests.test_lldp import Pool

U = "/api/v1/topology/links/utilization"
IN, OUT, SPEED = "1.3.6.1.2.1.2.2.1.10", "1.3.6.1.2.1.2.2.1.16", "1.3.6.1.2.1.2.2.1.5"


def _walk(name, oid, rows):
    return Reading(name, oid, [(f"{oid}.{i}", v) for i, v in rows])


# Pure rules.

def test_samples_take_the_if_index_from_the_row_and_skip_what_is_not_a_counter():
    out = samples_from([
        _walk("interface.if_in_octets", IN, [(1, 100), (2, -5), (3, True), (4, "7")]),
        _walk("interface.if_out_octets", OUT, [(1, 200), (5, 9)]),
        Reading("interface.if_in_octets", IN, [(f"{IN}.6.1", 1), (f"{OUT}.7", 1)]),  # not one index; another column
        _walk("interface.if_speed", SPEED, [(1, 1_000_000_000), (5, IF_SPEED_MAX), (8, 100)]),  # 8 has no counters
        _walk("interface.if_descr", "1.3.6.1.2.1.2.2.1.2", [(9, 5)]),
    ])
    assert out == {1: {"in": 100, "out": 200, "speed": 1_000_000_000}, 5: {"in": None, "out": 9, "speed": None}}


def test_a_speed_of_zero_is_unknown():
    assert samples_from([_walk("interface.if_in_octets", IN, [(1, 1)]), _walk("interface.if_speed", SPEED, [(1, 0)])])[1]["speed"] is None


def test_a_rate_needs_two_points_a_moment_apart():
    assert counter_rate([]) is None and counter_rate([(0, 5)]) is None and counter_rate([(10, 5), (10, 9)]) is None
    assert counter_rate([(0, 0), (60, 750)]) == 100.0  # 750 octets in a minute: 100 bit/s


def test_a_counter_that_drops_was_reset_and_counts_from_zero_never_as_a_wrap():
    # 1000 -> 400: a restart that has since counted 400, not a wrap worth 2**32 - 600 octets.
    assert counter_rate([(0, 0), (30, 1000), (60, 400)]) == (1000 + 400) * 8 / 60


def test_the_busiest_direction_at_either_end_wins():
    u = link_utilization([{"side": "src", "in_bps": 10e6, "out_bps": 20e6, "speed_bps": 100e6},
                          {"side": "dest", "in_bps": 30e6, "out_bps": 1e6, "speed_bps": 1000e6}])
    assert u == {"percent": 20.0, "mbps": 20.0, "speed_mbps": 100.0, "side": "src", "direction": "out"}


def test_a_known_percent_beats_a_bigger_rate_of_unknown_speed():
    u = link_utilization([{"side": "src", "in_bps": 900e6, "out_bps": None, "speed_bps": None},
                          {"side": "dest", "in_bps": 0.0, "out_bps": None, "speed_bps": 1e9}])
    assert (u["percent"], u["side"]) == (0.0, "dest")


def test_without_any_speed_the_busiest_rate_is_reported_alone():
    u = link_utilization([{"side": "src", "in_bps": 5e6, "out_bps": 7_654_321.0, "speed_bps": None}])
    assert u == {"percent": None, "mbps": 7.654, "speed_mbps": None, "side": "src", "direction": "out"}
    assert link_utilization([]) is None and link_utilization([{"side": "src", "in_bps": None, "out_bps": None, "speed_bps": 1}]) is None


def test_the_period_takes_only_legacys_choices():
    assert _utilization_minutes("15m") == 15 and _utilization_minutes("6h") == 360
    for bad in ("15", "2h", "", "15M"):
        with pytest.raises(ValueError):
            _utilization_minutes(bad)


def _link(state, iface="Gi0/1"):
    end = {"device_id": "d1", "ip": "10.0.0.1", "name": "a", "interface": iface}
    return {"id": "L1", "state": state, "src": end, "dest": {**end, "device_id": "d2", "ip": None, "name": "b", "interface": None}}


def test_metrics_use_legacys_names_and_labels_and_export_only_what_is_known():
    text = render_metrics([_link("up")], {"L1": {"percent": 42.5, "mbps": 425.0, "speed_mbps": 1000.0, "side": "src", "direction": "in"}})
    labels = ('link_id="L1",src_device_id="d1",src_device_ip="10.0.0.1",src_device_name="a",src_iface_name="Gi0/1",'
              'dest_device_id="d2",dest_device_ip="",dest_device_name="b",dest_iface_name="N/A"')
    assert f"link_utilization_prc{{{labels}}} 42.5" in text and f"link_utilization_mbps{{{labels}}} 425" in text
    assert f"link_utilization_speed{{{labels}}} 1000" in text and f"link_status{{{labels}}} 1" in text
    assert "# TYPE link_utilization_prc gauge" in text
    text = render_metrics([_link("down")], {"L1": {"percent": None, "mbps": 3.0, "speed_mbps": None, "side": "src", "direction": "in"}})
    assert "link_utilization_prc" not in text and "link_utilization_speed" not in text and "link_utilization_mbps{" in text
    assert "link_status{" in text and text.rstrip().endswith(" 0")


def test_an_idle_link_exports_zero_rather_than_nothing():
    # Legacy skipped a zero; exporting it keeps the series alive, so max_over_time sees an idle link as idle.
    text = render_metrics([_link("unknown")], {"L1": {"percent": 0.0, "mbps": 0.0, "speed_mbps": 1000.0, "side": "src", "direction": "in"}})
    assert "link_utilization_prc{" in text and "link_utilization_mbps{" in text


def test_a_link_whose_state_is_unknown_exports_no_status():
    assert render_metrics([_link("unknown")], {}) == "" and render_metrics([], {}) == ""


# Storage, API and metrics.

async def login(app_client, db, name="op", role="ISP Admin", group=None):
    user = await make_user(db, name, role)
    if group is not None:
        await db.execute("insert into role_permissions (role_id, permission_id) select role_id, (select id from permissions "
                         "where code = 'links.view') from users where id = $1::uuid on conflict do nothing", user)
        await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return headers


_BASE = {}


@pytest.fixture(autouse=True)
def _fresh_base():
    _BASE.clear()


async def sample(db, interface, ago_seconds, in_octets, out_octets, speed=None):
    """Sample times are exact offsets from one moment per test, so a rate is not skewed by the time between inserts."""
    base = _BASE.setdefault(id(db), await db.fetchval("select now() - interval '1 second'"))
    await db.execute("insert into interface_counter_samples (sampled_at, interface_id, in_octets, out_octets, speed_bps) "
                     "values ($6::timestamptz - make_interval(secs => $1), $2::uuid, $3, $4, $5)",
                     ago_seconds, interface, in_octets, out_octets, speed, base)


async def linked(db, group_a=None, group_b=None):
    a, b = await make_device(db, "acc-1", group_a), await make_device(db, "agg-1", group_b)
    ai, bi = await make_interface(db, a, "Gi0/1", 1), await make_interface(db, b, "Gi0/24", 24)
    link = str(await db.fetchval("insert into links (src_device_id, src_interface_id, dest_device_id, dest_interface_id) "
                                 "values ($1::uuid, $2::uuid, $3::uuid, $4::uuid) returning id", a, ai, b, bi))
    return a, b, ai, bi, link


async def test_the_sink_stores_a_sample_per_known_interface_and_leaves_other_polls_alone(db):
    device = await make_device(db, "sw-1")
    known = await make_interface(db, device, "Gi0/1", 1)
    sink = ReadingSink(Pool(db))
    await sink.write(device, "interface_basic", [_walk("interface.if_in_octets", IN, [(1, 10), (99, 5)]),
                                                 _walk("interface.if_out_octets", OUT, [(1, 20)]),
                                                 _walk("interface.if_speed", SPEED, [(1, 100_000_000)])])
    rows = await db.fetch("select interface_id::text as i, in_octets, out_octets, speed_bps from interface_counter_samples")
    assert [tuple(r) for r in rows] == [(known, 10, 20, 100_000_000)]  # ifIndex 99 is not in the inventory


    class Refusing:
        def acquire(self):
            raise AssertionError("a poll with nothing to store must not take a connection")

    idle = ReadingSink(Refusing())  # type: ignore[arg-type]
    await idle.write(device, "system_basic", [Reading("system.sys_name", "1.3.6.1.2.1.1.5", "sw-1")])
    await idle.write(device, "interface_basic", [_walk("interface.if_speed", SPEED, [(1, 100_000_000)])])
    assert await db.fetchval("select count(*) from interface_counter_samples") == 1


async def test_utilisation_is_the_busiest_direction_over_the_period(app_client, db):
    a, b, ai, bi, link = await linked(db)
    await sample(db, ai, 120, 0, 0, 100_000_000)
    await sample(db, ai, 60, 375_000_000, 75_000_000, 100_000_000)  # in: 50 Mbit/s over the minute
    await sample(db, ai, 3600, 0, 0, 100_000_000)  # outside the 15-minute period: ignored
    headers = await login(app_client, db)
    body = (await app_client.get(U, headers=headers)).json()
    assert body["minutes"] == settings.links_utilization_minutes == 15
    assert body["items"] == [{"link_id": link, "percent": 50.0, "mbps": 50.0, "speed_mbps": 100.0, "side": "src", "direction": "in"}]


async def test_the_inventory_speed_is_used_when_the_samples_have_none(app_client, db):
    a, b, ai, bi, link = await linked(db)
    await db.execute("update interfaces set speed_bps = 1000000000 where id = $1::uuid", bi)
    await sample(db, bi, 120, 0, 0)
    await sample(db, bi, 60, 0, 750_000_000)  # out: 100 Mbit/s
    headers = await login(app_client, db)
    (item,) = (await app_client.get(U, headers=headers)).json()["items"]
    assert (item["percent"], item["side"], item["direction"], item["speed_mbps"]) == (10.0, "dest", "out", 1000.0)


async def test_an_end_outside_the_callers_scope_is_not_measured(app_client, db):
    mine, theirs = await make_group(db, "mine"), await make_group(db, "theirs")
    a, b, ai, bi, link = await linked(db, mine, theirs)
    for iface, busy in ((ai, 75_000_000), (bi, 600_000_000)):
        await sample(db, iface, 120, 0, 0, 100_000_000)
        await sample(db, iface, 60, busy, 0, 100_000_000)
    reseller = await login(app_client, db, "res", "Reseller Operator", mine)
    (item,) = (await app_client.get(U, headers=reseller)).json()["items"]
    assert (item["side"], item["percent"]) == ("src", 10.0)
    admin = await login(app_client, db)
    (item,) = (await app_client.get(U, headers=admin)).json()["items"]
    assert (item["side"], item["percent"]) == ("dest", 80.0)


async def test_unmeasured_links_are_left_out_and_the_device_filter_and_permission_apply(app_client, db):
    a, b, ai, bi, link = await linked(db)
    other = await make_device(db, "other")
    headers = await login(app_client, db)
    assert (await app_client.get(U, headers=headers)).json()["items"] == []
    await sample(db, ai, 120, 0, 0, 100_000_000)
    await sample(db, ai, 60, 1, 0, 100_000_000)
    assert len((await app_client.get(U, headers=headers, params={"device_id": b})).json()["items"]) == 1
    assert (await app_client.get(U, headers=headers, params={"device_id": other})).json()["items"] == []
    await db.execute("delete from role_permissions where permission_id = (select id from permissions where code = 'links.view')")
    assert (await app_client.get(U, headers=headers)).status_code == 403


async def test_metrics_export_utilisation_and_status_for_the_ported_alarms(app_client, db):
    a, b, ai, bi, link = await linked(db)
    for device in (a, b):
        await db.execute("insert into device_ping_status (device_id, status) values ($1::uuid, 'up')", device)
    await db.execute("update interfaces set oper_status = 'up', admin_status = 'up'")
    await sample(db, ai, 120, 0, 0, 100_000_000)
    await sample(db, ai, 60, 675_000_000, 0, 100_000_000)  # 90 Mbit/s: above the rule's 85%
    text = (await app_client.get("/metrics")).text
    assert f'link_utilization_prc{{link_id="{link}",src_device_id="{a}"' in text
    line = next(x for x in text.splitlines() if x.startswith("link_utilization_prc{"))
    assert line.endswith(" 90") and 'src_iface_name="Gi0/1"' in line and 'dest_device_name="agg-1"' in line
    assert next(x for x in text.splitlines() if x.startswith("link_status{")).endswith(" 1")
    rule = await db.fetchval("select expression from alarm_rules where alert_name = 'high_link_utilization'")
    assert "link_utilization_prc" in rule


async def test_the_table_refuses_a_sample_without_counters_or_with_a_negative_one(db):
    device = await make_device(db, "sw-2")
    iface = await make_interface(db, device, "Gi0/2", 2)
    import asyncpg

    for values in ((None, None, None), (-1, 0, None), (0, 0, 0)):
        with pytest.raises(asyncpg.CheckViolationError):
            async with db.transaction():
                await db.execute("insert into interface_counter_samples (interface_id, in_octets, out_octets, speed_bps) "
                                 "values ($1::uuid, $2, $3, $4)", iface, *values)
