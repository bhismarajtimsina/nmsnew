"""Test harness.

Network guard: this suite must never open a connection to anything except loopback and the scratch database and Redis it
is given (TEST_ALLOWED_HOSTS). It enforces the repository rule that nothing in development, review or CI reaches a device.
"""
from __future__ import annotations

import asyncio
import ipaddress
import os
import socket
import uuid

# --- environment first: the application reads it when it is imported --------------------------------------------------
import base64

os.environ.setdefault("ENVIRONMENT", "test")
os.environ.setdefault("ENCRYPTION_KEYS", "test1:" + base64.urlsafe_b64encode(os.urandom(32)).decode("ascii"))
os.environ.setdefault("ENCRYPTION_ACTIVE_KEY_ID", "test1")
os.environ.setdefault("FORWARDED_ALLOW_IPS", "172.16.0.0/12")
os.environ.setdefault("JOB_SIGNING_KEY", "test-signing-key-not-a-secret")
os.environ.setdefault("WORKER_RETRY_BASE_MS", "50")
os.environ.setdefault("POLL_MIN_INTERVAL_SECONDS", "30")

# --- network guard ----------------------------------------------------------------------------------------------------
_ALLOWED: set[str] = set()
for _host in filter(None, os.environ.get("TEST_ALLOWED_HOSTS", "").split(",")):
    try:
        _ALLOWED.add(socket.gethostbyname(_host.strip()))
    except OSError:
        pass


class NetworkBlocked(RuntimeError):
    pass


def _permitted(host: str) -> bool:
    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        return False
    return address.is_loopback or host in _ALLOWED


_real_connect = socket.socket.connect
_real_connect_ex = socket.socket.connect_ex


def _check(sock: socket.socket, address) -> None:
    if sock.family in (socket.AF_INET, socket.AF_INET6) and isinstance(address, tuple) and not _permitted(str(address[0])):
        raise NetworkBlocked(f"test attempted a connection to {address[0]}, which is not loopback or an allowed test service")


def _guarded_connect(self, address):
    _check(self, address)
    return _real_connect(self, address)


def _guarded_connect_ex(self, address):
    _check(self, address)
    return _real_connect_ex(self, address)


socket.socket.connect = _guarded_connect  # type: ignore[method-assign]
socket.socket.connect_ex = _guarded_connect_ex  # type: ignore[method-assign]

# --- imports that need the environment ---------------------------------------------------------------------------------
import asyncpg  # noqa: E402
import httpx  # noqa: E402
import pytest  # noqa: E402
import pytest_asyncio  # noqa: E402
from alembic import command  # noqa: E402

from app.core.config import settings  # noqa: E402
from app.schema import alembic_config  # noqa: E402
from app.seed import SeedResult, _seed_bdcom_switch_profile, _seed_device_models, _seed_registry, _seed_schedule, _seed_standard_oid_profiles, seed  # noqa: E402

TRUSTED_CLIENT = ("172.18.0.5", 44321)  # inside FORWARDED_ALLOW_IPS: behaves like the Nginx container


def _admin_dsn(database: str) -> str:
    return f"postgresql://{settings.postgres_user}:{settings.postgres_password}@{settings.postgres_host}:{settings.postgres_port}/{database}"


async def _create_database(name: str) -> None:
    conn = await asyncpg.connect(_admin_dsn("postgres"))
    try:
        await conn.execute(f'create database "{name}"')
    finally:
        await conn.close()


async def _drop_database(name: str) -> None:
    conn = await asyncpg.connect(_admin_dsn("postgres"))
    try:
        await conn.execute(f'drop database if exists "{name}" with (force)')
    finally:
        await conn.close()


class ScratchDatabase:
    """A throwaway database in the scratch server, migrated with the real migrations."""

    def __init__(self) -> None:
        self.name = f"cs_test_{uuid.uuid4().hex[:12]}"
        self._previous = settings.postgres_db

    def __enter__(self) -> "ScratchDatabase":
        asyncio.run(_create_database(self.name))
        settings.postgres_db = self.name
        return self

    def __exit__(self, *exc) -> None:
        settings.postgres_db = self._previous
        asyncio.run(_drop_database(self.name))

    def upgrade(self, revision: str = "head") -> None:
        command.upgrade(alembic_config(), revision)

    def downgrade(self, revision: str) -> None:
        command.downgrade(alembic_config(), revision)


