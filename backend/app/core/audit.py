from __future__ import annotations

import json
import re
from typing import Any

import asyncpg

_SENSITIVE = re.compile(r"(^|_)(password|passwd|secret|token|community|auth_key|api_key|private_key|totp|pin|hash|code)(_|$)|_enc$")


def redact(value: Any) -> Any:
    """Copy of `value` with every sensitive-looking field replaced. Applied to everything written to the audit log."""
    if isinstance(value, dict):
        return {k: ("[redacted]" if _SENSITIVE.search(str(k).lower()) else redact(v)) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [redact(v) for v in value]
    return value


async def write_audit(
    conn: asyncpg.Connection,
    *,
    action: str,
    actor_user_id: str | None = None,
    resource_type: str | None = None,
    resource_id: str | None = None,
    ip: str | None = None,
    user_agent: str | None = None,
    before: dict[str, Any] | None = None,
    after: dict[str, Any] | None = None,
    metadata: dict[str, Any] | None = None,
) -> None:
    await conn.execute(
        """
        insert into audit_logs (actor_user_id, action, resource_type, resource_id, ip_address, user_agent, before, after, metadata)
        values ($1::uuid, $2, $3, $4::uuid, $5::inet, $6, $7::jsonb, $8::jsonb, $9::jsonb)
        """,
        actor_user_id,
        action,
        resource_type,
        resource_id,
        ip,
        user_agent,
        json.dumps(redact(before), default=str) if before is not None else None,
        json.dumps(redact(after), default=str) if after is not None else None,
        json.dumps(redact(metadata or {}), default=str),
    )
