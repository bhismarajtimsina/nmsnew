"""Database backup and the restore drill (Plan 40): "a backup that has not been restored is not a backup".

`create` writes a logical dump (`pg_dump -Fc`) and, next to it, a manifest: the schema revision, the row count of
every table, the dump's SHA-256, and how long it took.

`drill` proves a dump restores:
- the dump must match its manifest's SHA-256;
- it is restored into a new scratch database, with TimescaleDB's own pre/post-restore steps;
- the scratch database must then be at the same schema revision as the manifest, hold the same row count in every
  table, and still have an active role that sees everything, so an administrator could sign in;
- the scratch database is dropped afterwards, pass or fail, and the time to restore is recorded.

What this is not, and why: it is not continuous WAL archiving (Plan 40 step 1), which needs a separate host or volume
for the archive; that belongs to the deployment. It does not encrypt the dump either. The dump holds credentials only
in their encrypted form (the key lives in the environment, never in the database, D-09), but it should still be
stored encrypted and access-controlled wherever it is kept. The database password reaches pg_dump and pg_restore
through the environment, never the command line, so it does not show in a process list.
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import time
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

import asyncpg

from app.core.config import Settings


@dataclass
class DrillResult:
    ok: bool
    problems: list[str] = field(default_factory=list)
    restore_seconds: float = 0.0
    tables_checked: int = 0


def _env(cfg: Settings) -> dict[str, str]:
    env = dict(os.environ, PGHOST=cfg.postgres_host, PGPORT=str(cfg.postgres_port), PGUSER=cfg.postgres_user)
    if cfg.postgres_password:
        env["PGPASSWORD"] = cfg.postgres_password
    return env


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


async def table_counts(conn: asyncpg.Connection) -> dict[str, int]:
    """Exact row counts of every ordinary table in the public schema (hypertables count their chunks' rows)."""
    names = [r["relname"] for r in await conn.fetch(
        "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace "
        "where n.nspname = 'public' and c.relkind in ('r', 'p') order by 1")]
    return {name: await conn.fetchval(f'select count(*) from "{name}"') for name in names}


async def create(cfg: Settings, directory: Path) -> tuple[Path, dict]:
    """Writes <directory>/cybersathy-<timestamp>.dump and its .json manifest. Returns the dump path and manifest."""
    directory.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    dump = directory / f"cybersathy-{stamp}.dump"
    conn = await asyncpg.connect(cfg.postgres_dsn)
    try:
        revision = await conn.fetchval("select version_num from alembic_version")
        counts = await table_counts(conn)
    finally:
        await conn.close()
    started = time.monotonic()
    subprocess.run(["pg_dump", "--format=custom", "--no-owner", "--no-privileges", f"--file={dump}", cfg.postgres_db],
                   env=_env(cfg), check=True, capture_output=True, text=True)
    manifest = {"created_at": stamp, "database": cfg.postgres_db, "revision": revision, "tables": counts,
                "sha256": _sha256(dump), "bytes": dump.stat().st_size, "dump_seconds": round(time.monotonic() - started, 2)}
    dump.with_suffix(".json").write_text(json.dumps(manifest, indent=2, sort_keys=True))
    return dump, manifest


async def _admin(cfg: Settings) -> asyncpg.Connection:
    return await asyncpg.connect(cfg.postgres_dsn)


async def drill(cfg: Settings, dump: Path) -> DrillResult:
    manifest = json.loads(dump.with_suffix(".json").read_text())
    result = DrillResult(ok=False)
    if _sha256(dump) != manifest["sha256"]:
        result.problems.append("the dump does not match its manifest (SHA-256 differs)")
        return result
    scratch = f"cs_restore_drill_{uuid.uuid4().hex[:12]}"
    admin = await _admin(cfg)
    try:
        await admin.execute(f'create database "{scratch}"')
        started = time.monotonic()
        target = await asyncpg.connect(cfg.postgres_dsn.rsplit("/", 1)[0] + f"/{scratch}")
        try:
            await target.execute("create extension if not exists timescaledb")
            await target.execute("select timescaledb_pre_restore()")
            restored = subprocess.run(["pg_restore", "--no-owner", "--no-privileges", f"--dbname={scratch}", str(dump)],
                                      env=_env(cfg), capture_output=True, text=True)
            await target.execute("select timescaledb_post_restore()")
            result.restore_seconds = round(time.monotonic() - started, 2)
            if restored.returncode != 0:
                result.problems.append(f"pg_restore reported errors: {restored.stderr.strip()[:500]}")
            revision = await target.fetchval("select version_num from alembic_version")
            if revision != manifest["revision"]:
                result.problems.append(f"schema revision {revision} after restore, {manifest['revision']} in the manifest")
            counts = await table_counts(target)
            for table, expected in sorted(manifest["tables"].items()):
                got = counts.get(table)
                if got != expected:
                    result.problems.append(f"{table}: {got} rows after restore, {expected} in the manifest")
            result.tables_checked = len(manifest["tables"])
            if not await target.fetchval(
                "select exists(select 1 from users u join roles r on r.id = u.role_id where u.is_active and r.scope_mode = 'all')"):
                result.problems.append("no active user with a role that sees everything: nobody could administer the restored system")
        finally:
            await target.close()
    finally:
        await admin.execute(f'drop database if exists "{scratch}" with (force)')
        await admin.close()
    result.ok = not result.problems
    return result
