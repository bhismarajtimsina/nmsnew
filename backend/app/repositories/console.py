"""Console sessions and transcripts (Plan 38). Reads are scoped by the session's device, like every device read."""
from __future__ import annotations

from typing import Any

import asyncpg

from app.core.security import CurrentUser
from app.repositories.scope import DEVICE_VISIBLE, GRANTED_GROUPS_CTE

SESSION_COLUMNS = ("s.id, s.user_id, u.username, s.device_id, s.device_name, s.auto_auth, s.status, s.created_at, s.opened_at, "
                   "s.closed_at, s.close_reason")


async def list_sessions(conn: asyncpg.Connection, user: CurrentUser, *, device_id: str | None, limit: int) -> list[asyncpg.Record]:
    return await conn.fetch(
        f"{GRANTED_GROUPS_CTE} select {SESSION_COLUMNS} from console_sessions s join devices d on d.id = s.device_id "
        f"left join users u on u.id = s.user_id where {DEVICE_VISIBLE} and ($3::uuid is null or s.device_id = $3::uuid) "
        f"order by s.created_at desc limit $4",
        user.id, user.scope_all, device_id, limit,
    )


async def get_session(conn: asyncpg.Connection, user: CurrentUser, session_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(
        f"{GRANTED_GROUPS_CTE} select {SESSION_COLUMNS} from console_sessions s join devices d on d.id = s.device_id "
        f"left join users u on u.id = s.user_id where s.id = $3::uuid and {DEVICE_VISIBLE}",
        user.id, user.scope_all, session_id,
    )


async def history(conn: asyncpg.Connection, session_id: str, *, after_seq: int, limit: int) -> list[asyncpg.Record]:
    """A session's transcript chunks; call only after `get_session` has checked the caller may see the session."""
    return await conn.fetch(
        "select seq, direction, data, at from console_history where session_id = $1::uuid and seq > $2 order by seq limit $3",
        session_id, after_seq, limit,
    )


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    return {k: (str(v) if k in {"id", "user_id", "device_id"} and v is not None else v) for k, v in dict(row).items()}
