"""Idempotent seed of permissions, roles, default settings and the first Super Admin.

Rules (defect F-01 and F-02): running the seed again must never change a credential, never move a user to another role,
never revert a role's scope mode, never remove a permission an administrator granted, and never put back one they
removed. It inserts what is missing and refreshes the catalogue-managed fields of permissions.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field

import asyncpg

from app.access.catalogue import all_permissions, role_definitions
from app.core.config import settings
from app.core.passwords import check_strength, generate_password, hash_password, SCHEME_ARGON2
from app.registry.alarm_rule_data import ALARM_RULES
from app.registry.device_model_data import DEVICE_MODELS
from app.registry.notification_event_config_data import NOTIFICATION_EVENT_CONFIGS
from app.registry.trap_profile_data import TRAP_PROFILES
from app.registry.standard_oids import (
    INTERFACE_BASIC_PROFILE,
    INTERFACE_DEFINITIONS,
    INTERFACE_TABLE_MAX_ROWS,
    INTERFACE_TABLE_TIMEOUT_MS,
    SOURCE_FILE,
    SYSTEM_BASIC_PROFILE,
    SYSTEM_DEFINITIONS,
    SYSTEM_GET_TIMEOUT_MS,
)
from app.registry.vendor_data import CAPABILITIES, FAMILIES, VENDORS

DEFAULT_SETTINGS = {
    "access.default_scope_policy": (
        {"reseller_scope_required": True, "admin_scope": "all", "viewer_scope_required": True},
        "Default CyberSathy-NMS role and scope rules",
    ),
}


class WeakSeedPassword(ValueError):
    pass


@dataclass
class SeedResult:
    permissions_added: int = 0
    roles_added: list[str] = field(default_factory=list)
    role_permissions_added: int = 0
    vendors_added: int = 0
    families_added: int = 0
    admin_status: str = "unchanged"  # created | password_set | unchanged
    generated_password: str | None = None


async def seed(
    conn: asyncpg.Connection,
    *,
    admin_username: str = "admin",
    admin_display_name: str = "CyberSathy Admin",
    admin_email: str | None = None,
    admin_password: str | None = None,
    reset_role_permissions: bool = False,
) -> SeedResult:
    result = SeedResult()
    if admin_password is not None:
        problems = check_strength(admin_password, settings.password_min_length)
        if problems and settings.is_production:
            raise WeakSeedPassword(f"seed admin password is too weak: {', '.join(problems)}")

    async with conn.transaction():
        existing_codes = {r["code"] for r in await conn.fetch("select code from permissions")}
        new_codes: set[str] = set()
        for code, group, dangerous, description in all_permissions():
            await conn.execute(
                """
                insert into permissions (code, group_name, description, is_dangerous)
                values ($1, $2, $3, $4)
                on conflict (code) do update set group_name = excluded.group_name, is_dangerous = excluded.is_dangerous
                """,
                code, group, description, dangerous,
            )
            if code not in existing_codes:
                result.permissions_added += 1
                new_codes.add(code)
        permission_ids = {r["code"]: r["id"] for r in await conn.fetch("select id, code from permissions")}

        role_ids: dict[str, str] = {}
        existing_roles = {r["name"] for r in await conn.fetch("select name from roles")}
        for name, definition in role_definitions().items():
            # scope_mode is set on creation only: an administrator's later decision is never reverted by a re-seed.
            row = await conn.fetchrow(
                """
                insert into roles (name, description, is_system, scope_mode)
                values ($1, $2, true, $3)
                on conflict (name) do update set is_system = true
                returning id
                """,
                name, definition["description"], definition["scope_mode"],
            )
            role_ids[name] = row["id"]
            role_is_new = name not in existing_roles
            if role_is_new:
                result.roles_added.append(name)
            if reset_role_permissions:
                await conn.execute("delete from role_permissions where role_id = $1", row["id"])
            for code in sorted(definition["permissions"]):
                # Defaults are granted to a new role, or when the permission itself is new to the catalogue. A permission
                # an administrator removed from an existing role is not put back by a later seed.
                if not (role_is_new or reset_role_permissions or code in new_codes):
                    continue
                status = await conn.execute(
                    "insert into role_permissions (role_id, permission_id) values ($1, $2) on conflict do nothing",
                    row["id"], permission_ids[code],
                )
                if status.endswith(" 1"):
                    result.role_permissions_added += 1

        await _seed_registry(conn, result)
        await _seed_schedule(conn)
        await _seed_device_models(conn)
        await _seed_standard_oid_profiles(conn)
        await _seed_bdcom_switch_profile(conn)
        await _seed_bdcom_olt_profiles(conn)
        await _seed_switch_standard_profiles(conn)
        await _seed_mikrotik_profile(conn)
        await _seed_alarm_rules(conn)
        await _seed_trap_profiles(conn)
        await _seed_notification_event_config(conn)

        for key, (value, description) in DEFAULT_SETTINGS.items():
            await conn.execute(
                "insert into system_settings (key, value, description) values ($1, $2::jsonb, $3) on conflict (key) do nothing",
                key, json.dumps(value), description,
            )

        user = await conn.fetchrow("select id, password_hash from users where username = $1", admin_username)
        if user is None:
            generated = admin_password is None
            password = admin_password or generate_password(settings.password_min_length)
            await conn.execute(
                """
                insert into users (role_id, username, email, display_name, password_hash, hash_scheme,
                                   password_changed_at, must_change_password, is_active)
                values ($1, $2, $3, $4, $5, $6, now(), $7, true)
                """,
                role_ids["Super Admin"], admin_username, admin_email, admin_display_name,
                hash_password(password), SCHEME_ARGON2, generated,
            )
            result.admin_status = "created"
            result.generated_password = password if generated else None
        elif user["password_hash"] is None and admin_password is not None:
            # Recovery for an account that has no password (for example one created before password login existed).
            await conn.execute(
                "update users set password_hash = $2, hash_scheme = $3, password_changed_at = now() where id = $1",
                user["id"], hash_password(admin_password), SCHEME_ARGON2,
            )
            result.admin_status = "password_set"
    return result


async def _seed_registry(conn: asyncpg.Connection, result: SeedResult) -> None:
    """Vendors and families are inserted when missing and never overwritten: a polling switch an operator flipped stays flipped.
    Capabilities are code-owned safety facts (risk level, default) and are refreshed."""
    for slug, name in VENDORS:
        status = await conn.execute("insert into vendors (slug, name) values ($1, $2) on conflict (slug) do nothing", slug, name)
        result.vendors_added += status.endswith(" 1")
    vendor_ids = {r["slug"]: r["id"] for r in await conn.fetch("select id, slug from vendors")}
    for vendor_slug, family_slug, family_name, device_type in FAMILIES:
        status = await conn.execute(
            "insert into vendor_model_families (vendor_id, slug, name, device_type) values ($1, $2, $3, $4) on conflict (vendor_id, slug) do nothing",
            vendor_ids[vendor_slug], family_slug, family_name, device_type,
        )
        result.families_added += status.endswith(" 1")
    for code, description, risk, default in CAPABILITIES:
        await conn.execute(
            """
            insert into capabilities (code, description, risk, enabled_by_default) values ($1, $2, $3, $4)
            on conflict (code) do update set description = excluded.description, risk = excluded.risk, enabled_by_default = excluded.enabled_by_default
            """,
            code, description, risk, default,
        )


# key, type, params, crontab, enabled, description. Polls are seeded OFF: they need an active profile and a transport.
DEFAULT_JOBS: list[tuple[str, str, dict, str, bool, str]] = [
    ("sessions_cleanup", "cleanup_sessions", {"older_than_days": 7}, "17 3 * * *", True, "Delete expired and revoked sessions"),
    ("retention_login_attempts", "retention", {"target": "login_attempts", "days": 90}, "23 3 * * *", True, "Keep login attempts for 90 days"),
    ("retention_schedule_runs", "retention", {"target": "schedule_runs", "days": 30}, "29 3 * * *", True, "Keep schedule run history for 30 days"),
    ("retention_discovery_jobs", "retention", {"target": "discovery_jobs", "days": 90}, "35 3 * * *", True, "Keep finished discovery jobs for 90 days"),
    ("retention_dead_letters", "retention", {"target": "dead_letter_jobs", "days": 180}, "41 3 * * *", True, "Keep resolved dead-letter jobs for 180 days"),
    ("poll_switch_basic", "poll_group", {"profile": "switch_basic", "device_type": "switch"}, "*/5 * * * *", False, "Poll every switch with the switch_basic profile"),
    ("poll_olt_basic", "poll_group", {"profile": "olt_basic", "device_type": "olt"}, "*/5 * * * *", False, "Poll every OLT with the olt_basic profile"),
    # On by default: without it, an event a maintenance window held back would stay silent after the window ends.
    ("maintenance_release", "maintenance_release", {}, "* * * * *", True, "Announce still-open events held back by an ended maintenance window"),
    # Off until Alertmanager is part of this stack (Plan 31) and ALERTMANAGER_URL points at it. Legacy only ever ran
    # `wca sync-active-alerts` by hand.
    ("sync_active_alerts", "sync_active_alerts", {}, "*/10 * * * *", False, "Close events whose alert Alertmanager no longer reports as active"),
    ("poll_router_basic", "poll_group", {"profile": "router_basic", "device_type": "router"}, "*/5 * * * *", False, "Poll every router with the router_basic profile"),
]


async def _seed_schedule(conn: asyncpg.Connection) -> None:
    """Inserted when missing and never overwritten: a schedule an administrator changed or switched on stays as they left it."""
    from datetime import datetime, timezone

    from app.scheduling import cron

    now = datetime.now(timezone.utc)
    for key, job_type, params, crontab, enabled, description in DEFAULT_JOBS:
        await conn.execute(
            """
            insert into schedule_jobs (key, job_type, params, crontab, enabled, description, next_run_at)
            values ($1, $2, $3::jsonb, $4, $5, $6, $7) on conflict (key) do nothing
            """,
            key, job_type, json.dumps(params), crontab, enabled, description, cron.next_after(cron.parse(crontab), now, settings.scheduler_timezone),
        )


async def _seed_device_models(conn: asyncpg.Connection) -> None:
    """Detection rules ported from the legacy vendor config (see tools/gen_device_model_catalogue.py). Refreshed on every
    seed like the vendor capability facts: these are code-owned reference data, not something an operator edits here."""
    vendor_ids = {r["slug"]: r["id"] for r in await conn.fetch("select id, slug from vendors")}
    family_ids = {(r["vendor_id"], r["slug"]): r["id"] for r in await conn.fetch("select vendor_id, slug, id from vendor_model_families")}
    for vendor_slug, family_slug, key, name, device_type, sysobjectid, sysdescr, priority, source_note in DEVICE_MODELS:
        vendor_id = vendor_ids[vendor_slug]
        family_id = family_ids[(vendor_id, family_slug)]
        await conn.execute(
            """
            insert into device_models (vendor, model_name, device_type, sysobjectid_matcher, sysdescr_pattern, family_id,
                                       priority, source_note, legacy_key)
            values ($1, $2, $3, $4, $5, $6::uuid, $7, $8, $9)
            on conflict (vendor, model_name) do update set
                device_type = excluded.device_type, sysobjectid_matcher = excluded.sysobjectid_matcher,
                sysdescr_pattern = excluded.sysdescr_pattern, family_id = excluded.family_id,
                priority = excluded.priority, source_note = excluded.source_note, legacy_key = excluded.legacy_key
            """,
            vendor_slug, name, device_type, sysobjectid, sysdescr, family_id, priority, source_note, key,
        )


async def _seed_standard_oid_profiles(conn: asyncpg.Connection) -> None:
    """The system_basic and interface_basic profiles, built from app/registry/standard_oids.py (see that module's
    docstring for why these, specifically, do not need a device fixture the way a vendor-private OID would).

    Profile activation is one-way in this schema (migration 0007: an active profile is immutable), so once version 1
    exists and is active this function does nothing further — a later correction is a new version, authored by hand,
    not something a re-seed silently changes underneath an operator who may have built other profiles on top of it."""
    if await conn.fetchval("select exists(select 1 from oid_profiles where name = $1)", SYSTEM_BASIC_PROFILE):
        return

    async def make_definition(d, safety_level: str) -> str:
        existing = await conn.fetchval("select id from oid_definitions where vendor_id is null and logical_name = $1", d.logical_name)
        if existing is not None:
            return str(existing)
        return await conn.fetchval(
            """
            insert into oid_definitions (logical_name, numeric_oid, module, access, safety_level, unit, mib_object, source_note)
            values ($1, $2, $3, 'read-only', $4, $5, $6, $7)
            returning id
            """,
            d.logical_name, d.numeric_oid, d.logical_name.split(".")[0], safety_level, d.unit, d.mib_object,
            f"IETF standard (RFC 1213), re-derived from {SOURCE_FILE}; see app/registry/standard_oids.py",
        )

    async def make_profile(name: str) -> str:
        return await conn.fetchval("insert into oid_profiles (name, version, status) values ($1, 1, 'draft') returning id", name)

    system_profile = await make_profile(SYSTEM_BASIC_PROFILE)
    for position, d in enumerate(SYSTEM_DEFINITIONS):
        definition_id = await make_definition(d, "safe")
        await conn.execute(
            "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, timeout_ms, position) values ($1, $2, 'get', $3, $4)",
            system_profile, definition_id, SYSTEM_GET_TIMEOUT_MS, position,
        )
    await conn.execute("update oid_profiles set status = 'active', description = $2 where id = $1",
                       system_profile, "The system group (RFC 1213): identity and uptime. Vendor-neutral.")

    interface_profile = await make_profile(INTERFACE_BASIC_PROFILE)
    for position, d in enumerate(INTERFACE_DEFINITIONS):
        definition_id = await make_definition(d, "bounded")
        await conn.execute(
            "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms, position) "
            "values ($1, $2, 'walk', $3, $4, $5)",
            interface_profile, definition_id, INTERFACE_TABLE_MAX_ROWS, INTERFACE_TABLE_TIMEOUT_MS, position,
        )
    await conn.execute("update oid_profiles set status = 'active', description = $2 where id = $1",
                       interface_profile, "The interface table (RFC 1213): one row per interface. Vendor-neutral.")


async def _seed_bdcom_switch_profile(conn: asyncpg.Connection) -> None:
    """The BDCOM switch profile (Plan 13), as a DRAFT: vendor-private OIDs need a device fixture and an operator's
    hardware sign-off before anything polls them, and the engine only runs active profiles (see
    app/registry/bdcom_switch_oids.py). Inserted once; an operator activates it, or builds a corrected version, by hand."""
    from app.registry.bdcom_switch_oids import DEFINITIONS, FAMILY_SLUG, GET_TIMEOUT_MS, MIB_DIRECTORY, PROFILE_NAME, VENDOR_SLUG

    if await conn.fetchval("select exists(select 1 from oid_profiles where name = $1)", PROFILE_NAME):
        return
    vendor_id = await conn.fetchval("select id from vendors where slug = $1", VENDOR_SLUG)
    family_id = await conn.fetchval("select id from vendor_model_families where vendor_id = $1 and slug = $2", vendor_id, FAMILY_SLUG)
    if vendor_id is None or family_id is None:
        return  # the registry seed runs first; without the vendor and family there is nothing to attach the profile to
    profile_id = await conn.fetchval(
        "insert into oid_profiles (name, version, status, vendor_id, family_id, description) values ($1, 1, 'draft', $2, $3, $4) returning id",
        PROFILE_NAME, vendor_id, family_id,
        "BDCOM switch: identity, CPU and memory, LLDP neighbours, SFP diagnostics. Draft until a fixture and a hardware "
        "sign-off exist (Plan 13).",
    )
    for position, d in enumerate(DEFINITIONS):
        definition_id = await conn.fetchval(
            """
            insert into oid_definitions (vendor_id, logical_name, numeric_oid, module, access, safety_level, unit, mib_object, source_note)
            values ($1, $2, $3, $4, 'read-only', $5, $6, $7, $8)
            on conflict (coalesce(vendor_id, '00000000-0000-0000-0000-000000000000'::uuid), logical_name) do update set numeric_oid = excluded.numeric_oid
            returning id
            """,
            vendor_id, d.logical_name, d.numeric_oid, d.logical_name.split(".")[1], "safe" if d.strategy == "get" else "bounded",
            d.unit, d.mib_object, f"{MIB_DIRECTORY}/{d.mib_file}, object {d.mib_object}; legacy switch-common.yml. Not verified against a device.",
        )
        await conn.execute(
            "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms, position) values ($1, $2, $3, $4, $5, $6)",
            profile_id, definition_id, d.strategy, d.max_rows, d.timeout_ms if d.strategy != "get" else GET_TIMEOUT_MS, position,
        )


async def _seed_switch_standard_profiles(conn: asyncpg.Connection) -> None:
    """The vendor-neutral RMON and VLAN switch profiles (Plan 18) and the router address profile (Plan 19), as DRAFTS: published standards, but their walk cost
    across many access switches is measured first (app/registry/switch_standard_oids.py). Each inserted once."""
    from app.registry import router_standard_oids, switch_standard_oids
    from app.registry.switch_standard_oids import GET_TIMEOUT_MS, MIB_DIRECTORY

    profiles = {**switch_standard_oids.PROFILES, **router_standard_oids.PROFILES}
    descriptions = {**switch_standard_oids.DESCRIPTIONS, **router_standard_oids.DESCRIPTIONS}
    for name, definitions in profiles.items():
        if await conn.fetchval("select exists(select 1 from oid_profiles where name = $1)", name):
            continue
        profile_id = await conn.fetchval(
            "insert into oid_profiles (name, version, status, description) values ($1, 1, 'draft', $2) returning id",
            name, descriptions[name],
        )
        for position, d in enumerate(definitions):
            definition_id = await conn.fetchval("select id from oid_definitions where vendor_id is null and logical_name = $1", d.logical_name)
            if definition_id is None:
                definition_id = await conn.fetchval(
                    """
                    insert into oid_definitions (logical_name, numeric_oid, module, access, safety_level, unit, mib_object, source_note)
                    values ($1, $2, $3, 'read-only', $4, $5, $6, $7)
                    returning id
                    """,
                    d.logical_name, d.numeric_oid, d.logical_name.split(".")[0], "safe" if d.strategy == "get" else "bounded",
                    d.unit, d.mib_object, f"{MIB_DIRECTORY}/{d.mib_file}, object {d.mib_object} (published standard)",
                )
            await conn.execute(
                "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms, position) values ($1, $2, $3, $4, $5, $6)",
                profile_id, definition_id, d.strategy, d.max_rows, d.timeout_ms if d.strategy != "get" else GET_TIMEOUT_MS, position,
            )


async def _seed_mikrotik_profile(conn: asyncpg.Connection) -> None:
    """The MikroTik RouterOS profile (Plan 19), from the operator-supplied MIKROTIK-MIB, as a DRAFT for the same reason
    as every vendor-private profile: nothing polls it until a fixture and a hardware sign-off exist
    (app/registry/mikrotik_oids.py). Inserted once."""
    from app.registry.mikrotik_oids import DEFINITIONS, FAMILY_SLUG, GET_TIMEOUT_MS, MIB_DIRECTORY, MIB_FILE, PROFILE_NAME, VENDOR_SLUG

    if await conn.fetchval("select exists(select 1 from oid_profiles where name = $1)", PROFILE_NAME):
        return
    vendor_id = await conn.fetchval("select id from vendors where slug = $1", VENDOR_SLUG)
    family_id = await conn.fetchval("select id from vendor_model_families where vendor_id = $1 and slug = $2", vendor_id, FAMILY_SLUG)
    if vendor_id is None or family_id is None:
        return
    profile_id = await conn.fetchval(
        "insert into oid_profiles (name, version, status, vendor_id, family_id, description) values ($1, 1, 'draft', $2, $3, $4) returning id",
        PROFILE_NAME, vendor_id, family_id,
        "MikroTik RouterOS: identity, health, DHCP lease count, simple queues, neighbours, SFP optics. From MIKROTIK-MIB. "
        "Draft until a fixture and a hardware sign-off exist (Plan 19).",
    )
    for position, d in enumerate(DEFINITIONS):
        definition_id = await conn.fetchval(
            """
            insert into oid_definitions (vendor_id, logical_name, numeric_oid, module, access, safety_level, unit, mib_object, source_note)
            values ($1, $2, $3, $4, 'read-only', $5, $6, $7, $8)
            on conflict (coalesce(vendor_id, '00000000-0000-0000-0000-000000000000'::uuid), logical_name) do update set numeric_oid = excluded.numeric_oid
            returning id
            """,
            vendor_id, d.logical_name, d.numeric_oid, d.logical_name.split(".")[1], "safe" if d.strategy == "get" else "bounded",
            d.unit, d.mib_object, f"{MIB_DIRECTORY}/{MIB_FILE}, object {d.mib_object}. Not verified against a device.",
        )
        await conn.execute(
            "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms, position) values ($1, $2, $3, $4, $5, $6)",
            profile_id, definition_id, d.strategy, d.max_rows, d.timeout_ms if d.strategy != "get" else GET_TIMEOUT_MS, position,
        )


async def _seed_bdcom_olt_profiles(conn: asyncpg.Connection) -> None:
    """The BDCOM GPON and EPON OLT profiles (Plan 14), as DRAFTS for the same reason as the switch profile: nothing polls
    them until a fixture per family and an operator's hardware sign-off exist (app/registry/bdcom_olt_oids.py)."""
    from app.registry.bdcom_olt_oids import FAMILY_SLUG, GET_TIMEOUT_MS, GPON_PROFILE, PROFILES, VENDOR_SLUG

    vendor_id = await conn.fetchval("select id from vendors where slug = $1", VENDOR_SLUG)
    family_id = await conn.fetchval("select id from vendor_model_families where vendor_id = $1 and slug = $2", vendor_id, FAMILY_SLUG)
    if vendor_id is None or family_id is None:
        return
    for name, definitions in PROFILES.items():
        if await conn.fetchval("select exists(select 1 from oid_profiles where name = $1)", name):
            continue
        technology = "GPON (GP3600)" if name == GPON_PROFILE else "EPON (P36xx, 3310)"
        profile_id = await conn.fetchval(
            "insert into oid_profiles (name, version, status, vendor_id, family_id, description) values ($1, 1, 'draft', $2, $3, $4) returning id",
            name, vendor_id, family_id,
            f"BDCOM {technology} OLT: identity, resources, PON optics, ONU identity, status, distance and optics. Draft until "
            "a fixture and a hardware sign-off exist (Plan 14).",
        )
        for position, d in enumerate(definitions):
            definition_id = await conn.fetchval(
                """
                insert into oid_definitions (vendor_id, logical_name, numeric_oid, module, access, safety_level, unit, mib_object, source_note)
                values ($1, $2, $3, $4, 'read-only', $5, $6, $7, $8)
                on conflict (coalesce(vendor_id, '00000000-0000-0000-0000-000000000000'::uuid), logical_name) do update set numeric_oid = excluded.numeric_oid
                returning id
                """,
                vendor_id, d.logical_name, d.numeric_oid, d.logical_name.split(".")[1], "safe" if d.strategy == "get" else "bounded",
                d.unit, d.mib_object, f"{d.mib_dir}/{d.mib_file}, object {d.mib_object}; legacy gp3600.yml / 3310c.yml. Not verified against a device.",
            )
            await conn.execute(
                "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms, position) values ($1, $2, $3, $4, $5, $6)",
                profile_id, definition_id, d.strategy, d.max_rows, d.timeout_ms if d.strategy != "get" else GET_TIMEOUT_MS, position,
            )


