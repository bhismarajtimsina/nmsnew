"""Turns a decoded trap into a `trap_history` row, resolving it to a device by source IP and to a known definition
by OID - the same shape Plan 20's Alertmanager ingestion uses for its own device resolution.

No device is contacted: this only records what a device already sent, addressed to whatever the UDP listener
(D-19, not yet built) is bound to.
"""
from __future__ import annotations

import json
from dataclasses import dataclass

import asyncpg

from app.core.crypto import EncryptionService
from app.repositories.access_profiles import aad
from app.traps.decode import DecodedTrap


@dataclass
class TrapOutcome:
    accepted: bool  # False: the source IP matches no device, and nothing was stored
    known: bool  # True: the OID matched a trap_profiles row
    device_id: str | None
    community_ok: bool = True  # False: a known device, but check_community rejected it - nothing was stored either


async def resolve_device_by_source(conn: asyncpg.Connection, source_ip: str) -> str | None:
    row = await conn.fetchval("select id from devices where management_ip = $1::inet", source_ip)
    return str(row) if row is not None else None


def community_matches(trap_community: str, *configured: str | None) -> bool:
    """Ported from `TrapService\\Controllers\\Controller::handleTrap`'s own check: a trap is accepted when its community
    equals either the device's public *or* private community. The access profile holds both again since Plan 38 added
    the write community (legacy's `private_community`), so both are compared. A profile with neither configured (a v3
    profile, or none at all) matches nothing, the same as legacy comparing against two nulls."""
    return any(trap_community == value for value in configured)  # a None (not configured) never equals a string


async def _configured_communities(conn: asyncpg.Connection, device_id: str, enc: EncryptionService) -> tuple[str | None, str | None]:
    row = await conn.fetchrow(
        "select p.id, p.snmp_community_enc, p.snmp_write_community_enc from devices d "
        "join device_access_profiles p on p.id = d.access_profile_id where d.id = $1::uuid",
        device_id,
    )
    if row is None:
        return None, None
    pid = str(row["id"])
    public = enc.decrypt(row["snmp_community_enc"], aad(pid, "snmp_community")) if row["snmp_community_enc"] else None
    private = enc.decrypt(row["snmp_write_community_enc"], aad(pid, "snmp_write_community")) if row["snmp_write_community_enc"] else None
    return public, private


async def record_trap(
    conn: asyncpg.Connection, source_ip: str, decoded: DecodedTrap, *,
    enc: EncryptionService | None = None, check_community: bool = False,
) -> TrapOutcome:
    """Accepts a trap only from a known device's source IP - the plan's own safety rule ("accept traps only from
    known device IPs"). A trap from an unrecognized source is dropped before it ever reaches a query: there is
    nothing worth keeping from a source this system cannot attribute to a device, and storing it would make this an
    easy way to fill the database from off-network. The caller counts the drop (a rate limiter or metric, D-19's
    job, not this function's).

    `check_community` is `TRAP_SERVICE_CHECK_COMMUNITY` - off by default in the real legacy `.env` too, ported
    exactly: when off, a trap's community is never even read from its access profile, let alone compared, matching
    `_env('TRAP_SERVICE_CHECK_COMMUNITY', false)` guarding the whole check in `handleTrap`. When on, a mismatch is
    dropped without being stored - legacy throws before ever reaching `trapLogStorage->add()`."""
    device_id = await resolve_device_by_source(conn, source_ip)
    if device_id is None:
        return TrapOutcome(accepted=False, known=False, device_id=None)

    if check_community:
        assert enc is not None, "check_community requires an EncryptionService"
        public, private = await _configured_communities(conn, device_id, enc)
        if not community_matches(decoded.community, public, private):
            return TrapOutcome(accepted=True, known=False, device_id=device_id, community_ok=False)

    profile = await conn.fetchrow("select id, vendor, name from trap_profiles where oid = $1", decoded.trap_oid)
    await conn.execute(
        """
        insert into trap_history (source_ip, device_id, trap_profile_id, vendor, trap_name, trap_oid, version, varbinds)
        values ($1::inet, $2::uuid, $3::uuid, $4, $5, $6, $7, $8::jsonb)
        """,
        source_ip, device_id, profile["id"] if profile else None, profile["vendor"] if profile else None,
        profile["name"] if profile else None, decoded.trap_oid, decoded.version, json.dumps(decoded.varbinds),
    )
    return TrapOutcome(accepted=True, known=profile is not None, device_id=device_id)
