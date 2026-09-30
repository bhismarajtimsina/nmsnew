"""Storage and lookup for the MIB library. Import reads files already on disk; nothing here reaches a device."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import asyncpg

from app.registry.mib import resolve


@dataclass
class ImportReport:
    files: int = 0
    objects: int = 0
    resolved: int = 0


async def import_sources(conn: asyncpg.Connection, sources: dict[str, str], vendor_id: str | None) -> ImportReport:
    """Resolve every file in `sources` together (so cross-file parent references work) and replace each file's own
    definitions. Re-importing a filename replaces its objects, so a corrected MIB text does not leave stale rows.

    A name is stored once per *occurrence*, not once per name: a handful of real MIBs genuinely declare the same
    identifier twice with two different OIDs, and that is a fact about the source worth keeping, not a duplicate to
    silently collapse (search and check_definition() both return every occurrence)."""
    result = resolve(sources)
    report = ImportReport(files=len(sources))
    async with conn.transaction():
        for filename, resolved_file in result.files.items():
            resolved_count = sum(1 for e in resolved_file.entries if e.numeric_oid is not None)
            file_id = await conn.fetchval(
                """
                insert into mib_files (filename, vendor_id, source_path, object_count, resolved_count)
                values ($1::varchar, $2::uuid, $1::text, $3, $4)
                on conflict (filename) do update set
                    vendor_id = excluded.vendor_id, object_count = excluded.object_count,
                    resolved_count = excluded.resolved_count, imported_at = now()
                returning id
                """,
                filename, vendor_id, len(resolved_file.entries), resolved_count,
            )
            await conn.execute("delete from mib_objects where file_id = $1", file_id)
            for entry in resolved_file.entries:
                d = entry.definition
                await conn.execute(
                    "insert into mib_objects (file_id, name, kind, parent_name, sub_id, numeric_oid) values ($1, $2, $3, $4, $5, $6)",
                    file_id, d.name, d.kind, d.parent, d.sub_id, entry.numeric_oid,
                )
            report.objects += len(resolved_file.entries)
            report.resolved += resolved_count
    return report


async def list_files(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        "select f.id, f.filename, v.slug as vendor_slug, f.object_count, f.resolved_count, f.imported_at "
        "from mib_files f left join vendors v on v.id = f.vendor_id order by f.filename"
    )
    return [{**dict(r), "id": str(r["id"])} for r in rows]


async def search_objects(conn: asyncpg.Connection, *, query: str | None, file_id: str | None, limit: int) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        """
        select o.id, o.name, o.kind, o.parent_name, o.sub_id, o.numeric_oid, f.filename
        from mib_objects o join mib_files f on f.id = o.file_id
        where ($1::uuid is null or o.file_id = $1::uuid)
          and ($2::text is null or o.name ilike '%' || $2 || '%' or o.numeric_oid like $2 || '%')
        order by o.name limit $3
        """,
        file_id, query, limit,
    )
    return [{**dict(r), "id": str(r["id"])} for r in rows]


async def check_definition(conn: asyncpg.Connection, name: str, numeric_oid: str) -> dict[str, Any]:
    """Does a declared OID definition (logical name + numeric OID) match what an imported MIB actually assigns to that
    object name? Used to validate an OID authored in Plan 6 against the vendor's own MIB, never the other way round."""
    rows = await conn.fetch("select numeric_oid, filename from mib_objects o join mib_files f on f.id = o.file_id where o.name = $1", name)
    if not rows:
        return {"status": "not_found_in_mibs", "name": name, "declared_oid": numeric_oid, "mib_matches": []}
    matches = [{"numeric_oid": r["numeric_oid"], "filename": r["filename"]} for r in rows]
    agree = any(m["numeric_oid"] == numeric_oid for m in matches)
    return {"status": "confirmed" if agree else "mismatch", "name": name, "declared_oid": numeric_oid, "mib_matches": matches}
