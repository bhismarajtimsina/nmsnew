from __future__ import annotations

from typing import Any

import asyncpg


async def list_profiles(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        """
        select p.id, p.name, p.version, p.status, p.description, p.created_at, p.activated_at,
               v.slug as vendor_slug, f.slug as family_slug,
               (select count(*) from oid_profile_entries e where e.profile_id = p.id) as entries
        from oid_profiles p left join vendors v on v.id = p.vendor_id left join vendor_model_families f on f.id = p.family_id
        order by p.name, p.version desc
        """
    )
    return [{**dict(r), "id": str(r["id"])} for r in rows]


async def get_profile(conn: asyncpg.Connection, profile_id: str) -> dict[str, Any] | None:
    head = await conn.fetchrow(
        """
        select p.id, p.name, p.version, p.status, p.description, p.created_at, p.activated_at,
               v.slug as vendor_slug, f.slug as family_slug
        from oid_profiles p left join vendors v on v.id = p.vendor_id left join vendor_model_families f on f.id = p.family_id
        where p.id = $1::uuid
        """,
        profile_id,
    )
    if head is None:
        return None
    entries = await conn.fetch(
        """
        select d.logical_name, d.numeric_oid, d.module, d.access, d.safety_level, d.unit, d.mib_object, d.source_note,
               e.walk_strategy, e.max_rows, e.timeout_ms, e.position,
               t.name as transform, t.kind as transform_kind, t.factor, t.valid_min, t.valid_max
        from oid_profile_entries e
        join oid_definitions d on d.id = e.definition_id
        left join oid_transform_rules t on t.id = e.transform_id
        where e.profile_id = $1::uuid order by e.position, d.logical_name
        """,
        profile_id,
    )
    data = {**dict(head), "id": str(head["id"])}
    data["entries"] = [{k: (float(v) if k in {"factor", "valid_min", "valid_max"} and v is not None else v) for k, v in dict(r).items()} for r in entries]
    return data
