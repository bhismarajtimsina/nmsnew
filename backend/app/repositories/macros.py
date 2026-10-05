"""Stored macros and ONU-registration templates (Plan 38). Not a scoped table: a template is device-independent text;
scope applies when one is run against a device, in the action flow."""
from __future__ import annotations

import json
from typing import Any

import asyncpg

COLUMNS = "id, kind, name, description, template, parameters, display_output, model_keys, version, created_at, updated_at"


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    data = dict(row)
    data["id"] = str(data["id"])
    if isinstance(data.get("parameters"), str):
        data["parameters"] = json.loads(data["parameters"])
    data["model_keys"] = list(data["model_keys"] or [])
    return data


async def list_macros(conn: asyncpg.Connection, kind: str) -> list[dict[str, Any]]:
    rows = await conn.fetch(f"select {COLUMNS} from macros where kind = $1 order by name", kind)
    return [as_dict(r) for r in rows]


async def get_macro(conn: asyncpg.Connection, kind: str, macro_id: str) -> dict[str, Any] | None:
    row = await conn.fetchrow(f"select {COLUMNS} from macros where kind = $1 and id = $2::uuid", kind, macro_id)
    return as_dict(row) if row else None


async def create_macro(conn: asyncpg.Connection, kind: str, user_id: str, data: dict[str, Any]) -> dict[str, Any]:
    row = await conn.fetchrow(
        f"""
        insert into macros (kind, name, description, template, parameters, display_output, model_keys, created_by_user_id, updated_by_user_id)
        values ($1, $2, $3, $4, $5::jsonb, $6, $7, $8::uuid, $8::uuid) returning {COLUMNS}
        """,
        kind, data["name"], data["description"], data["template"], json.dumps(data["parameters"]), data["display_output"],
        data["model_keys"], user_id,
    )
    return as_dict(row)


async def update_macro(conn: asyncpg.Connection, kind: str, macro_id: str, user_id: str, version: int, data: dict[str, Any]) -> dict[str, Any] | None:
    """None when the row does not exist or is not at `version` (someone else saved first)."""
    row = await conn.fetchrow(
        f"""
        update macros set name = $4, description = $5, template = $6, parameters = $7::jsonb, display_output = $8,
               model_keys = $9, version = version + 1, updated_by_user_id = $10::uuid, updated_at = now()
        where kind = $1 and id = $2::uuid and version = $3 returning {COLUMNS}
        """,
        kind, macro_id, version, data["name"], data["description"], data["template"], json.dumps(data["parameters"]),
        data["display_output"], data["model_keys"], user_id,
    )
    return as_dict(row) if row else None


async def delete_macro(conn: asyncpg.Connection, kind: str, macro_id: str) -> bool:
    status = await conn.execute("delete from macros where kind = $1 and id = $2::uuid", kind, macro_id)
    return not status.endswith(" 0")
