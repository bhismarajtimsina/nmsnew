"""Connects the pure decisions in generate.py to real events, users, contacts and devices: given an event that was
just created or resolved, decides which `notifications` rows to queue. No channel is contacted here - see
sender.py for the step that actually delivers a queued row.

Recipient eligibility mirrors `AbstractNotificationGenerator::getUsers` exactly: a device-scoped event reaches users
scoped to that device (directly, or through its group or an ancestor group - the same reach `GRANTED_GROUPS_CTE`
already gives a scope_all-less caller elsewhere in this project) or whose role sees everything; a device-less event
reaches only users whose role holds `notifications.send_global`.
"""
from __future__ import annotations

from datetime import datetime, timezone

import asyncpg

from app.notifications.generate import Contact, EventConfig, resolved_send_at, build_drafts


async def get_event_config(conn: asyncpg.Connection, event_name: str) -> EventConfig | None:
    row = await conn.fetchrow(
        "select id, enabled, delay_before_send_seconds, send_resolved from notification_event_config where event_name = $1",
        event_name,
    )
    if row is None:
        return None
    ignored = await conn.fetch(
        "select device_id from notification_event_ignored_devices where config_id = $1::uuid", row["id"]
    )
    return EventConfig(
        enabled=row["enabled"],
        delay_before_send_seconds=row["delay_before_send_seconds"],
        send_resolved=row["send_resolved"],
        ignored_device_ids=frozenset(str(r["device_id"]) for r in ignored),
    )


async def eligible_contacts(conn: asyncpg.Connection, device_id: str | None) -> list[Contact]:
    if device_id is not None:
        rows = await conn.fetch(
            """
            with recursive ancestors(id, parent_id) as (
                select dg.id, dg.parent_id from devices d join device_groups dg on dg.id = d.group_id where d.id = $1::uuid
                union
                select g.id, g.parent_id from device_groups g join ancestors a on g.id = a.parent_id
            )
            select distinct c.id, c.severities, c.ignore_event_names
            from notification_contacts c
            join users u on u.id = c.user_id
            join roles r on r.id = u.role_id
            where c.enabled and u.is_active and (
                r.scope_mode = 'all'
                or u.id in (select user_id from user_device_scopes where device_id = $1::uuid)
                or u.id in (select user_id from user_device_group_scopes where device_group_id in (select id from ancestors))
            )
            """,
            device_id,
        )
    else:
        rows = await conn.fetch(
            """
            select distinct c.id, c.severities, c.ignore_event_names
            from notification_contacts c
            join users u on u.id = c.user_id
            join roles r on r.id = u.role_id
            join role_permissions rp on rp.role_id = r.id
            join permissions p on p.id = rp.permission_id
            where c.enabled and u.is_active and p.code = 'notifications.send_global'
            """
        )
    return [
        Contact(id=str(r["id"]), severities=frozenset(r["severities"]), ignore_event_names=frozenset(r["ignore_event_names"]))
        for r in rows
    ]


async def queue_for_event(conn: asyncpg.Connection, event_id: str, *, now: datetime | None = None) -> int:
    """Reads the event, its config and its eligible contacts, and queues one `notifications` row per draft
    `build_drafts` produces. Returns how many were queued. A no-op (0) if the event does not exist or its name has
    no notification config at all - matching `EventGenerator`'s own "not configured for sending, ignoring" path."""
    now = now or datetime.now(timezone.utc)
    event = await conn.fetchrow("select id, name, severity, device_id, resolved_at from events where id = $1::uuid", event_id)
    if event is None:
        return 0
    cfg = await get_event_config(conn, event["name"])
    if cfg is None:
        return 0

    device_id = str(event["device_id"]) if event["device_id"] else None
    contacts = await eligible_contacts(conn, device_id)
    resolved = event["resolved_at"] is not None
    drafts = build_drafts(
        event_name=event["name"], severity=event["severity"], device_id=device_id,
        resolved=resolved, cfg=cfg, contacts=contacts, now=now,
    )

    created = 0
    for draft in drafts:
        previous = None
        send_at = draft.send_at
        if draft.type == "resolved":
            previous = await conn.fetchrow(
                "select id, send_at from notifications where event_id = $1::uuid and contact_id = $2::uuid "
                "and type = 'alert' order by created_at desc limit 1",
                event_id, draft.contact_id,
            )
            send_at = resolved_send_at(now, previous["send_at"] if previous else None)
        await conn.execute(
            "insert into notifications (send_at, type, contact_id, event_id, previous_notification_id) "
            "values ($1, $2, $3::uuid, $4::uuid, $5::uuid)",
            send_at, draft.type, draft.contact_id, event_id, previous["id"] if previous else None,
        )
        created += 1
    return created
