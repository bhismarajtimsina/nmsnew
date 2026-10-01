"""Short-lived, single-use tickets for opening the realtime WebSocket (Plans 22 and 36).

A WebSocket handshake can only carry its credential in the URL, and URLs end up in proxy and access logs. So a browser
never puts its session token there: it first asks `POST /api/v1/realtime/ticket` (with its normal Bearer credential)
for a ticket, then connects with `/ws?ticket=...`. A ticket

  * is random and is stored only as its hash, in Redis, together with which session or API token minted it - never
    the credential itself;
  * lives `TICKET_TTL_SECONDS` and can be used once: it is taken out of Redis atomically (GETDEL), so a copy of it
    found in a log is already spent or expired;
  * is honored only while the credential that minted it is still valid: the handshake re-checks that session or
    token, its user and the address (`authenticate_credential`), so revoking a session also stops its tickets.
"""
from __future__ import annotations

import hashlib
import json
import secrets

from redis.asyncio import Redis

from app.core.security import CurrentUser

TICKET_PREFIX = "csw_"
TICKET_TTL_SECONDS = 30
_KEY = "cs:ws:ticket:"


def _key(ticket: str) -> str:
    return _KEY + hashlib.sha256(ticket.encode("utf-8")).hexdigest()


async def mint(redis: Redis, user: CurrentUser) -> str:
    ticket = TICKET_PREFIX + secrets.token_urlsafe(32)
    record = {"kind": user.auth_kind, "credential_id": user.credential_id, "user_id": user.id}
    await redis.set(_key(ticket), json.dumps(record), ex=TICKET_TTL_SECONDS)
    return ticket


async def redeem(redis: Redis, ticket: str) -> dict | None:
    """The minting credential's kind and id, or None when the ticket is unknown, expired or already used."""
    if not ticket.startswith(TICKET_PREFIX):
        return None
    raw = await redis.getdel(_key(ticket))
    if raw is None:
        return None
    try:
        record = json.loads(raw)
    except ValueError:
        return None
    if record.get("kind") not in ("session", "api_token") or not record.get("credential_id"):
        return None
    return record
