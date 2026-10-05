"""Device access profiles: SNMP credentials, stored encrypted and never returned.

Each secret is encrypted with the profile id and field name as context, so a ciphertext copied to another profile or
another field does not decrypt.
"""
from __future__ import annotations

import uuid
from typing import Any

import asyncpg

from app.core.crypto import EncryptionService

SECRET_FIELDS = {"snmp_community": "snmp_community_enc", "snmp_write_community": "snmp_write_community_enc",
                 "snmp_v3_auth_secret": "snmp_v3_auth_secret_enc", "snmp_v3_priv_secret": "snmp_v3_priv_secret_enc"}

SELECT = """
    select p.id, p.name, p.snmp_version, p.timeout_ms, p.retries, p.snmp_v3_username, p.snmp_v3_auth_protocol, p.snmp_v3_priv_protocol,
           p.snmp_community_enc is not null as has_community, p.snmp_v3_auth_secret_enc is not null as has_auth_secret,
           p.snmp_v3_priv_secret_enc is not null as has_priv_secret,
           p.snmp_write_community_enc is not null as has_write_community, p.legacy_id, p.created_at, p.updated_at,
           (select count(*) from devices d where d.access_profile_id = p.id) as devices_using
    from device_access_profiles p
"""


def aad(profile_id: str, field: str) -> str:
    return f"device_access_profile:{profile_id}:{field}"


def as_dict(row: asyncpg.Record) -> dict[str, Any]:
    return {**dict(row), "id": str(row["id"])}


async def list_profiles(conn: asyncpg.Connection) -> list[dict[str, Any]]:
    return [as_dict(r) for r in await conn.fetch(SELECT + " order by p.name")]


async def get_profile(conn: asyncpg.Connection, profile_id: str) -> dict[str, Any] | None:
    row = await conn.fetchrow(SELECT + " where p.id = $1::uuid", profile_id)
    return as_dict(row) if row else None


async def create_profile(conn: asyncpg.Connection, enc: EncryptionService, data: dict[str, Any]) -> str:
    profile_id = str(uuid.uuid4())
    secrets = {column: enc.encrypt(data[field], aad(profile_id, field)) for field, column in SECRET_FIELDS.items() if data.get(field)}
    await conn.execute(
        """
        insert into device_access_profiles (id, name, snmp_version, snmp_community_enc, snmp_v3_username, snmp_v3_auth_protocol,
                                            snmp_v3_auth_secret_enc, snmp_v3_priv_protocol, snmp_v3_priv_secret_enc, timeout_ms, retries,
                                            snmp_write_community_enc)
        values ($1::uuid, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
        """,
        profile_id, data["name"], data["snmp_version"], secrets.get("snmp_community_enc"), data.get("snmp_v3_username"),
        data.get("snmp_v3_auth_protocol"), secrets.get("snmp_v3_auth_secret_enc"), data.get("snmp_v3_priv_protocol"),
        secrets.get("snmp_v3_priv_secret_enc"), data["timeout_ms"], data["retries"], secrets.get("snmp_write_community_enc"),
    )
    return profile_id


async def update_profile(conn: asyncpg.Connection, enc: EncryptionService | None, profile_id: str, changes: dict[str, Any]) -> None:
    for column in ("name", "timeout_ms", "retries", "snmp_v3_username", "snmp_v3_auth_protocol", "snmp_v3_priv_protocol"):
        if column in changes:
            await conn.execute(f"update device_access_profiles set {column} = $2, updated_at = now() where id = $1::uuid", profile_id, changes[column])
    for field, column in SECRET_FIELDS.items():
        if changes.get(field):
            assert enc is not None
            await conn.execute(f"update device_access_profiles set {column} = $2, updated_at = now() where id = $1::uuid",
                               profile_id, enc.encrypt(changes[field], aad(profile_id, field)))


async def delete_profile(conn: asyncpg.Connection, profile_id: str) -> None:
    await conn.execute("delete from device_access_profiles where id = $1::uuid", profile_id)


async def write_credentials_row(conn: asyncpg.Connection, profile_id: str) -> asyncpg.Record | None:
    """What a device action needs to write: version, the write community (v1/v2c) or the v3 user. Only app/actions
    calls this; polling reads the profile through the engine's own query and never selects the write community."""
    return await conn.fetchrow(
        """
        select id as access_profile_id, snmp_version, snmp_write_community_enc, snmp_v3_username, snmp_v3_auth_protocol,
               snmp_v3_auth_secret_enc, snmp_v3_priv_protocol, snmp_v3_priv_secret_enc, timeout_ms
        from device_access_profiles where id = $1::uuid
        """,
        profile_id,
    )
