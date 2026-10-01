"""Turns an Alertmanager webhook payload into events, ported from the real, production
AlertmanagerEventProcessor.php (git 777e9dd88's incident work and its predecessors).

Kept faithful to that logic:
  * the device is found from the alert's labels, in order: `dev_id` (by id), then `host`, then `ip` (both by
    management address) — the same priority the legacy processor uses
  * `severity` defaults to `critical` when the alert does not set one
  * the dedup key is Alertmanager's own `fingerprint`; a `firing` alert with a fingerprint that is already open is a
    duplicate notification, not a new event, and is skipped
  * a `resolved` notification during a short grace period after this worker started is skipped — Alertmanager itself
    can still be catching up on restart, and closing events on stale information is worse than a short delay
  * every event created or resolved is handed to the notification pipeline in the same transaction, as legacy's
    `event:created` / `event:resolved` observers hand it to `EventGenerator`

Not in legacy (Plan 20's flapping suppression): a `firing` alert whose last event was resolved by Alertmanager
within `flap_window_seconds` reopens that event rather than inserting a new one, so a flapping interface is one event
with a `flap_count`, not one per flap. Once an event has flapped, its next resolved notification is held for the same
window, and canceled if it reopens again first (see `requeue_after_reopen`). An event an operator resolved by hand is
never reopened: re-firing after that is a new event, exactly as in legacy.

Also not in legacy: an event created while its device is under a maintenance window is stored with
`suppressed_by_maintenance` and notifies nobody (see app/alerting/maintenance.py for how it is released).

No device is contacted: Alertmanager already did the polling (via the exporters), and this only relays its verdict.
"""
from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from typing import Any

import asyncpg

from app.alerting.maintenance import device_in_maintenance
from app.notifications.pipeline import queue_for_event, requeue_after_reopen

RESOLVED_GRACE_PERIOD_SECONDS = 300
DEFAULT_FLAP_WINDOW_SECONDS = 900
DEFAULT_SEVERITY = "critical"


@dataclass
class IngestSummary:
    created: int = 0
    duplicate: int = 0
    reopened: int = 0
    resolved: int = 0
    resolved_skipped_grace: int = 0
    resolved_not_found: int = 0
    errors: list[str] = field(default_factory=list)


async def resolve_device(conn: asyncpg.Connection, labels: dict[str, Any]) -> str | None:
    if labels.get("dev_id"):
        try:
            return str(await conn.fetchval("select id from devices where id = $1::uuid", str(labels["dev_id"])))
        except (asyncpg.DataError, asyncpg.PostgresError):
            return None
    for key in ("host", "ip"):
        if labels.get(key):
            row = await conn.fetchval("select id from devices where management_ip = $1::inet", str(labels[key]))
            if row is not None:
                return str(row)
    return None


async def _is_open(conn: asyncpg.Connection, name: str, dedup_key: str) -> bool:
    return await conn.fetchval(
        "select exists(select 1 from events where name = $1 and dedup_key = $2 and resolved_at is null)", name, dedup_key
    )


async def queue_resolved(conn: asyncpg.Connection, event_id: str, flap_count: int, flap_window_seconds: int) -> None:
    """Notifications for an event this system just closed automatically. Once it has flapped, the resolved
    notification is held for the flap window (see the module docstring)."""
    hold = flap_window_seconds if flap_count > 0 else 0
    await queue_for_event(conn, event_id, resolved_hold_seconds=hold)


async def _recently_autoresolved(conn: asyncpg.Connection, name: str, dedup_key: str, window_seconds: int) -> asyncpg.Record | None:
    if window_seconds <= 0:
        return None
    return await conn.fetchrow(
        "select id, device_id, suppressed_by_maintenance from events where name = $1 and dedup_key = $2 and resolved_at is not null "
        "and resolved_by_user_id is null and resolved_at > now() - make_interval(secs => $3) "
        "order by resolved_at desc limit 1",
        name, dedup_key, window_seconds,
    )


async def process_alert(
    conn: asyncpg.Connection, alert: dict[str, Any], *, worker_uptime_seconds: float, summary: IngestSummary,
    flap_window_seconds: int = DEFAULT_FLAP_WINDOW_SECONDS,
) -> None:
    labels = dict(alert.get("labels") or {})
    name = labels.pop("alertname", None)
    if not name:
        summary.errors.append("alert has no alertname label")
        return
    fingerprint = alert.get("fingerprint")
    if not fingerprint:
        summary.errors.append(f"{name}: alert has no fingerprint")
        return
    labels.setdefault("severity", DEFAULT_SEVERITY)
    severity = str(labels["severity"]).lower()
    if severity not in ("info", "warning", "critical"):
        severity = DEFAULT_SEVERITY

    if alert.get("status") == "firing":
        if await _is_open(conn, name, fingerprint):
            summary.duplicate += 1
            return
        async with conn.transaction():
            recent = await _recently_autoresolved(conn, name, fingerprint, flap_window_seconds)
            if recent is not None:
                await conn.execute(
                    "update events set resolved_at = null, is_autoresolved = null, flap_count = flap_count + 1, "
                    "last_reopened_at = now() where id = $1::uuid",
                    recent["id"],
                )
                event_device = str(recent["device_id"]) if recent["device_id"] else None
                if recent["suppressed_by_maintenance"]:
                    # Nobody was ever told about it. Still covered: stay quiet. Window over: everyone hears now.
                    if not await device_in_maintenance(conn, event_device):
                        await conn.execute("update events set suppressed_by_maintenance = false where id = $1", recent["id"])
                        await requeue_after_reopen(conn, str(recent["id"]))
                else:
                    # Contacts already knew about this event before any window began, so they keep hearing about it.
                    await requeue_after_reopen(conn, str(recent["id"]))
                summary.reopened += 1
                return
            device_id = await resolve_device(conn, labels)
            suppressed = await device_in_maintenance(conn, device_id)
            event_id = await conn.fetchval(
                "insert into events (name, dedup_key, labels, description, severity, device_id, suppressed_by_maintenance) "
                "values ($1, $2, $3::jsonb, $4, $5, $6::uuid, $7) returning id",
                name, fingerprint, json.dumps(labels), (alert.get("annotations") or {}).get("description"), severity, device_id,
                suppressed,
            )
            await queue_for_event(conn, str(event_id))
        summary.created += 1
    else:
        if worker_uptime_seconds < RESOLVED_GRACE_PERIOD_SECONDS:
            summary.resolved_skipped_grace += 1
            return
        async with conn.transaction():
            rows = await conn.fetch(
                "update events set resolved_at = now() where name = $1 and dedup_key = $2 and resolved_at is null "
                "returning id, flap_count",
                name, fingerprint,
            )
            for row in rows:
                await queue_resolved(conn, str(row["id"]), row["flap_count"], flap_window_seconds)
        if not rows:
            summary.resolved_not_found += 1
        else:
            summary.resolved += 1


async def process_webhook(
    conn: asyncpg.Connection, payload: dict[str, Any], *, worker_started_at: float,
    flap_window_seconds: int = DEFAULT_FLAP_WINDOW_SECONDS,
) -> IngestSummary:
    summary = IngestSummary()
    uptime = time.monotonic() - worker_started_at
    for alert in payload.get("alerts") or []:
        await process_alert(conn, alert, worker_uptime_seconds=uptime, summary=summary, flap_window_seconds=flap_window_seconds)
    return summary
