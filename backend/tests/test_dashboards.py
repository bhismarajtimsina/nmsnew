"""Role dashboards (Plan 23): the role decides the default dashboard, an admin can open every dashboard, a reseller
never reaches NOC-wide data, and every widget number a reseller sees counts only their scope."""
import pytest

from app.core.security import CurrentUser
from app.services.dashboards import DASHBOARDS, LEGACY_WIDGETS, ROLE_DEFAULTS, WIDGETS, default_dashboard
from tests.helpers import bearer, make_device, make_group, make_interface, make_user

W = "/api/v1/dashboards/widgets"


async def login_as(app_client, db, name, role):
    user = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user, headers


async def get(app_client, headers, path, **params):
    response = await app_client.get(path, headers=headers, params=params)
    assert response.status_code == 200, response.text
    return response.json()


async def two_regions(db):
    """North (with a child group) and South, a device in each, plus one ungrouped device."""
    north, south = await make_group(db, "North"), await make_group(db, "South")
    child = await make_group(db, "North-East", parent=north)
    devices = {name: await make_device(db, name, group, ip=ip) for name, group, ip in [
        ("sw-north", north, "10.1.0.1"), ("sw-ne", child, "10.1.0.2"), ("sw-south", south, "10.2.0.1"), ("sw-loose", None, "10.3.0.1")]}
    return north, devices


async def reseller(app_client, db, group, role="Reseller Admin"):
    user, headers = await login_as(app_client, db, "res", role)
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    return headers


# --- which dashboards, and the default ---

@pytest.mark.parametrize("role, default", [
    ("Super Admin", "noc"), ("ISP Admin", "noc"), ("ISP NOC", "noc"), ("ISP Support", "noc"),
    ("Reseller Admin", "reseller"), ("Reseller Operator", "reseller"), ("Reseller Viewer", "reseller"),
])
async def test_the_role_decides_the_default_dashboard(app_client, db, role, default):
    _, headers = await login_as(app_client, db, "u", role)
    body = await get(app_client, headers, "/api/v1/dashboards")
    assert body["default"] == default and default in [d["key"] for d in body["dashboards"]]


async def test_an_admin_can_open_every_dashboard_with_every_widget(app_client, db):
    _, headers = await login_as(app_client, db, "root", "Super Admin")
    body = await get(app_client, headers, "/api/v1/dashboards")
    assert [d["key"] for d in body["dashboards"]] == list(DASHBOARDS)
    for board in body["dashboards"]:
        assert [w["key"] for w in board["widgets"]] == list(DASHBOARDS[board["key"]].widgets)


async def test_a_reseller_gets_only_scoped_dashboards_and_no_noc_wide_widget(app_client, db):
    north, _ = await two_regions(db)
    headers = await reseller(app_client, db, north)
    body = await get(app_client, headers, "/api/v1/dashboards")
    assert {d["key"] for d in body["dashboards"]} == {"reseller", "alarms"}
    assert all(not d["noc_wide"] for d in body["dashboards"])
    keys = {w["key"] for d in body["dashboards"] for w in d["widgets"]}
    assert not any(WIDGETS[k].noc_wide for k in keys)


async def test_noc_wide_is_enforced_even_when_a_reseller_role_is_given_the_permission(app_client, db):
    north, _ = await two_regions(db)
    headers = await reseller(app_client, db, north)
    await db.execute(
        "insert into role_permissions (role_id, permission_id) select r.id, p.id from roles r, permissions p "
        "where r.name = 'Reseller Admin' and p.code in ('logs.actions.view', 'users.view') on conflict do nothing")
    for path in ("latest-system-actions", "last-user-activity"):
        assert (await app_client.get(f"{W}/{path}", headers=headers)).status_code == 403
    body = await get(app_client, headers, "/api/v1/dashboards")
    assert "noc" not in {d["key"] for d in body["dashboards"]}


async def test_pending_widgets_are_listed_with_their_reason_and_no_endpoint(app_client, db):
    _, headers = await login_as(app_client, db, "root", "Super Admin")
    olt = next(d for d in (await get(app_client, headers, "/api/v1/dashboards"))["dashboards"] if d["key"] == "olt")
    pending = [w for w in olt["widgets"] if w["state"] == "pending"]
    assert pending and all(w["endpoint"] is None and "Plan 14" in w["pending_reason"] for w in pending)
    live = [w for w in olt["widgets"] if w["state"] == "live"]
    assert [w["key"] for w in live] == ["events_table"] and live[0]["endpoint"].startswith("/api/v1/events")


