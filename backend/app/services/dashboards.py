"""Role dashboards (Plan 23): which dashboards exist, which widgets they hold, who may open them, and how every legacy
widget maps to a native one.

Two rules decide access, on top of each widget's permission:
- A NOC-wide dashboard or widget shows data across the whole network, so it needs a role that sees everything
  (`scope_mode = 'all'`). A reseller is refused it however its permissions are set; that is not left to permission
  hygiene.
- Every other widget is computed through the scoped repositories (app/repositories/dashboards.py), so a reseller's
  numbers only ever count what is inside their scope.

A widget is `live` when its data exists in the new system today, or `pending` with the reason and the plan that
brings its data. Pending widgets are listed rather than dropped, so a dashboard shows what is coming instead of
silently looking complete.
"""
from __future__ import annotations

from dataclasses import dataclass

from app.core.security import CurrentUser


@dataclass(frozen=True)
class Widget:
    key: str
    title: str
    permission: str
    noc_wide: bool = False
    pending: str | None = None  # why there is no data yet, and which plan brings it


_ONU_PENDING = "OLT/PON/ONU storage is not built yet (Plan 14)"
WIDGETS: dict[str, Widget] = {w.key: w for w in [
    Widget("device_status", "Device reachability", "devices.view"),
    Widget("events_by_severity", "Open events by severity", "events.view"),
    Widget("events_by_name", "Open events by name", "events.view"),
    Widget("events_table", "Latest open events", "events.view"),
    Widget("ports_down", "Ports down", "devices.view"),
    Widget("poller_health", "Poller health", "pollers.view"),
    Widget("error_calling_by_device", "Polling errors by device", "pollers.view"),
    Widget("system_stat", "System summary", "portal.view"),
    Widget("latest_system_actions", "Latest system actions", "logs.actions.view", noc_wide=True),
    Widget("last_user_activity", "Last user activity", "users.view", noc_wide=True),
    Widget("high_link_utilization", "Busiest links", "links.view", pending="links and traffic history (Plan 27)"),
    Widget("ont_statuses", "ONU statuses", "olts.view", pending=_ONU_PENDING),
    Widget("ont_offline_split", "Offline ONUs by reason", "olts.view", pending=_ONU_PENDING),
    Widget("pon_ports", "PON ports and capacity", "olts.view", pending=_ONU_PENDING),
    Widget("bad_ont_signal", "Worst ONU signal", "olts.view", pending=_ONU_PENDING),
    Widget("ont_signal_bar", "ONU signal levels", "olts.view", pending=_ONU_PENDING),
    Widget("online_onts_chart", "Online ONUs over time", "olts.view", pending=_ONU_PENDING),
    Widget("unregistered_onts", "Unregistered ONUs", "onus.unregistered.view", pending=_ONU_PENDING),
]}


@dataclass(frozen=True)
class Dashboard:
    key: str
    title: str
    widgets: tuple[str, ...]
    noc_wide: bool


DASHBOARDS: dict[str, Dashboard] = {d.key: d for d in [
    Dashboard("noc", "Network operations", (
        "device_status", "events_by_severity", "events_table", "events_by_name", "ports_down", "poller_health",
        "error_calling_by_device", "high_link_utilization", "system_stat", "latest_system_actions"), noc_wide=True),
    Dashboard("olt", "OLTs and ONUs", (
        "ont_statuses", "ont_offline_split", "pon_ports", "bad_ont_signal", "ont_signal_bar", "online_onts_chart",
        "unregistered_onts", "events_table"), noc_wide=True),
    Dashboard("monitoring", "Monitoring", (
        "device_status", "poller_health", "error_calling_by_device", "ports_down", "system_stat", "last_user_activity"),
        noc_wide=True),
    Dashboard("alarms", "Alarms", ("events_by_severity", "events_by_name", "events_table"), noc_wide=False),
    # The reseller's subscribers first, then what they act on today (legacy migration 068's order).
    Dashboard("reseller", "My subscribers", (
        "ont_statuses", "online_onts_chart", "bad_ont_signal", "ont_signal_bar", "unregistered_onts", "events_table",
        "device_status"), noc_wide=False),
]}

# The dashboard each seeded role opens on. A role not listed (one an administrator created) falls back by scope.
ROLE_DEFAULTS = {
    "Super Admin": "noc", "ISP Admin": "noc", "ISP NOC": "noc", "ISP Support": "noc",
    "Reseller Admin": "reseller", "Reseller Operator": "reseller", "Reseller Viewer": "reseller",
}
_FALLBACK_ORDER = {True: ("noc", "monitoring", "olt", "alarms", "reseller"), False: ("reseller", "alarms")}

# Every legacy widget and dashboard endpoint, and its native equivalent. None would be a documented removal; there
# is none today.
LEGACY_WIDGETS: dict[str, str | None] = {
    "pinger_stat": "device_status", "device_status_chart": "device_status", "events_table": "events_table",
    "events_stat": "events_by_severity", "events_stat_by_name": "events_by_name",
    "high_link_utilization": "high_link_utilization", "device_calling_errors_stat": "error_calling_by_device",
    "system_stat": "system_stat", "last_user_activity": "last_user_activity", "bad_ont_signal": "bad_ont_signal",
    "online_onts_chart": "online_onts_chart", "ont_signal_bar": "ont_signal_bar", "ont_statuses_pie": "ont_statuses",
    "unregistered_onts": "unregistered_onts",
    # /api/v1/dashboard/... endpoints (Plan 23's parity list)
    "error-calling-by-device": "error_calling_by_device", "system-stat": "system_stat", "ont-statuses": "ont_statuses",
    "ont-offline-split": "ont_offline_split", "ports-down": "ports_down", "poller-health": "poller_health",
    "pon-ports": "pon_ports", "latest-system-actions": "latest_system_actions",
}


def widget_allowed(user: CurrentUser, key: str) -> bool:
    widget = WIDGETS[key]
    return user.has_permission(widget.permission) and (user.scope_all or not widget.noc_wide)


def dashboard_widgets(user: CurrentUser, key: str) -> list[Widget]:
    """The widgets of a dashboard this user may see, in layout order. Empty when the dashboard is not theirs."""
    dashboard = DASHBOARDS[key]
    if dashboard.noc_wide and not user.scope_all:
        return []
    return [WIDGETS[w] for w in dashboard.widgets if widget_allowed(user, w)]


def available_dashboards(user: CurrentUser) -> list[str]:
    return [key for key in DASHBOARDS if dashboard_widgets(user, key)]


def default_dashboard(user: CurrentUser) -> str | None:
    available = available_dashboards(user)
    preferred = ROLE_DEFAULTS.get(user.role)
    if preferred in available:
        return preferred
    return next((key for key in _FALLBACK_ORDER[user.scope_all] if key in available), None)
