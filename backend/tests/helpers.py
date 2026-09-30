from __future__ import annotations

import ipaddress
from typing import Any

import asyncpg
import httpx

from app.core.passwords import SCHEME_ARGON2, hash_password

DEFAULT_PASSWORD = "Correct-Horse-Battery-9"


async def make_user(
    conn: asyncpg.Connection,
    username: str,
    role: str = "ISP Admin",
    password: str | None = DEFAULT_PASSWORD,
    *,
    must_change: bool = False,
    active: bool = True,
    password_hash: str | None = None,
    strict_ips: list[str] | None = None,
    scope_mode: str | None = None,
) -> str:
    role_id = await conn.fetchval("select id from roles where name = $1", role)
    if scope_mode:
        await conn.execute("update roles set scope_mode = $2 where id = $1", role_id, scope_mode)
    stored = password_hash if password_hash is not None else (hash_password(password) if password else None)
    scheme = SCHEME_ARGON2 if password_hash is None and password else None
    allowed = [ipaddress.ip_network(i, strict=False) for i in strict_ips] if strict_ips else None
    return str(await conn.fetchval(
        """
        insert into users (role_id, username, display_name, password_hash, hash_scheme, is_active, must_change_password,
                           strict_ip_enabled, allowed_ips)
        values ($1, $2, $3, $4, $5, $6, $7, $8, $9) returning id
        """,
        role_id, username, username.title(), stored, scheme, active, must_change, bool(strict_ips), allowed,
    ))


async def login(client: httpx.AsyncClient, username: str, password: str = DEFAULT_PASSWORD, **extra: Any) -> httpx.Response:
    return await client.post("/api/v1/auth/login", json={"login": username, "password": password, **extra})


async def bearer(client: httpx.AsyncClient, username: str, password: str = DEFAULT_PASSWORD) -> dict[str, str]:
    response = await login(client, username, password)
    assert response.status_code == 200, response.text
    return {"Authorization": f"Bearer {response.json()['token']}"}


async def make_group(conn: asyncpg.Connection, name: str, parent: str | None = None) -> str:
    return str(await conn.fetchval("insert into device_groups (name, parent_id) values ($1, $2::uuid) returning id", name, parent))


_counter = 0


async def make_device(conn: asyncpg.Connection, name: str, group: str | None = None, ip: str | None = None) -> str:
    global _counter
    _counter += 1
    ip = ip or f"10.99.{_counter // 250}.{_counter % 250 + 1}"
    return str(await conn.fetchval(
        "insert into devices (name, group_id, management_ip, device_type) values ($1, $2::uuid, $3::inet, 'switch') returning id",
        name, group, ip,
    ))


async def make_interface(conn: asyncpg.Connection, device: str, name: str, if_index: int = 1) -> str:
    return str(await conn.fetchval(
        "insert into interfaces (device_id, if_index, name) values ($1::uuid, $2, $3) returning id", device, if_index, name,
    ))