async def test_dashboards_need_a_session_and_widgets_their_permission(app_client, db):
    assert (await app_client.get("/api/v1/dashboards")).status_code == 401
    _, headers = await login_as(app_client, db, "viewer", "Reseller Viewer")
    await db.execute("delete from role_permissions where role_id = (select id from roles where name = 'Reseller Viewer') "
                     "and permission_id = (select id from permissions where code = 'events.view')")
    assert (await app_client.get(f"{W}/events-by-severity", headers=headers)).status_code == 403


# --- widget data, scoped ---

async def test_device_status_counts_only_visible_devices(app_client, db):
    north, d = await two_regions(db)
    for name, status in [("sw-north", "up"), ("sw-ne", "down"), ("sw-south", "down")]:
        await db.execute("insert into device_ping_status (device_id, status, last_checked_at) values ($1::uuid, $2, now())", d[name], status)
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    assert await get(app_client, noc, f"{W}/device-status") == {"up": 1, "down": 2, "never_checked": 1, "total": 4}
    res = await reseller(app_client, db, north)
    assert await get(app_client, res, f"{W}/device-status") == {"up": 1, "down": 1, "never_checked": 0, "total": 2}


async def event(db, device, name, severity, resolved=False):
    await db.execute(
        "insert into events (name, severity, device_id, dedup_key, resolved_at) values ($1, $2, $3::uuid, $4, case when $5 then now() end)",
        name, severity, device, f"{name}-{device}-{severity}-{resolved}", resolved)


async def test_event_counts_are_open_only_scoped_and_system_events_are_noc_only(app_client, db):
    north, d = await two_regions(db)
    await event(db, d["sw-north"], "port_down", "warning")
    await event(db, d["sw-ne"], "device_down", "critical")
    await event(db, d["sw-south"], "device_down", "critical")
    await event(db, d["sw-north"], "port_down", "critical", resolved=True)
    await event(db, None, "worker_lost", "critical")
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    assert (await get(app_client, noc, f"{W}/events-by-severity"))["items"] == [{"severity": "critical", "count": 3}, {"severity": "warning", "count": 1}]
    assert (await get(app_client, noc, f"{W}/events-by-name"))["items"][0] == {"name": "device_down", "count": 2}
    res = await reseller(app_client, db, north)
    assert (await get(app_client, res, f"{W}/events-by-severity"))["items"] == [{"severity": "critical", "count": 1}, {"severity": "warning", "count": 1}]
    assert sorted((i["name"], i["count"]) for i in (await get(app_client, res, f"{W}/events-by-name"))["items"]) == [("device_down", 1), ("port_down", 1)]


async def test_ports_down_lists_enabled_ports_not_up_longest_down_first_and_scoped(app_client, db):
    north, d = await two_regions(db)
    ports = {}
    for device, name, admin, oper in [("sw-north", "Gi0/1", "up", "down"), ("sw-north", "Gi0/2", "down", "down"),
                                      ("sw-north", "Gi0/3", "up", "up"), ("sw-ne", "Gi0/1", "up", "lowerLayerDown"),
                                      ("sw-south", "Gi0/1", "up", "down")]:
        iface = await make_interface(db, d[device], name, len(ports) + 1)
        await db.execute("update interfaces set admin_status = $2, oper_status = $3 where id = $1::uuid", iface, admin, oper)
        ports[(device, name)] = iface
    await db.execute("insert into interface_status_history (interface_id, device_id, oper_status, admin_status, changed_at) "
                     "values ($1::uuid, $2::uuid, 'down', 'up', now() - interval '2 hours'), ($3::uuid, $4::uuid, 'lowerLayerDown', 'up', now() - interval '1 hour')",
                     ports[("sw-north", "Gi0/1")], d["sw-north"], ports[("sw-ne", "Gi0/1")], d["sw-ne"])
    res = await reseller(app_client, db, north)
    body = await get(app_client, res, f"{W}/ports-down")
    assert body["total"] == 2 and [(i["device"]["name"], i["name"]) for i in body["items"]] == [("sw-north", "Gi0/1"), ("sw-ne", "Gi0/1")]
    assert body["items"][0]["down_since"] is not None
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    assert (await get(app_client, noc, f"{W}/ports-down"))["total"] == 3
    assert (await get(app_client, noc, f"{W}/ports-down", limit=1))["items"][0]["name"] == "Gi0/1"


async def poll(db, device, outcome, hours_ago=1, truncated=False, profile="system_basic"):
    await db.execute(
        "insert into polling_results (device_id, profile_name, outcome, truncated, started_at, error) "
        "values ($1::uuid, $2, $3::text, $4, now() - make_interval(hours => $5), case when $3::text = 'ok' then null else 'test failure' end)",
        device, profile, outcome, truncated, hours_ago)


