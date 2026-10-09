"""Console session requests and tickets (Plan 38).

The API asks for a session; this checks the switch, the auto-auth permission, the device's scope and both concurrency
limits, then stores a pending session with a single-use ticket (hashed) valid for TICKET_TTL_SECONDS. The gateway
redeems the ticket, which opens the session, re-checks the requester with fresh data, and records the transcript.

A session that is still `open` but older than the time limit plus a margin is not counted against the limits: its
gateway died without closing it, and it must not lock the device's console forever.
"""
from __future__ import annotations

import hashlib
import secrets
from typing import Any

import asyncpg

from app.core.audit import write_audit
from app.core.config import settings
from app.core.security import CurrentUser
from app.repositories import devices as device_repo

TICKET_TTL_SECONDS = 30
STALE_MARGIN_SECONDS = 60


class ConsoleError(Exception):
    status = 400

    def __init__(self, message: str) -> None:
        super().__init__(message)
        self.message = message


class SwitchedOff(ConsoleError):
    status = 503


class NotAllowed(ConsoleError):
    status = 403


class NotFound(ConsoleError):
    status = 404


class NotAvailable(ConsoleError):
    status = 501


class TooMany(ConsoleError):
    status = 429


def _hash(ticket: str) -> str:
    return hashlib.sha256(ticket.encode()).hexdigest()


_LIVE = ("(status = 'open' and opened_at > now() - make_interval(secs => $2)) "
         "or (status = 'pending' and ticket_expires_at > now())")


async def request_session(conn: asyncpg.Connection, user: CurrentUser, device_id: str, *, auto_auth: bool,
                          ip: str | None = None) -> dict[str, Any]:
    if not settings.console_enabled:
        raise SwitchedOff("the console is switched off (CONSOLE_ENABLED); nothing was opened")
    if auto_auth and not user.has_permission("console.open_auto_auth"):
        raise NotAllowed("logging in automatically needs console.open_auto_auth")
    device = await device_repo.get_device(conn, user, device_id)
    if device is None:
        raise NotFound("Not found")
    if auto_auth:
        raise NotAvailable("automatic login is not available yet: device CLI credentials are not stored in this build")
    stale = settings.console_max_seconds + STALE_MARGIN_SECONDS
    async with conn.transaction():
        # Serialise requests for one device, so two at once cannot both slip under the limit.
        await conn.execute("select pg_advisory_xact_lock(hashtext('console:' || $1))", device_id)
        await conn.execute("update console_sessions set status = 'expired' where device_id = $1::uuid and status = 'pending' "
                           "and ticket_expires_at <= now()", device_id)
        on_device = await conn.fetchval(f"select count(*) from console_sessions where device_id = $1::uuid and ({_LIVE})", device_id, stale)
        if on_device >= settings.console_max_per_device:
            raise TooMany(f"this device already has {on_device} console session(s); the limit is {settings.console_max_per_device}")
        by_user = await conn.fetchval(f"select count(*) from console_sessions where user_id = $1::uuid and ({_LIVE})", user.id, stale)
        if by_user >= settings.console_max_per_user:
            raise TooMany(f"you already have {by_user} console session(s); the limit is {settings.console_max_per_user}")
        ticket = secrets.token_urlsafe(32)
        row = await conn.fetchrow(
            "insert into console_sessions (user_id, device_id, device_name, auto_auth, ticket_hash, ticket_expires_at, client_ip) "
            "values ($1::uuid, $2::uuid, $3, $4, $5, now() + make_interval(secs => $6), $7::inet) returning id, ticket_expires_at",
            user.id, device_id, device["name"], auto_auth, _hash(ticket), TICKET_TTL_SECONDS, ip,
        )
        await write_audit(conn, action="console.requested", actor_user_id=user.id, resource_type="device", resource_id=device_id, ip=ip,
                          metadata={"session_id": str(row["id"]), "auto_auth": auto_auth})
    return {"session_id": str(row["id"]), "ticket": ticket, "expires_at": row["ticket_expires_at"]}


async def redeem(conn: asyncpg.Connection, ticket: str) -> asyncpg.Record | None:
    """Open the session a ticket belongs to, once. None for an unknown, used or expired ticket. One statement outside any
    transaction, so a ticket is spent the moment it is presented."""
    if conn.is_in_transaction():
        raise RuntimeError("redeem a ticket outside a transaction, so spending it always commits")
    return await conn.fetchrow(
        "update console_sessions set status = 'open', opened_at = now() "
        "where ticket_hash = $1 and status = 'pending' and ticket_expires_at > now() "
        "returning id, user_id, device_id, device_name, auto_auth",
        _hash(ticket),
    )


async def close(conn: asyncpg.Connection, session_id: str, reason: str) -> None:
    await conn.execute(
        "update console_sessions set status = 'closed', closed_at = now(), close_reason = $2 where id = $1::uuid and status = 'open'",
        session_id, reason[:120],
    )


class Recorder:
    """Appends transcript chunks in order. Callers pass already-redacted text (app/console/session.py does)."""

    def __init__(self, conn: asyncpg.Connection, session_id: str) -> None:
        self.conn, self.session_id, self.seq = conn, session_id, 0

    async def __call__(self, direction: str, data: str) -> None:
        self.seq += 1
        await self.conn.execute(
            "insert into console_history (session_id, seq, direction, data) values ($1::uuid, $2, $3, $4)",
            self.session_id, self.seq, direction, data,
        )


def banner(device_name: str, session_id: str, username: str) -> str:
    """The visible banner the plan requires: who, what, and that it is recorded."""
    return (f"CyberSathy console: {device_name} | session {session_id} | user {username} | "
            f"this session is recorded; input at password prompts is hidden from the record\r\n")
