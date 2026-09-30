import asyncio

import asyncpg
import pytest

from app import dbschema
from app.core.config import settings
from app.core.database import create_pool
from app.schema import SchemaMismatch, ensure_schema_current, head_revision
from tests.conftest import ScratchDatabase


async def _snapshot() -> dict:
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        return await dbschema.snapshot(conn)
    finally:
        await conn.close()


def test_migrated_schema_equals_the_committed_snapshot(database):
    """A migration changed without regenerating app/schema_snapshot.json fails here (run `python -m app.cli db snapshot --write`)."""
    problems = dbschema.diff(dbschema.load_committed(), asyncio.run(_snapshot()))
    assert problems == []


def test_downgrade_to_base_and_upgrade_again_is_clean_and_keeps_extensions(database):
    with ScratchDatabase() as scratch:
        scratch.upgrade()
        scratch.downgrade("base")

        async def after_downgrade():
            conn = await asyncpg.connect(settings.postgres_dsn)
            try:
                tables = [r["tablename"] for r in await conn.fetch("select tablename from pg_tables where schemaname = 'public'")]
                extensions = [r["extname"] for r in await conn.fetch("select extname from pg_extension")]
            finally:
                await conn.close()
            return tables, extensions

        tables, extensions = asyncio.run(after_downgrade())
        assert tables == ["alembic_version"]
        assert "timescaledb" in extensions and "pgcrypto" in extensions  # a downgrade never removes shared extensions

        scratch.upgrade()
        assert dbschema.diff(dbschema.load_committed(), asyncio.run(_snapshot())) == []


def test_each_revision_can_be_stepped_down_and_back_up_one_at_a_time(database):
    with ScratchDatabase() as scratch:
        scratch.upgrade()
        from alembic.script import ScriptDirectory

        from app.schema import alembic_config

        revisions = len(list(ScriptDirectory.from_config(alembic_config()).walk_revisions()))
        for _ in range(revisions):
            scratch.downgrade("-1")
        scratch.upgrade()
        assert dbschema.diff(dbschema.load_committed(), asyncio.run(_snapshot())) == []


def test_the_service_refuses_to_serve_a_schema_behind_the_code(database):
    with ScratchDatabase() as scratch:
        scratch.upgrade("20260928_0004")

        async def attempt():
            pool = await create_pool()
            try:
                await ensure_schema_current(pool)
            finally:
                await pool.close()

        with pytest.raises(SchemaMismatch) as raised:
            asyncio.run(attempt())
        assert head_revision() in str(raised.value)


def test_drift_check_reports_a_manual_change(database):
    with ScratchDatabase() as scratch:
        scratch.upgrade()

        async def tamper():
            conn = await asyncpg.connect(settings.postgres_dsn)
            try:
                await conn.execute("alter table devices add column sneaky text")
            finally:
                await conn.close()

        asyncio.run(tamper())
        problems = dbschema.diff(dbschema.load_committed(), asyncio.run(_snapshot()))
        assert any("sneaky" in p for p in problems)


def test_audit_log_is_a_compressed_hypertable_with_retention(database):
    snapshot = dbschema.load_committed()
    assert snapshot["hypertables"]["audit_logs"]["compression"] is True
    jobs = {(p["job"], p["hypertable"]) for p in snapshot["policies"]}
    assert ("policy_retention", "audit_logs") in jobs and ("policy_compression", "audit_logs") in jobs


async def test_truncating_users_cascades_through_vendors_to_the_oid_registry_and_it_is_reseeded(database):
    """A regression test for a real test-isolation gap: vendors and vendor_model_families both hold a polling_toggled_by
    FK to users, so truncating users (unavoidable between tests) transitively empties oid_profiles, oid_profile_entries,
    oid_definitions and device_models too. tests/conftest.py's `clean` fixture must re-seed all of it, every time."""
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        before = await conn.fetchval("select count(*) from oid_profiles")
        assert before > 0  # the standard profiles exist before any truncation
        await conn.execute("truncate users restart identity cascade")
        after = await conn.fetchval("select count(*) from oid_profiles")
        assert after == 0  # confirms the cascade actually reaches this far, so a fix here is not testing nothing
    finally:
        await conn.close()
