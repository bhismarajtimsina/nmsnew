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

No device is contacted: Alertmanager already did the polling (via the exporters), and this only relays its verdict.
"""
from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from typing import Any

import asyncpg

RESOLVED_GRACE_PERIOD_SECONDS = 300
DEFAULT_SEVERITY = "critical"


@dataclass
class IngestSummary:
    created: int = 0
    duplicate: int = 0
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


async def process_alert(conn: asyncpg.Connection, alert: dict[str, Any], *, worker_uptime_seconds: float, summary: IngestSummary) -> None:
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
        device_id = await resolve_device(conn, labels)
        await conn.execute(
            "insert into events (name, dedup_key, labels, description, severity, device_id) values ($1, $2, $3::jsonb, $4, $5, $6::uuid)",
            name, fingerprint, json.dumps(labels), (alert.get("annotations") or {}).get("description"), severity, device_id,
        )
        summary.created += 1
    else:
        if worker_uptime_seconds < RESOLVED_GRACE_PERIOD_SECONDS:
            summary.resolved_skipped_grace += 1
            return
        status = await conn.execute(
            "update events set resolved_at = now() where name = $1 and dedup_key = $2 and resolved_at is null", name, fingerprint
        )
        if status.endswith(" 0"):
            summary.resolved_not_found += 1
        else:
            summary.resolved += 1


async def process_webhook(conn: asyncpg.Connection, payload: dict[str, Any], *, worker_started_at: float) -> IngestSummary:
    summary = IngestSummary()
    uptime = time.monotonic() - worker_started_at
    for alert in payload.get("alerts") or []:
        await process_alert(conn, alert, worker_uptime_seconds=uptime, summary=summary)
    return summary