async def test_poller_health_and_calling_errors_are_scoped_and_windowed(app_client, db):
    north, d = await two_regions(db)
    await poll(db, d["sw-north"], "ok", truncated=True)
    await poll(db, d["sw-north"], "timeout")
    await poll(db, d["sw-ne"], "error")
    await poll(db, d["sw-ne"], "error", hours_ago=30)       # outside both windows
    await poll(db, d["sw-south"], "timeout")
    res = await reseller(app_client, db, north)
    health = await get(app_client, res, f"{W}/poller-health")
    assert (health["ok"], health["timeout"], health["error"], health["truncated"]) == (1, 1, 1, 1)
    assert {f["device"]["name"] for f in health["failing"]} == {"sw-north", "sw-ne"}
    assert (await get(app_client, res, f"{W}/poller-health", hours=48))["error"] == 2
    calls = await get(app_client, res, f"{W}/error-calling-by-device")
    assert (calls["errors"], calls["not_responding"]) == (1, 1) and len(calls["devices"]) == 2
    _, noc = await login_as(app_client, db, "noc", "ISP NOC")
    calls = await get(app_client, noc, f"{W}/error-calling-by-device", limit=1)
    assert (calls["errors"], calls["not_responding"], len(calls["devices"])) == (1, 2, 1)  # totals cover every device


async def test_system_stat_is_scoped_and_hides_user_counts_from_a_reseller(app_client, db):
    north, d = await two_regions(db)
    await make_interface(db, d["sw-north"], "Gi0/1")
    await make_interface(db, d["sw-south"], "Gi0/1")
    res = await reseller(app_client, db, north)
    assert await get(app_client, res, f"{W}/system-stat") == {"devices": 2, "interfaces": 1, "device_groups": 2, "users": None, "roles": None}
    _, root = await login_as(app_client, db, "root", "Super Admin")
    stat = await get(app_client, root, f"{W}/system-stat")
    assert (stat["devices"], stat["interfaces"], stat["device_groups"]) == (4, 2, 3) and stat["users"] == 2 and stat["roles"] >= 7


async def test_latest_actions_and_user_activity_for_an_admin(app_client, db):
    _, root = await login_as(app_client, db, "root", "Super Admin")
    await db.execute("insert into audit_logs (action, resource_type, after) values ('device.create', 'device', '{\"secret\": \"x\"}')")
    actions = (await get(app_client, root, f"{W}/latest-system-actions"))["items"]
    assert actions[0]["action"] == "device.create" and "after" not in actions[0]
    activity = (await get(app_client, root, f"{W}/last-user-activity"))["items"]
    assert activity[0]["username"] == "root" and activity[0]["last_activity_at"] is not None


# --- catalogue rules ---

def test_every_legacy_widget_has_a_native_equivalent_and_every_dashboard_widget_exists():
    assert all(native in WIDGETS for native in LEGACY_WIDGETS.values() if native is not None)
    for parity in ("error-calling-by-device", "system-stat", "ont-statuses", "ont-offline-split", "ports-down",
                   "poller-health", "pon-ports", "latest-system-actions"):
        assert LEGACY_WIDGETS[parity] in WIDGETS
    assert all(w in WIDGETS for board in DASHBOARDS.values() for w in board.widgets)
    assert set(ROLE_DEFAULTS.values()) <= set(DASHBOARDS)


def test_an_unknown_role_falls_back_by_scope_and_a_user_with_nothing_gets_no_default():
    everything = frozenset(w.permission for w in WIDGETS.values())
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Custom", "r", "all", everything)) == "noc"
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Custom", "r", "assigned", everything)) == "reseller"
    # Only events.view: the reseller board still has the events table, and it comes before alarms for a restricted role.
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Custom", "r", "assigned", frozenset({"events.view"}))) == "reseller"
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Custom", "r", "all", frozenset({"events.view"}))) == "noc"
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Custom", "r", "all", frozenset())) is None


def test_a_role_default_wins_over_the_scope_fallback(monkeypatch):
    from app.services import dashboards

    everything = frozenset(w.permission for w in WIDGETS.values())
    monkeypatch.setitem(dashboards.ROLE_DEFAULTS, "Alarm Desk", "alarms")
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Alarm Desk", "r", "all", everything)) == "alarms"
    # ...but never a dashboard the user cannot open: a restricted Alarm Desk mapped to a NOC-wide board falls back.
    monkeypatch.setitem(dashboards.ROLE_DEFAULTS, "Alarm Desk", "noc")
    assert default_dashboard(CurrentUser("1", "a", "A", None, "Alarm Desk", "r", "assigned", everything)) == "reseller"
