from __future__ import annotations

import pytest_asyncio
from redis.asyncio import Redis

from app.core.config import settings
from app.core.crypto import EncryptionService
from app.core.database import create_pool
from app.polling.engine import Context
from app.polling.fake import FakeTransport
from app.repositories import access_profiles as profiles

COMMUNITY = "live-community-CANARY-77"
SYS_NAME, SYS_DESCR, SYS_OBJECT_ID, SYS_UPTIME = "1.3.6.1.2.1.1.5.0", "1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0"
SAFE = [SYS_DESCR, SYS_OBJECT_ID, SYS_UPTIME, SYS_NAME]


@pytest_asyncio.fixture
async def ctx(clean, monkeypatch):
    monkeypatch.setattr(settings, "poll_min_interval_seconds", 0)
    pool = await create_pool()
    redis = Redis.from_url(settings.redis_dsn, decode_responses=True)
    context = Context(pool, redis, FakeTransport(), EncryptionService.from_settings(), settings)
    yield context
    await redis.aclose()
    await pool.close()


async def make_access_profile(db, name="snmp", community=COMMUNITY) -> str:
    return await profiles.create_profile(db, EncryptionService.from_settings(), {
        "name": name, "snmp_version": "v2c", "snmp_community": community, "timeout_ms": 2000, "retries": 1})


async def make_pollable(db, address="10.50.0.1", *, name=None, vendor="bdcom", family="bdcom-switch", owner="cybersathy",
                        enabled=True, with_profile=True, profile_id=None) -> str:
    fam = await db.fetchrow("select v.id as vendor_id, f.id as family_id from vendor_model_families f join vendors v on v.id = f.vendor_id where v.slug = $1 and f.slug = $2", vendor, family)
    pid = profile_id or (await make_access_profile(db, f"snmp-{address}") if with_profile else None)
    return str(await db.fetchval(
        """
        insert into devices (name, management_ip, device_type, vendor, vendor_id, family_id, access_profile_id, polling_enabled, polling_owner)
        values ($1, $2::inet, 'switch', $3, $4, $5, $6::uuid, $7, $8) returning id
        """,
        name or f"dev-{address}", address, vendor, fam["vendor_id"], fam["family_id"], pid, enabled, owner,
    ))


async def make_active_profile(db, name="switch_basic", entries=None, *, activate=True, vendor_slug=None) -> str:
    """entries: (logical_name, oid, strategy, max_rows, timeout_ms, scale) with scale = (factor, min, max) or None."""
    entries = entries if entries is not None else [("sys_name", SYS_NAME, "get", None, None, None)]
    vendor_id = await db.fetchval("select id from vendors where slug = $1", vendor_slug) if vendor_slug else None
    pid = await db.fetchval("insert into oid_profiles (name, version, vendor_id) values ($1, 1, $2) returning id", name, vendor_id)
    for position, (logical, oid, strategy, rows, timeout, scale) in enumerate(entries):
        definition = await db.fetchval(
            "insert into oid_definitions (logical_name, numeric_oid, module, access, source_note, unit) values ($1, $2, 'test', 'read-only', 'test', $3) returning id",
            f"{name}.{logical}", oid, "dBm" if scale else None)
        transform = None
        if scale:
            transform = await db.fetchval(
                "insert into oid_transform_rules (name, kind, factor, valid_min, valid_max, fixture_ref) values ($1, 'scale', $2, $3, $4, 'fixtures/test.json') returning id",
                f"{name}.{logical}.scale", scale[0], scale[1], scale[2])
        await db.execute(
            "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms, transform_id, position) values ($1, $2, $3, $4, $5, $6, $7)",
            pid, definition, strategy, rows, timeout, transform, position)
    if activate:
        await db.execute("update oid_profiles set status = 'active' where id = $1", pid)
    return str(pid)
