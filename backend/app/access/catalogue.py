"""Roles and permissions the system ships with.

The permission list and the legacy role definitions come from catalogue_data.py (generated). This module turns them
into the roles described in docs/cybersathy-nms-migration/04-roles-permissions-reseller-scope.md.
"""
from __future__ import annotations

from app.access.catalogue_data import (
    LEGACY_ISP_FULL_066,
    LEGACY_ISP_SUPPORT_063,
    LEGACY_RESELLER_063,
    LEGACY_TO_NEW,
    PERMISSIONS,
)

# Permissions the legacy route-key model never had: access administration and the global gate for dangerous actions.
EXTRA_PERMISSIONS: list[tuple[str, str, bool, str]] = [
    ("users.view", "users", False, "View users"),
    ("users.manage", "users", False, "Create, update and disable users"),
    ("roles.view", "roles", False, "View roles"),
    ("roles.manage", "roles", False, "Create and update roles"),
    ("permissions.view", "permissions", False, "View the permission catalogue"),
    ("scope.manage", "scope", False, "Assign device, group and interface scopes to users"),
    ("resellers.manage", "resellers", False, "Create resellers and assign users to them"),
    ("api_tokens.manage", "api_tokens", False, "Create and revoke API tokens"),
    ("dangerous_actions.execute", "dangerous_actions", False, "Global gate for every dangerous action"),
    ("vendors.view", "vendors", False, "View vendors, model families and capabilities"),
    ("vendors.manage", "vendors", False, "Edit vendor and family records"),
    ("vendors.polling.toggle", "vendors", False, "Switch polling on or off for a vendor or a model family"),
    ("oid_profiles.view", "oid_profiles", False, "View OID definitions and polling profiles"),
    ("oid_profiles.manage", "oid_profiles", False, "Create and change OID definitions and polling profiles"),
    ("device_models.view", "device_models", False, "View the device model detection catalogue"),
    ("mib.view", "mib", False, "Search the read-only MIB library"),
    ("events.ingest", "events", False, "Accept incoming Alertmanager webhook notifications"),
    # Granted through EXPAND: the legacy `device_show` and `olts_info` keys covered reading interfaces and ONUs too.
    ("devices.delete", "devices", True, "Delete a device and its history"),
    ("interfaces.view", "interfaces", False, "View interfaces"),
    ("interfaces.mark", "interfaces", False, "Favorite an interface and set its tags"),
    ("onus.view", "onus", False, "View ONU inventory"),
    ("traps.view", "traps", False, "View received SNMP trap history"),
    # Granted through EXPAND to every role that can resolve events: the people who act on alarms plan the work too.
    ("maintenance.manage", "maintenance", False, "Create and cancel maintenance windows for devices and groups in scope"),
]

# One legacy key grants more than one new permission.
EXPAND: dict[str, list[str]] = {
    "devices.view": ["interfaces.view", "traps.view"],
    "olts.view": ["onus.view"],
    "events.resolve": ["maintenance.manage"],
}

SCOPE_ALL = "all"
SCOPE_ASSIGNED = "assigned"

# Permissions with no interactive owner: no role ever holds them, so a session can never call the routes they guard.
# An administrator (anyone holding api_tokens.manage) may still mint a token scoped to one, for the one caller that
# needs it - Alertmanager's webhook_config, here - without that permission ever reaching a user session.
SERVICE_ONLY_PERMISSIONS: frozenset[str] = frozenset({"events.ingest"})


def all_permissions() -> list[tuple[str, str, bool, str]]:
    """The catalogue, one entry per code. A code the legacy mapping already produces is not added again from EXTRA."""
    seen: dict[str, tuple[str, str, bool, str]] = {}
    for entry in PERMISSIONS + EXTRA_PERMISSIONS:
        seen.setdefault(entry[0], entry)
    return sorted(seen.values())


