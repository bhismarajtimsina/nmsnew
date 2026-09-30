from __future__ import annotations

from typing import Any

import asyncpg

VENDOR_SELECT = """
    select v.id, v.slug, v.name, v.polling_enabled, v.polling_disabled_reason, v.polling_toggled_at, v.discovery_oids,
           v.discovery_timeout_ms, v.discovery_retries, v.notes,
           (select count(*) from vendor_model_families f where f.vendor_id = v.id) as families
    from vendors v
"""

FAMILY_SELECT = """
    select f.id, v.slug as vendor_slug, f.slug, f.name, f.device_type, f.polling_enabled, f.polling_disabled_reason,
           f.polling_toggled_at, f.notes
    from vendor_model_families f join vendors v on v.id = f.vendor_id
"""


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    data = dict(row)
    data["id"] = str(data["id"])
    if data.get("discovery_oids") is not None:
        data["discovery_oids"] = list(data["discovery_oids"])
    return data


async def list_vendors(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    return [as_dict(r) for r in await conn.fetch(VENDOR_SELECT + " order by v.name")]


async def get_vendor(conn: asyncpg.Connection, slug: str) -> dict[str, Any] | None:
    row = await conn.fetchrow(VENDOR_SELECT + " where v.slug = $1", slug)
    if row is None:
        return None
    data = as_dict(row)
    data["model_families"] = [as_dict(r) for r in await conn.fetch(FAMILY_SELECT + " where v.slug = $1 order by f.name", slug)]
    return data


async def get_family(conn: asyncpg.Connection, vendor_slug: str, family_slug: str) -> dict[str, Any] | None:
    row = await conn.fetchrow(FAMILY_SELECT + " where v.slug = $1 and f.slug = $2", vendor_slug, family_slug)
    return as_dict(row) if row else None


async def update_vendor(conn: asyncpg.Connection, slug: str, changes: dict[str, Any]) -> None:
    for column in ("name", "notes", "discovery_timeout_ms", "discovery_retries"):
        if column in changes:
            await conn.execute(f"update vendors set {column} = $2, updated_at = now() where slug = $1", slug, changes[column])


async def set_vendor_polling(conn: asyncpg.Connection, slug: str, enabled: bool, reason: str | None, user_id: str) -> None:
    await conn.execute(
        """
        update vendors set polling_enabled = $2, polling_disabled_reason = case when $2 then null else $3 end,
                           polling_toggled_at = now(), polling_toggled_by = $4::uuid, updated_at = now()
        where slug = $1
        """,
        slug, enabled, reason, user_id,
    )


async def set_family_polling(conn: asyncpg.Connection, vendor_slug: str, family_slug: str, enabled: bool, reason: str | None, user_id: str) -> None:
    await conn.execute(
        """
        update vendor_model_families set polling_enabled = $3, polling_disabled_reason = case when $3 then null else $4 end,
                                         polling_toggled_at = now(), polling_toggled_by = $5::uuid
        where slug = $2 and vendor_id = (select id from vendors where slug = $1)
        """,
        vendor_slug, family_slug, enabled, reason, user_id,
    )


async def polling_allowed(conn: asyncpg.Connection, vendor_slug: str, family_slug: str) -> bool:
    """May anything poll this family? Every poller and job scheduler asks this before acting.
    Unknown vendors and families are not allowed: the answer fails closed."""
    allowed = await conn.fetchval(
        """
        select v.polling_enabled and f.polling_enabled
        from vendor_model_families f join vendors v on v.id = f.vendor_id
        where v.slug = $1 and f.slug = $2
        """,
        vendor_slug, family_slug,
    )
    return bool(allowed)


async def list_capabilities(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    return [dict(r) for r in await conn.fetch("select code, description, risk, enabled_by_default from capabilities order by code")]


async def family_capabilities(conn: asyncpg.Connection, vendor_slug: str, family_slug: str) -> list[dict[str, Any]]:
    """Every capability with this family's status. A capability nobody has assessed is reported as `unverified`."""
    rows = await conn.fetch(
        """
        select c.code, c.description, c.risk, c.enabled_by_default,
               coalesce(fc.status, 'unverified') as status, coalesce(fc.verified_by_fixture, false) as verified_by_fixture,
               fc.fixture_ref, fc.note
        from capabilities c
        left join family_capabilities fc on fc.capability_code = c.code and fc.family_id = (
            select f.id from vendor_model_families f join vendors v on v.id = f.vendor_id where v.slug = $1 and f.slug = $2)
        order by c.code
        """,
        vendor_slug, family_slug,
    )
    return [dict(r) for r in rows]
