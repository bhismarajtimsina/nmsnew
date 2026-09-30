"""Migration state: the head revision shipped with the code and the revision the database is at."""
from __future__ import annotations

from pathlib import Path

import asyncpg
from alembic.config import Config
from alembic.script import ScriptDirectory

BACKEND_DIR = Path(__file__).resolve().parents[1]


def alembic_config() -> Config:
    config = Config(str(BACKEND_DIR / "alembic.ini"))
    config.set_main_option("script_location", str(BACKEND_DIR / "alembic"))
    return config


def head_revision() -> str:
    head = ScriptDirectory.from_config(alembic_config()).get_current_head()
    if head is None:
        raise RuntimeError("no migration revisions found")
    return head


async def current_revision(conn: asyncpg.Connection) -> str | None:
    exists = await conn.fetchval("select to_regclass('public.alembic_version') is not null")
    if not exists:
        return None
    return await conn.fetchval("select version_num from alembic_version")


class SchemaMismatch(RuntimeError):
    pass


async def ensure_schema_current(pool: asyncpg.Pool) -> None:
    """Refuse to serve requests against a database that is not at the revision this code expects."""
    async with pool.acquire() as conn:
        current = await current_revision(conn)
    expected = head_revision()
    if current != expected:
        raise SchemaMismatch(
            f"database schema is at {current!r} but this code expects {expected!r}; run `python -m app.cli db upgrade`"
        )
