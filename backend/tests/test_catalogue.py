"""Invariants of the shipped roles and permissions. No database needed."""
from app.access.catalogue import EXTRA_PERMISSIONS, all_permissions, role_definitions
from app.access.catalogue_data import LEGACY_TO_NEW, PERMISSIONS

CODES = {code for code, *_ in all_permissions()}
DANGEROUS = {code for code, _, dangerous, _ in all_permissions() if dangerous}
ROLES = role_definitions()


def test_every_role_only_uses_known_permissions():
    for name, definition in ROLES.items():
        assert definition["permissions"] <= CODES, (name, definition["permissions"] - CODES)


def test_seven_planned_roles_exist():
    assert set(ROLES) == {"Super Admin", "ISP Admin", "ISP NOC", "ISP Support", "Reseller Admin", "Reseller Operator", "Reseller Viewer"}


def test_super_admin_holds_everything():
    assert ROLES["Super Admin"]["permissions"] == CODES


def test_a_role_with_a_dangerous_permission_also_holds_the_global_gate():
    """Dangerous codes only work together with dangerous_actions.execute, so a role holding one without the gate is a bug."""
    for name, definition in ROLES.items():
        if definition["permissions"] & DANGEROUS:
            assert "dangerous_actions.execute" in definition["permissions"], name


def test_resellers_are_restricted_to_assigned_scope_and_never_see_everything():
    for name in ("Reseller Admin", "Reseller Operator", "Reseller Viewer"):
        assert ROLES[name]["scope_mode"] == "assigned"
        forbidden = {"events.see_all", "users.manage", "users.view", "roles.manage", "scope.manage", "resellers.manage",
                     "system.configure", "device_access.manage", "devices.manage", "console.open", "macros.execute"}
        assert not (ROLES[name]["permissions"] & forbidden), (name, ROLES[name]["permissions"] & forbidden)


def test_isp_roles_see_everything_and_only_super_admin_manages_users_and_system():
    for name in ("Super Admin", "ISP Admin", "ISP NOC", "ISP Support"):
        assert ROLES[name]["scope_mode"] == "all"
    for name in ("ISP Admin", "ISP NOC", "ISP Support"):
        assert not (ROLES[name]["permissions"] & {"users.manage", "roles.manage", "system.configure", "scope.manage", "resellers.manage"})
    assert "events.see_all" in ROLES["ISP Admin"]["permissions"]


def test_noc_is_a_strict_subset_of_isp_admin_without_editing_rights():
    noc, admin = ROLES["ISP NOC"]["permissions"], ROLES["ISP Admin"]["permissions"]
    assert noc < admin
    assert not (noc & {"devices.manage", "device_access.manage", "macros.edit", "events.configure"})


def test_reseller_roles_are_ordered_by_power():
    viewer, operator, admin = (ROLES[n]["permissions"] for n in ("Reseller Viewer", "Reseller Operator", "Reseller Admin"))
    assert not (viewer & DANGEROUS) and "dangerous_actions.execute" not in viewer
    assert operator < admin
    assert "olts.onu.reboot" in operator and "olts.onu.deregister" not in operator and "olts.onu.deregister" in admin


def test_every_legacy_key_is_mapped_and_the_codes_exist():
    for key, code in LEGACY_TO_NEW.items():
        assert code is None or code in CODES, key
    assert len(LEGACY_TO_NEW) == 92


def test_dangerous_flags_match_the_mapping_document():
    flagged = {c for c, _, d, _ in PERMISSIONS if d}
    assert {"olts.onu.reboot", "switches.reboot", "switches.save_config", "console.open", "macros.execute"} <= flagged
    assert not (flagged & {code for code, *_ in EXTRA_PERMISSIONS})


def test_every_permission_code_appears_once():
    codes = [code for code, *_ in all_permissions()]
    assert len(codes) == len(set(codes)), sorted({c for c in codes if codes.count(c) > 1})
    assert len(codes) == 112  # 111 + maintenance.manage (Plan 20, 2026-10-01)
