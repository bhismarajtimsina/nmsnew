"""Closes events left open because Alertmanager never sent their `resolved` webhook - typically because it restarted.
Ported from the real legacy `components/Events/Console/SyncActiveAlertsCommand.php` (`wca sync-active-alerts`).

Kept faithful to that command:
  * the active set is Alertmanager's `/api/v2/alerts?active=true&silenced=true&inhibited=true&unprocessed=false`, so a
    silenced or inhibited alert still counts as active and its event stays open; an alert reported as `unprocessed`
    anyway is left out of the set, exactly as the PHP skips it
  * only open events whose name is an alarm rule (legacy: `AlertmanagerRulesStorage`) and that have a non-empty
    fingerprint are candidates - an event from anywhere else is never touched
  * every event it closes notifies contacts, as the PHP fires `event:resolved`
  * `dry_run` reports what would be closed and changes nothing

Added, because the legacy command can do real damage here: right after Alertmanager restarts its active list is
nearly empty until rules are evaluated again, and the PHP would close every open alarm. This reads Alertmanager's
own start time from `/api/v2/status` and refuses to run until it has been up for the same grace period the webhook
ingest already applies to `resolved` notifications.

It talks to Alertmanager only: never to a device.
"""
from __future__ import annotations

import asyncio
import json
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Protocol

import asyncpg

from app.alerting.ingest import DEFAULT_FLAP_WINDOW_SECONDS, RESOLVED_GRACE_PERIOD_SECONDS, queue_resolved

ACTIVE_ALERTS_PATH = "/api/v2/alerts?active=true&silenced=true&inhibited=true&unprocessed=false"
STATUS_PATH = "/api/v2/status"


class AlertmanagerClient(Protocol):
    async def active_fingerprints(self) -> set[str]: ...

    async def started_at(self) -> datetime: ...


class HttpAlertmanager:
    """Alertmanager's v2 HTTP API. The standard library client, run in a thread: two GETs every few minutes do not
    justify a new dependency."""

    def __init__(self, base_url: str, timeout_seconds: float = 15) -> None:
        if not base_url:
            raise ValueError("ALERTMANAGER_URL is not configured")
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout_seconds

    def _get_json(self, path: str):
        request = urllib.request.Request(self.base_url + path, headers={"Accept": "application/json"})
        with urllib.request.urlopen(request, timeout=self.timeout) as response:  # noqa: S310 - fixed scheme from config
            return json.loads(response.read())

    async def active_fingerprints(self) -> set[str]:
        alerts = await asyncio.to_thread(self._get_json, ACTIVE_ALERTS_PATH)
        if not isinstance(alerts, list):
            raise RuntimeError("unexpected Alertmanager response: not a list of alerts")
        fingerprints = set()
        for alert in alerts:
            if not isinstance(alert, dict):
                continue
            if (alert.get("status") or {}).get("state") == "unprocessed":
                continue
            fp = alert.get("fingerprint")
            if isinstance(fp, str) and fp:
                fingerprints.add(fp)
        return fingerprints

    async def started_at(self) -> datetime:
        status = await asyncio.to_thread(self._get_json, STATUS_PATH)
        raw = status.get("uptime") if isinstance(status, dict) else None
        if not isinstance(raw, str):
            raise RuntimeError("unexpected Alertmanager response: status has no uptime")
        return datetime.fromisoformat(raw.replace("Z", "+00:00"))


@dataclass
class SyncSummary:
    active: int = 0
    candidates: int = 0
    resolved: int = 0
    skipped_reason: str | None = None
    resolved_ids: list[str] = field(default_factory=list)


async def sync_active_alerts(
    conn: asyncpg.Connection, client: AlertmanagerClient, *, dry_run: bool = False,
    grace_seconds: int = RESOLVED_GRACE_PERIOD_SECONDS, flap_window_seconds: int = DEFAULT_FLAP_WINDOW_SECONDS,
    now: datetime | None = None,
) -> SyncSummary:
    summary = SyncSummary()
    now = now or datetime.now(timezone.utc)
    up_for = (now - await client.started_at()).total_seconds()
    if up_for < grace_seconds:
        summary.skipped_reason = f"Alertmanager has been up {int(up_for)}s, under the {grace_seconds}s grace period"
        return summary
    active = await client.active_fingerprints()
    summary.active = len(active)

    rows = await conn.fetch(
        "select e.id, e.dedup_key from events e where e.resolved_at is null and coalesce(e.dedup_key, '') <> '' "
        "and e.name in (select alert_name from alarm_rules)"
    )
    orphaned = [r for r in rows if r["dedup_key"] not in active]
    summary.candidates = len(orphaned)
    if dry_run:
        summary.resolved_ids = [str(r["id"]) for r in orphaned]
        return summary
    for row in orphaned:
        async with conn.transaction():
            closed = await conn.fetchrow(
                "update events set resolved_at = now() where id = $1 and resolved_at is null returning id, flap_count",
                row["id"],
            )
            if closed is None:  # resolved by a webhook between the read and now
                continue
            await queue_resolved(conn, str(closed["id"]), closed["flap_count"], flap_window_seconds)
        summary.resolved += 1
        summary.resolved_ids.append(str(closed["id"]))
    return summary
