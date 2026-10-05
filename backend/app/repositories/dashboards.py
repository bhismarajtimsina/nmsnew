"""Scoped reads behind the live dashboard widgets (Plan 23). Every count goes through app/repositories/scope.py, so a
restricted user's widget only ever counts devices, interfaces, events and polls inside their scope. The two NOC-wide
widgets (latest system actions, last user activity) read no scoped table; the service refuses them to restricted
users before they get here (app/services/dashboards.py)."""
from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.events import EVENT_VISIBLE
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE, INTERFACE_VISIBLE


def _device_ref(r: asyncpg.Record) -> dict[str, Any]:
    return {"id": str(r["device_id"]), "name": r["device_name"], "ip": r["device_ip"]}


async def device_status(conn: asyncpg.Connection, user: CurrentUser) -> dict[str, int]:
    row = await conn.fetchrow(
        f"""{GRANTED_GROUPS_CTE}
        select count(*) filter (where p.status = 'up') as up, count(*) filter (where p.status = 'down') as down,
               count(*) filter (where p.status is null) as never_checked, count(*) as total
        from devices d left join device_ping_status p on p.device_id = d.id where {DEVICE_VISIBLE}""",
        user.id, user.scope_all,
    )
    return dict(row)


async def events_by_severity(conn: asyncpg.Connection, user: CurrentUser) -> list[dict[str, Any]]:
    # As the events list does: a role that sees everything also sees events with no device (system events).
    visible = "($2::boolean or true)" if user.scope_all else EVENT_VISIBLE
    rows = await conn.fetch(
        f"""{GRANTED_GROUPS_CTE}
        select e.severity, count(*) as count from events e left join devices d on d.id = e.device_id
        where {visible} and e.resolved_at is null group by e.severity order by count(*) desc, e.severity""",
        user.id, user.scope_all,
    )
    return [dict(r) for r in rows]


async def events_by_name(conn: asyncpg.Connection, user: CurrentUser, limit: int) -> list[dict[str, Any]]:
    visible = "($2::boolean or true)" if user.scope_all else EVENT_VISIBLE
    rows = await conn.fetch(
        f"""{GRANTED_GROUPS_CTE}
        select e.name, count(*) as count from events e left join devices d on d.id = e.device_id
        where {visible} and e.resolved_at is null group by e.name order by count(*) desc, e.name limit $3""",
        user.id, user.scope_all, limit,
    )
    return [dict(r) for r in rows]


async def ports_down(conn: asyncpg.Connection, user: CurrentUser, limit: int) -> tuple[list[dict[str, Any]], int]:
    """Interfaces an operator left enabled that are not up, longest down first (legacy PortsDown). An interface set
    administratively down is meant to be down and is left out."""
    where = f"where {INTERFACE_VISIBLE} and i.admin_status = 'up' and i.oper_status is distinct from 'up'"
    rows = await conn.fetch(
        f"""{GRANTED_GROUPS_CTE}
        select i.id, i.name, i.alias, i.oper_status, d.id as device_id, d.name as device_name, host(d.management_ip) as device_ip,
               (select max(h.changed_at) from interface_status_history h where h.interface_id = i.id) as down_since
        from interfaces i join devices d on d.id = i.device_id {where}
        order by down_since asc nulls last, d.name, i.name limit $3""",
        user.id, user.scope_all, limit,
    )
    total = await conn.fetchval(f"{GRANTED_GROUPS_CTE} select count(*) from interfaces i join devices d on d.id = i.device_id {where}",
                                user.id, user.scope_all)
    items = [{"id": str(r["id"]), "name": r["name"], "alias": r["alias"], "oper_status": r["oper_status"],
              "device": _device_ref(r), "down_since": r["down_since"]} for r in rows]
    return items, total


