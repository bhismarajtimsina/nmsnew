from __future__ import annotations

import json
from typing import Any

import asyncpg

HEALTHY_WITHIN_SECONDS = 30


async def beat(conn: asyncpg.Connection, worker_id: str, kind: str, status: str, info: dict[str, Any] | None = None) -> None:
    await conn.execute(
        """
        insert into worker_heartbeats (worker_id, kind, status, info) values ($1, $2, $3, $4::jsonb)
        on conflict (worker_id) do update set last_seen = now(), status = excluded.status, info = excluded.info, kind = excluded.kind
        """,
        worker_id, kind, status, json.dumps(info or {}),
    )


async def list_workers(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        "select worker_id, kind, started_at, last_seen, status, info, last_seen > now() - make_interval(secs => $1) as healthy "
        "from worker_heartbeats order by kind, worker_id", float(HEALTHY_WITHIN_SECONDS))
    return [{**dict(r), "info": r["info"] if isinstance(r["info"], dict) else json.loads(r["info"])} for r in rows]