async def _seed_alarm_rules(conn: asyncpg.Connection) -> None:
    """The real, production alarm rule set (app/registry/alarm_rule_data.py). Inserted when missing by legacy_id and
    never overwritten: an operator who edits a rule's expression, focus or enabled state here keeps that edit."""
    for (group_name, alert_name, expression, for_duration, severity, audience, isp_focus, reseller_focus,
         annotation_summary, annotation_description, enabled, internal, legacy_id) in ALARM_RULES:
        await conn.execute(
            """
            insert into alarm_rules (group_name, alert_name, expression, for_duration, severity, audience, isp_focus,
                                     reseller_focus, annotation_summary, annotation_description, enabled, internal, legacy_id)
            values ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)
            on conflict (legacy_id) do nothing
            """,
            group_name, alert_name, expression, for_duration, severity, audience, isp_focus, reseller_focus,
            annotation_summary, annotation_description, enabled, internal, legacy_id,
        )


async def _seed_trap_profiles(conn: asyncpg.Connection) -> None:
    """Trap definitions ported from the legacy vendor configs (see tools/gen_trap_profile_catalogue.py). Refreshed on
    every seed like device model detection rules: these are code-owned vendor facts, not something an operator edits
    here."""
    for vendor, name, oid, is_interface, modules, description in TRAP_PROFILES:
        await conn.execute(
            """
            insert into trap_profiles (vendor, name, oid, is_interface, modules, description)
            values ($1, $2, $3, $4, $5, $6)
            on conflict (vendor, name) do update set
                oid = excluded.oid, is_interface = excluded.is_interface, modules = excluded.modules,
                description = excluded.description, updated_at = now()
            """,
            vendor, name, oid, is_interface, list(modules), description,
        )


async def _seed_notification_event_config(conn: asyncpg.Connection) -> None:
    """The real per-event notification config (app/registry/notification_event_config_data.py). Inserted when
    missing by event_name and never overwritten: an operator who disables an event's notifications or retunes its
    delay here keeps that edit, the same guarantee alarm_rules already has."""
    for event_name, enabled, delay_before_send_seconds, send_resolved, check_uplink, legacy_id in NOTIFICATION_EVENT_CONFIGS:
        await conn.execute(
            """
            insert into notification_event_config (event_name, enabled, delay_before_send_seconds, send_resolved, check_uplink, legacy_id)
            values ($1, $2, $3, $4, $5, $6)
            on conflict (event_name) do nothing
            """,
            event_name, enabled, delay_before_send_seconds, send_resolved, check_uplink, legacy_id,
        )