@pytest.fixture(scope="session")
def database():
    with ScratchDatabase() as db:
        db.upgrade()

        async def seed_once() -> None:
            conn = await asyncpg.connect(settings.postgres_dsn)
            try:
                await seed(conn, admin_username="seed-admin", admin_password="Seed-Admin-Password-1!")
            finally:
                await conn.close()

        asyncio.run(seed_once())
        asyncio.run(_remember_seed())
        yield db


_SEEDED: dict = {}


async def _remember_seed() -> None:
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        # Keyed by role name and permission code, not by id: a test may delete and re-create a permission.
        _SEEDED["role_permissions"] = [
            (r["role"], r["code"]) for r in await conn.fetch(
                "select r.name as role, p.code from role_permissions rp join roles r on r.id = rp.role_id join permissions p on p.id = rp.permission_id")
        ]
        _SEEDED["scope_modes"] = [(r["name"], r["scope_mode"]) for r in await conn.fetch("select name, scope_mode from roles")]
        # Keyed by event_name: a test may deliberately tamper with a seeded row (Plan 29's re-seed-protection test),
        # and nothing else ever resets notification_event_config between tests - it has no FK to a _MUTABLE table.
        _SEEDED["notification_event_config"] = [
            (r["event_name"], r["enabled"], r["delay_before_send_seconds"], r["send_resolved"], r["check_uplink"])
            for r in await conn.fetch(
                "select event_name, enabled, delay_before_send_seconds, send_resolved, check_uplink from notification_event_config")
        ]
    finally:
        await conn.close()


_MUTABLE = "users, devices, device_groups, device_models, device_access_profiles, resellers, audit_logs, login_attempts, worker_heartbeats, dead_letter_jobs, oid_transform_rules, schedule_jobs"


@pytest_asyncio.fixture
async def clean(database):
    """Empty every table tests write to. Roles, permissions and their links (the seed) are kept."""
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        await conn.execute(f"truncate {_MUTABLE} restart identity cascade")
        # CASCADE reaches further than _MUTABLE lists: vendors and vendor_model_families both hold a polling_toggled_by
        # FK to users, so truncating users cascades transitively through them to oid_profiles, oid_profile_entries,
        # oid_definitions and device_models too. Put all of it back, in the same order the real seed() does.
        await _seed_registry(conn, SeedResult())
        await _seed_schedule(conn)
        await _seed_device_models(conn)
        await _seed_standard_oid_profiles(conn)
        await _seed_bdcom_switch_profile(conn)
        # Tests change roles on purpose (grant a permission, flip a scope mode). Put them back so tests stay independent.
        await conn.execute("delete from role_permissions")
        await conn.executemany(
            "insert into role_permissions (role_id, permission_id) select r.id, p.id from roles r, permissions p where r.name = $1 and p.code = $2",
            _SEEDED["role_permissions"],
        )
        await conn.executemany("update roles set scope_mode = $2 where name = $1", _SEEDED["scope_modes"])
        # Same reasoning: a test may tamper with a seeded notification_event_config row on purpose.
        await conn.executemany(
            "update notification_event_config set enabled = $2, delay_before_send_seconds = $3, send_resolved = $4, "
            "check_uplink = $5 where event_name = $1",
            _SEEDED["notification_event_config"],
        )
    finally:
        await conn.close()
    from redis.asyncio import Redis

    redis = Redis.from_url(settings.redis_dsn)
    await redis.flushdb()
    await redis.aclose()
    yield


@pytest_asyncio.fixture
async def app_client(clean):
    from app.main import app

    async with app.router.lifespan_context(app):
        transport = httpx.ASGITransport(app=app, client=TRUSTED_CLIENT)
        async with httpx.AsyncClient(transport=transport, base_url="http://cybersathy.test") as client:
            client.app = app  # type: ignore[attr-defined]
            yield client


@pytest_asyncio.fixture
async def db(clean):
    conn = await asyncpg.connect(settings.postgres_dsn)
    try:
        yield conn
    finally:
        await conn.close()