async def poller_health(conn: asyncpg.Connection, user: CurrentUser, hours: int, limit: int) -> dict[str, Any]:
    """Poll outcomes over the window (legacy PollerHealth), with the devices whose polls failed."""
    window = f"p.started_at > now() - make_interval(hours => $3) and {DEVICE_VISIBLE}"
    totals = await conn.fetchrow(
        f"""{GRANTED_GROUPS_CTE}
        select count(*) filter (where p.outcome = 'ok') as ok, count(*) filter (where p.outcome = 'timeout') as timeout,
               count(*) filter (where p.outcome = 'error') as error, count(*) filter (where p.outcome = 'skipped') as skipped,
               count(*) filter (where p.truncated) as truncated
        from polling_results p join devices d on d.id = p.device_id where {window}""",
        user.id, user.scope_all, hours,
    )
    failing = await conn.fetch(
        f"""{GRANTED_GROUPS_CTE}
        select d.id as device_id, d.name as device_name, host(d.management_ip) as device_ip, p.profile_name,
               count(*) as failed_runs, max(p.started_at) as last_failure
        from polling_results p join devices d on d.id = p.device_id
        where {window} and p.outcome in ('timeout', 'error')
        group by d.id, d.name, d.management_ip, p.profile_name order by count(*) desc, d.name limit $4""",
        user.id, user.scope_all, hours, limit,
    )
    return {"window_hours": hours, **dict(totals),
            "failing": [{"device": _device_ref(r), "profile": r["profile_name"], "failed_runs": r["failed_runs"],
                         "last_failure": r["last_failure"]} for r in failing]}


async def error_calling_by_device(conn: asyncpg.Connection, user: CurrentUser, limit: int) -> dict[str, Any]:
    """Per device over the last day: polls that errored and polls the device did not answer (legacy
    ErrorCallingByDevices, which counted switcher-core call errors and non-responses the same way). Totals cover every
    visible device; the list stops at `limit`."""
    rows = await conn.fetch(
        f"""{GRANTED_GROUPS_CTE}
        select d.id as device_id, d.name as device_name, host(d.management_ip) as device_ip,
               count(*) filter (where p.outcome = 'error') as errors, count(*) filter (where p.outcome = 'timeout') as not_responding
        from polling_results p join devices d on d.id = p.device_id
        where p.started_at > now() - interval '24 hours' and p.outcome in ('error', 'timeout') and {DEVICE_VISIBLE}
        group by d.id, d.name, d.management_ip order by count(*) desc, d.name""",
        user.id, user.scope_all,
    )
    return {
        "errors": sum(r["errors"] for r in rows), "not_responding": sum(r["not_responding"] for r in rows), "limit": limit,
        "devices": [{"device": _device_ref(r), "errors": r["errors"], "not_responding": r["not_responding"]} for r in rows[:limit]],
    }


async def system_stat(conn: asyncpg.Connection, user: CurrentUser) -> dict[str, Any]:
    """Counts of what this user can see. Legacy's version counted every device, interface, user and role whatever the
    caller's scope; here devices, groups and interfaces are scoped, and users and roles are counted only for a role
    that sees everything and may view users."""
    row = await conn.fetchrow(
        f"""{GRANTED_GROUPS_CTE}
        select (select count(*) from devices d where {DEVICE_VISIBLE}) as devices,
               (select count(*) from interfaces i join devices d on d.id = i.device_id where {INTERFACE_VISIBLE}) as interfaces,
               (select count(*) from device_groups g where $2::boolean or g.id in (select id from granted)) as device_groups""",
        user.id, user.scope_all,
    )
    users = roles = None
    if user.scope_all and user.has_permission("users.view"):
        users = await conn.fetchval("select count(*) from users where is_active")
        roles = await conn.fetchval("select count(*) from roles")
    return {**dict(row), "users": users, "roles": roles}


async def latest_system_actions(conn: asyncpg.Connection, limit: int) -> list[dict[str, Any]]:
    """NOC-wide: the audit trail's newest entries, without their before/after payloads."""
    rows = await conn.fetch(
        """select a.id, a.occurred_at, a.action, a.resource_type, a.resource_id, u.username as actor
           from audit_logs a left join users u on u.id = a.actor_user_id order by a.occurred_at desc limit $1""",
        limit,
    )
    return [{**dict(r), "id": str(r["id"]), "resource_id": str(r["resource_id"]) if r["resource_id"] is not None else None} for r in rows]


async def last_user_activity(conn: asyncpg.Connection, limit: int) -> list[dict[str, Any]]:
    """NOC-wide: users by their most recent session activity or login."""
    rows = await conn.fetch(
        """select u.id, u.username, u.display_name, r.name as role, u.last_login_at,
                  (select max(s.last_activity_at) from user_sessions s where s.user_id = u.id) as last_activity_at
           from users u join roles r on r.id = u.role_id where u.is_active
           order by greatest(u.last_login_at, (select max(s.last_activity_at) from user_sessions s where s.user_id = u.id)) desc nulls last,
                    u.username
           limit $1""",
        limit,
    )
    return [{**dict(r), "id": str(r["id"])} for r in rows]
