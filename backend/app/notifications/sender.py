"""Polls `notifications` for rows due to send, applies the cancellation rule, and hands each surviving one to a
channel - ported from `Console/NotificationSender.php`'s send-one-notification logic. No real channel exists yet:
Telegram and email need external credentials and network calls this environment cannot safely fabricate or test
against, unlike the UDP trap listener or the ICMP pinger, which had a real loopback path to test through. `Channel`
is a `Protocol` so one can be plugged in later without changing anything here.

A send failure is recorded and never retried - confirmed this matches the real legacy sender exactly
(`Console/NotificationSender.php`'s catch block marks the row FAILED and stops; there is no retry path at all).
"""
from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Protocol

import asyncpg

from app.notifications.generate import is_send_canceled


class Channel(Protocol):
    async def send(self, notification_id: str) -> dict:
        """Delivers the notification and returns metadata to store. Raises on failure."""
        ...


class UnconfiguredChannel:
    """What `app/notifications/__main__.py` hands `run_cycle` until a real channel exists. Every send fails - there
    is genuinely nowhere to deliver a notification to yet - and that failure is recorded exactly like a real
    Telegram/email outage would be (status FAILED, never retried), rather than the process silently pretending to
    succeed or refusing to start at all."""

    async def send(self, notification_id: str) -> dict:
        raise RuntimeError("no notification channel is configured (Telegram/email are not built - see 29-notifications.md)")


async def get_due(conn: asyncpg.Connection, *, now: datetime | None = None, limit: int = 100) -> list[asyncpg.Record]:
    now = now or datetime.now(timezone.utc)
    return await conn.fetch(
        "select id, type, contact_id, event_id, previous_notification_id from notifications "
        "where status = 'queued' and send_at <= $1 order by send_at limit $2",
        now, limit,
    )


async def _previous_status(conn: asyncpg.Connection, previous_id) -> tuple[bool, str | None]:
    if previous_id is None:
        return False, None
    row = await conn.fetchrow("select status from notifications where id = $1::uuid", previous_id)
    return row is not None, (row["status"] if row else None)


async def process_one(
    conn: asyncpg.Connection, notification: asyncpg.Record, channel: Channel, *, check_previous_message: bool = False
) -> str:
    """Returns "canceled", "sent" or "failed" - the same three outcomes `send-notify` logs.

    Ported from `NotificationSenderService.php`'s own pre-send guard - "Notification was ignored, event or action not
    exist" - minus the "action" half: this system never generates an action-linked notification (audit events like
    "device added" or "user logged in" are `ActionGenerator`'s feature, not ported - see the migration's own
    docstring), so every real row here always carries an `event_id`. A null one is a malformed row, not a normal
    case, and is canceled rather than crashing the sender on it.
    """
    if notification["event_id"] is None:
        await conn.execute(
            "update notifications set status = 'canceled', meta = $2::jsonb where id = $1::uuid",
            notification["id"], json.dumps({"error": "Notification was ignored, event does not exist"}),
        )
        return "canceled"

    event_resolved = bool(
        await conn.fetchval("select resolved_at is not null from events where id = $1::uuid", notification["event_id"])
    )
    previous_exists, previous_status = await _previous_status(conn, notification["previous_notification_id"])

    if is_send_canceled(
        notification_type=notification["type"], event_resolved=event_resolved, previous_status=previous_status,
        previous_exists=previous_exists, check_previous_message=check_previous_message,
    ):
        await conn.execute("update notifications set status = 'canceled' where id = $1::uuid", notification["id"])
        return "canceled"

    await conn.execute("update notifications set status = 'in_process' where id = $1::uuid", notification["id"])
    try:
        meta = await channel.send(str(notification["id"]))
    except Exception as exc:  # noqa: BLE001 - untrusted channel I/O; recorded, never retried, matching legacy
        await conn.execute(
            "update notifications set status = 'failed', sent_at = now(), meta = $2::jsonb where id = $1::uuid",
            notification["id"], json.dumps({"error": str(exc)}),
        )
        return "failed"

    await conn.execute(
        "update notifications set status = 'sent', sent_at = now(), meta = $2::jsonb where id = $1::uuid",
        notification["id"], json.dumps(meta),
    )
    return "sent"