def _codes(legacy_keys: list[str], extra: list[str] | None = None, without: list[str] | None = None) -> set[str]:
    codes: set[str] = set()
    for key in legacy_keys:
        if without and key in without:
            continue
        code = LEGACY_TO_NEW.get(key)
        if code:
            codes.add(code)
            codes.update(EXPAND.get(code, []))
    codes.update(extra or [])
    return codes


# Registries are network knowledge: ISP roles read them, and the operators who run the network can stop polling fast.
_REGISTRY_READ = ["vendors.view", "oid_profiles.view", "device_models.view", "mib.view"]
_KILL_SWITCH = ["vendors.polling.toggle"]
# Favoriting and tagging an interface is collaborative bookkeeping, not a dangerous edit, but the legacy system
# required no permission for it at all; every role below that operates (rather than only reads) the network gets it.
_INTERFACE_MARK = ["interfaces.mark"]
_NOC_WITHOUT = [
    "device_management", "device_access_management", "device_groups_management", "macros_edit",
    "unregistered_onts_config", "events_configuration", "schedule_configuration", "notifications_full_access",
    "notifications_send_global_notify", "external_apps_console_with_auto_auth", "dashboard_global_edit",
]
_RESELLER_ADMIN_EXTRA = ["olts_ctrl_reset", "olts_ctrl_disable", "olts_ctrl_dereg", "olts_ctrl_uni_ports"]
_RESELLER_VIEWER = [
    "system_info", "user_self_control", "dashboard_edit", "device_show", "olts_info", "poller_info_by_device",
    "prom_chart_info", "analytics", "unregistered_onts", "events_show", "notifications_view_history",
    "notifications_configure_self_contacts", "view_qr_codes",
]


def role_definitions() -> dict[str, dict]:
    """name -> {description, scope_mode, permissions}. Scope mode decides whether the role sees everything."""
    gate = ["dangerous_actions.execute"]
    everything = {code for code, *_ in all_permissions()}
    reseller_admin_keys = LEGACY_RESELLER_063 + _RESELLER_ADMIN_EXTRA
    return {
        "Super Admin": dict(
            description="Full access to everything, including users, roles and system configuration",
            scope_mode=SCOPE_ALL, permissions=everything),
        "ISP Admin": dict(
            description="Full control of the network and all subscribers. Users, roles and system configuration stay with Super Admin",
            scope_mode=SCOPE_ALL, permissions=_codes(LEGACY_ISP_FULL_066, gate + ["api_tokens.manage", "devices.delete"] + _REGISTRY_READ + _KILL_SWITCH + _INTERFACE_MARK)),
        "ISP NOC": dict(
            description="Operates the network and sees every event. Cannot edit devices, access profiles, rules or templates",
            scope_mode=SCOPE_ALL, permissions=_codes(LEGACY_ISP_FULL_066, gate + _REGISTRY_READ + _KILL_SWITCH + _INTERFACE_MARK, without=_NOC_WITHOUT)),
        "ISP Support": dict(
            description="Traces faults across the transport and subscribers. Reads widely, changes little",
            scope_mode=SCOPE_ALL, permissions=_codes(LEGACY_ISP_SUPPORT_063, gate + _REGISTRY_READ)),
        "Reseller Admin": dict(
            description="Full control of the subscribers and equipment assigned to this reseller",
            scope_mode=SCOPE_ASSIGNED, permissions=_codes(reseller_admin_keys, gate + _INTERFACE_MARK)),
        "Reseller Operator": dict(
            description="Supports the subscribers assigned to this reseller. Reboots and relabels a single ONU",
            scope_mode=SCOPE_ASSIGNED, permissions=_codes(LEGACY_RESELLER_063, gate + _INTERFACE_MARK)),
        "Reseller Viewer": dict(
            description="Read-only view of the subscribers assigned to this reseller",
            scope_mode=SCOPE_ASSIGNED, permissions=_codes(_RESELLER_VIEWER)),
    }
