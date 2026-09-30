"""A normalized description of the live schema, used to detect drift.

The committed snapshot (app/schema_snapshot.json) must equal what `alembic upgrade head` produces on an empty database.
`python -m app.cli db check` compares a running database with it, which catches manual changes and forgotten migrations.
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import asyncpg

SNAPSHOT_PATH = Path(__file__).resolve().parent / "schema_snapshot.json"


async def snapshot(conn: asyncpg.Connection) -> dict[str, Any]:
    columns = await conn.fetch(
        """
        select table_name, column_name, udt_name, is_nullable, column_default, character_maximum_length
        from information_schema.columns
        where table_schema = 'public' and table_name <> 'alembic_version'
        order by table_name, column_name
        """
    )
    constraints = await conn.fetch(
        """
        select c.relname as table_name, k.conname, pg_get_constraintdef(k.oid) as definition
        from pg_constraint k
        join pg_class c on c.oid = k.conrelid
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relname <> 'alembic_version'
        order by c.relname, k.conname
        """
    )
    indexes = await conn.fetch(
        """
        select tablename, indexname, indexdef from pg_indexes
        where schemaname = 'public' and tablename <> 'alembic_version'
        order by tablename, indexname
        """
    )
    triggers = await conn.fetch(
        """
        select c.relname as table_name, t.tgname, pg_get_triggerdef(t.oid) as definition
        from pg_trigger t
        join pg_class c on c.oid = t.tgrelid
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and not t.tgisinternal and t.tgname not like 'ts\\_%'
        order by c.relname, t.tgname
        """
    )
    functions = await conn.fetch(
        """
        select p.proname, md5(p.prosrc) as source_hash
        from pg_proc p join pg_namespace n on n.oid = p.pronamespace
        where n.nspname = 'public' and p.proname like 'cs\\_%'
        order by p.proname
        """
    )
    extensions = await conn.fetch("select extname from pg_extension order by extname")
    hypertables = await conn.fetch(
        "select hypertable_name, compression_enabled from timescaledb_information.hypertables order by hypertable_name"
    )
    jobs = await conn.fetch(
        """
        select proc_name, hypertable_name, config::text as config
        from timescaledb_information.jobs
        where hypertable_name is not null
        order by hypertable_name, proc_name
        """
    )
    result: dict[str, Any] = {"tables": {}, "extensions": [r["extname"] for r in extensions if r["extname"] != "plpgsql"]}
    for r in columns:
        result["tables"].setdefault(r["table_name"], {"columns": {}, "constraints": {}, "indexes": {}, "triggers": {}})["columns"][r["column_name"]] = {
            "type": r["udt_name"],
            "nullable": r["is_nullable"] == "YES",
            "default": r["column_default"],
            "length": r["character_maximum_length"],
        }
    for r in constraints:
        result["tables"].setdefault(r["table_name"], {"columns": {}, "constraints": {}, "indexes": {}, "triggers": {}})["constraints"][r["conname"]] = r["definition"]
    for r in indexes:
        result["tables"].setdefault(r["tablename"], {"columns": {}, "constraints": {}, "indexes": {}, "triggers": {}})["indexes"][r["indexname"]] = r["indexdef"]
    for r in triggers:
        result["tables"].setdefault(r["table_name"], {"columns": {}, "constraints": {}, "indexes": {}, "triggers": {}})["triggers"][r["tgname"]] = r["definition"]
    result["functions"] = {r["proname"]: r["source_hash"] for r in functions}
    result["hypertables"] = {r["hypertable_name"]: {"compression": r["compression_enabled"]} for r in hypertables}
    policies = []
    for r in jobs:
        config = json.loads(r["config"])
        config.pop("hypertable_id", None)  # an internal number, not part of the schema's meaning
        policies.append({"job": r["proc_name"], "hypertable": r["hypertable_name"], "config": config})
    result["policies"] = policies
    return result


def dump(data: dict[str, Any]) -> str:
    return json.dumps(data, indent=2, sort_keys=True) + "\n"


def load_committed() -> dict[str, Any]:
    return json.loads(SNAPSHOT_PATH.read_text())


def diff(expected: dict[str, Any], actual: dict[str, Any], path: str = "") -> list[str]:
    """Human-readable differences between two snapshots."""
    problems: list[str] = []
    if isinstance(expected, dict) and isinstance(actual, dict):
        for key in sorted(set(expected) | set(actual)):
            where = f"{path}/{key}"
            if key not in actual:
                problems.append(f"missing in database: {where}")
            elif key not in expected:
                problems.append(f"unexpected in database: {where}")
            else:
                problems.extend(diff(expected[key], actual[key], where))
    elif expected != actual:
        problems.append(f"differs: {path}: expected {expected!r}, found {actual!r}")
    return problems
