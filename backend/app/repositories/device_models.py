from __future__ import annotations

from typing import Any

import asyncpg

from app.registry.detection import ModelRule

SELECT = """
    select m.id, m.legacy_key, m.vendor, m.model_name, m.device_type, m.sysobjectid_matcher, m.sysdescr_pattern,
           m.priority, m.source_note, m.legacy_id, f.slug as family_slug, m.created_at
    from device_models m left join vendor_model_families f on f.id = m.family_id
"""


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    return {**dict(row), "id": str(row["id"])}


async def list_models(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    return [as_dict(r) for r in await conn.fetch(SELECT + " order by m.vendor, m.priority")]


async def get_model(conn: asyncpg.Connection, model_id: str) -> dict[str, Any] | None:
    row = await conn.fetchrow(SELECT + " where m.id = $1::uuid", model_id)
    return as_dict(row) if row else None


async def load_rules(conn: asyncpg.Connection) -> list[ModelRule]:
    rows = await conn.fetch(
        "select m.id, m.legacy_key, m.model_name, v.slug as vendor_slug, f.slug as family_slug, m.device_type, "
        "m.sysobjectid_matcher, m.sysdescr_pattern, m.priority "
        "from device_models m join vendor_model_families f on f.id = m.family_id join vendors v on v.id = f.vendor_id"
    )
    return [
        ModelRule(
            id=str(r["id"]), key=r["legacy_key"] or str(r["id"]), name=r["model_name"], vendor_slug=r["vendor_slug"],
            family_slug=r["family_slug"], device_type=r["device_type"], sysobjectid_pattern=r["sysobjectid_matcher"],
            sysdescr_pattern=r["sysdescr_pattern"], priority=r["priority"],
        )
        for r in rows
    ]
