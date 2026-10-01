"""The polling engine: run one active profile against one device, safely.

Order of events for a poll, and why:
  1. Read everything needed from the database, then release the connection. No network I/O happens while a connection is held.
  2. Refuse (and record why) unless the device is allowed to be polled by this system right now: polling switched on, owned by
     this system, vendor and family not switched off, an access profile, an active profile that fits it, breaker closed.
  3. Take the per-device lock and a per-vendor slot. If either is busy the poll is skipped, not queued behind.
  4. Claim the poll slot (minimum interval between polls of one device).
  5. Talk to the device only through `BoundedTransport`. The first timeout or error ends the poll: a dead device is asked once,
     not once per profile entry.
  6. Record the outcome, update the circuit breaker, and hand readings to the sink.
Credentials are decrypted in memory for the duration of the poll and scrubbed from any error text before it is stored.
"""
from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from typing import Any, Protocol

import asyncpg
from prometheus_client import Counter
from redis.asyncio import Redis

from app.core.config import Settings
from app.core.crypto import EncryptionService
from app.polling.transport import (
    BoundedTransport,
    Credentials,
    SnmpTransport,
    Target,
    TransportError,
    TransportTimeout,
)
from app.registry.oid import Definition, Entry, profile_problems
from app.repositories import access_profiles as profile_repo
from app.repositories import vendors as vendor_repo
from app.workers.queue import Locks

logger = logging.getLogger("cybersathy.polling")
POLL_TRUNCATIONS = Counter("cybersathy_poll_truncations_total", "Walked columns cut at their max_rows", ["profile"])


@dataclass
class Reading:
    name: str
    oid: str
    value: Any
    unit: str | None = None
    in_range: bool = True


@dataclass
class PollOutcome:
    status: str  # ok | timeout | error | skipped
    rows: int = 0
    truncated: bool = False
    truncated_columns: list[str] = field(default_factory=list)
    duration_ms: int = 0
    error: str | None = None
    profile_version: int | None = None
    readings: list[Reading] = field(default_factory=list)


class Sink(Protocol):
    async def write(self, device_id: str, profile_name: str, readings: list[Reading]) -> None: ...


@dataclass
class Context:
    pool: asyncpg.Pool
    redis: Redis
    transport: SnmpTransport
    enc: EncryptionService | None
    cfg: Settings

    @property
    def locks(self) -> Locks:
        return Locks(self.redis)


DEVICE_SQL = """
    select d.id, host(d.management_ip) as address, d.polling_enabled, d.polling_owner, d.access_profile_id, d.vendor_id, d.family_id,
           v.slug as vendor_slug, f.slug as family_slug, p.timeout_ms as profile_timeout, p.retries as profile_retries,
           p.snmp_version, p.snmp_community_enc, p.snmp_v3_username, p.snmp_v3_auth_protocol, p.snmp_v3_auth_secret_enc,
           p.snmp_v3_priv_protocol, p.snmp_v3_priv_secret_enc,
           s.breaker_open_until, s.consecutive_failures
    from devices d
    left join vendor_model_families f on f.id = d.family_id
    left join vendors v on v.id = f.vendor_id
    left join device_access_profiles p on p.id = d.access_profile_id
    left join device_poll_state s on s.device_id = d.id
    where d.id = $1::uuid
"""


async def load_device(conn: asyncpg.Connection, device_id: str) -> asyncpg.Record | None:
    return await conn.fetchrow(DEVICE_SQL, device_id)


async def pollable_reason(conn: asyncpg.Connection, row: asyncpg.Record, *, require_polling_enabled: bool = True) -> str | None:
    """Why this system must not talk to the device right now, or None when it may."""
    if row["polling_owner"] != "cybersathy":
        return "the device is still polled by the legacy system"
    if require_polling_enabled and not row["polling_enabled"]:
        return "polling is switched off for this device"
    if row["access_profile_id"] is None:
        return "no access profile: nothing to authenticate with"
    if row["vendor_slug"] is not None and not await vendor_repo.polling_allowed(conn, row["vendor_slug"], row["family_slug"]):
        return "polling is switched off for this vendor or model family"
    return None


def credentials_from(row: asyncpg.Record, enc: EncryptionService) -> Credentials:
    pid = str(row["access_profile_id"])

    def secret(column: str, field_name: str) -> str | None:
        return enc.decrypt(row[column], profile_repo.aad(pid, field_name)) if row[column] else None

    return Credentials(
        version=row["snmp_version"], community=secret("snmp_community_enc", "snmp_community"), v3_username=row["snmp_v3_username"],
        v3_auth_protocol=row["snmp_v3_auth_protocol"], v3_auth_secret=secret("snmp_v3_auth_secret_enc", "snmp_v3_auth_secret"),
        v3_priv_protocol=row["snmp_v3_priv_protocol"], v3_priv_secret=secret("snmp_v3_priv_secret_enc", "snmp_v3_priv_secret"),
    )


def scrub(text: str, secrets: list[str]) -> str:
    """Remove every credential from text before it is stored or shown. Device errors sometimes echo what they were sent."""
    for secret in sorted(secrets, key=len, reverse=True):
        text = text.replace(secret, "[redacted]")
    return text[:500]


async def record_failure(conn: asyncpg.Connection, cfg: Settings, device_id: str, error: str) -> None:
    """Count a failure. Reaching the threshold opens the circuit breaker; after the cool-down the next attempt is a probe, and
    because the count is still at the threshold a failed probe opens it again at once."""
    await conn.execute(
        """
        insert into device_poll_state (device_id, consecutive_failures, last_failure_at, last_error)
        values ($1::uuid, 1, now(), $2)
        on conflict (device_id) do update set
            consecutive_failures = device_poll_state.consecutive_failures + 1, last_failure_at = now(), last_error = $2,
            breaker_open_until = case when device_poll_state.consecutive_failures + 1 >= $3
                                      then now() + make_interval(secs => $4) else device_poll_state.breaker_open_until end
        """,
        device_id, error, cfg.poll_breaker_threshold, float(cfg.poll_breaker_cooldown_seconds),
    )


async def record_success(conn: asyncpg.Connection, device_id: str) -> None:
    await conn.execute(
        """
        insert into device_poll_state (device_id, consecutive_failures, last_success_at) values ($1::uuid, 0, now())
        on conflict (device_id) do update set consecutive_failures = 0, breaker_open_until = null, last_success_at = now(), last_error = null
        """,
        device_id,
    )


async def claim_slot(conn: asyncpg.Connection, cfg: Settings, device_id: str) -> tuple[bool, str | None]:
    """Atomically take the right to poll now. Refused while the breaker is open or when the device was polled less than the
    minimum interval ago."""
    got = await conn.fetchval(
        """
        insert into device_poll_state (device_id, last_polled_at) values ($1::uuid, now())
        on conflict (device_id) do update set last_polled_at = now()
        where (device_poll_state.breaker_open_until is null or device_poll_state.breaker_open_until <= now())
          and (device_poll_state.last_polled_at is null
               or device_poll_state.last_polled_at <= now() - make_interval(secs => $2))
        returning device_id
        """,
        device_id, float(cfg.poll_min_interval_seconds),
    )
    if got is not None:
        return True, None
    state = await conn.fetchrow("select breaker_open_until > now() as open, breaker_open_until from device_poll_state where device_id = $1::uuid", device_id)
    if state and state["open"]:
        return False, f"circuit breaker open until {state['breaker_open_until']:%H:%M:%S} after repeated failures"
    return False, f"polled less than {cfg.poll_min_interval_seconds} seconds ago"


def scalar_instance(numeric_oid: str) -> str:
    """The instance a `get` entry actually requests. Definitions hold the MIB object's OID (what `mib check` compares
    against the MIB), but an agent answers a GET only for an instance, and a scalar's only instance is `.0`: a GET for
    sysDescr is a GET for 1.3.6.1.2.1.1.1.0. Asking for the bare object OID gets "no such instance" from a real agent.
    An OID already ending in `.0` is taken as already instanced. Table columns are read with `walk`/`getnext`, never
    `get`, so no other instance suffix is needed here."""
    return numeric_oid if numeric_oid.endswith(".0") else numeric_oid + ".0"


async def _load_profile(conn: asyncpg.Connection, name: str, row: asyncpg.Record) -> tuple[asyncpg.Record | None, list[asyncpg.Record]]:
    profile = await conn.fetchrow(
        """
        select id, name, version from oid_profiles
        where name = $1 and status = 'active' and (vendor_id is null or vendor_id = $2::uuid) and (family_id is null or family_id = $3::uuid)
        order by (family_id is not null) desc, (vendor_id is not null) desc limit 1
        """,
        name, row["vendor_id"], row["family_id"],
    )
    if profile is None:
        return None, []
    entries = await conn.fetch(
        """
        select d.logical_name, d.numeric_oid, d.access, d.safety_level, d.unit, e.walk_strategy, e.max_rows, e.timeout_ms,
               t.kind as transform_kind, t.factor, t.valid_min, t.valid_max
        from oid_profile_entries e join oid_definitions d on d.id = e.definition_id
        left join oid_transform_rules t on t.id = e.transform_id
        where e.profile_id = $1 order by e.position, d.logical_name
        """,
        profile["id"],
    )
    return profile, entries


def _transform(entry: asyncpg.Record, raw: Any) -> tuple[Any, bool]:
    if entry["transform_kind"] == "scale" and isinstance(raw, (int, float)):
        value = raw * float(entry["factor"])
        return value, float(entry["valid_min"]) <= value <= float(entry["valid_max"])
    return raw, True


async def _record(ctx: Context, device_id: str, profile_name: str, outcome: PollOutcome) -> None:
    async with ctx.pool.acquire() as conn:
        await conn.execute(
            """
            insert into polling_results (device_id, profile_name, profile_version, duration_ms, rows, truncated, outcome, error)
            values ($1::uuid, $2, $3, $4, $5, $6, $7, $8)
            """,
            device_id, profile_name, outcome.profile_version, outcome.duration_ms, outcome.rows, outcome.truncated, outcome.status, outcome.error,
        )
        if outcome.status == "ok":
            await record_success(conn, device_id)
        elif outcome.status in ("timeout", "error"):
            await record_failure(conn, ctx.cfg, device_id, outcome.error or outcome.status)


async def poll_device(ctx: Context, device_id: str, profile_name: str, sink: Sink | None = None) -> PollOutcome:
    started = time.monotonic()

    def finish(outcome: PollOutcome) -> PollOutcome:
        outcome.duration_ms = int((time.monotonic() - started) * 1000)
        return outcome

    async with ctx.pool.acquire() as conn:
        row = await load_device(conn, device_id)
        if row is None:
            return PollOutcome("skipped", error="device not found")
        reason = await pollable_reason(conn, row)
        profile, entries = (None, []) if reason else await _load_profile(conn, profile_name, row)
    if reason is None and profile is None:
        reason = f"no active profile named {profile_name!r} fits this device"
    if reason is None:
        as_entries = [Entry(Definition(e["logical_name"], e["numeric_oid"], e["access"], e["safety_level"]), e["walk_strategy"], e["max_rows"], e["timeout_ms"]) for e in entries]
        problems = profile_problems(as_entries)
        if problems:  # the database already forbids these; check again so a corrupt row can never reach a device
            reason = "profile failed validation: " + "; ".join(problems)
    version = profile["version"] if profile else None
    if reason:
        outcome = finish(PollOutcome("skipped", error=reason, profile_version=version))
        await _record(ctx, device_id, profile_name, outcome)
        return outcome

    if ctx.enc is None:
        outcome = finish(PollOutcome("error", error="credential encryption is not configured", profile_version=version))
        await _record(ctx, device_id, profile_name, outcome)
        return outcome

    vendor = row["vendor_slug"] or "unknown"
    lock_ms = ctx.cfg.poll_device_lock_ms
    async with ctx.locks.device(device_id, lock_ms) as have_device:
        if not have_device:
            outcome = finish(PollOutcome("skipped", error="another poll of this device is in progress", profile_version=version))
            await _record(ctx, device_id, profile_name, outcome)
            return outcome
        async with ctx.locks.vendor(vendor, ctx.cfg.poll_vendor_concurrency, lock_ms) as have_slot:
            if not have_slot:
                outcome = finish(PollOutcome("skipped", error=f"too many concurrent polls for vendor {vendor}", profile_version=version))
                await _record(ctx, device_id, profile_name, outcome)
                return outcome
            async with ctx.pool.acquire() as conn:
                claimed, why_not = await claim_slot(conn, ctx.cfg, device_id)
            if not claimed:
                outcome = finish(PollOutcome("skipped", error=why_not, profile_version=version))
                await _record(ctx, device_id, profile_name, outcome)
                return outcome
            outcome = await _run(ctx, row, entries, version)
            for column in outcome.truncated_columns:
                # Plan 19: a table larger than its max_rows is cut, flagged and counted, never walked further.
                POLL_TRUNCATIONS.labels(profile=profile_name).inc()
                logger.warning("poll truncated: device %s, profile %s, column %s hit its max_rows", device_id, profile_name, column)
    outcome = finish(outcome)
    await _record(ctx, device_id, profile_name, outcome)
    if outcome.status == "ok" and sink is not None:
        await sink.write(device_id, profile_name, outcome.readings)
    return outcome


async def _run(ctx: Context, row: asyncpg.Record, entries: list[asyncpg.Record], version: int | None) -> PollOutcome:
    creds = credentials_from(row, ctx.enc)  # type: ignore[arg-type]
    secrets = creds.secrets()
    target = Target(row["address"], creds)
    bounded = BoundedTransport(ctx.transport)
    retries = row["profile_retries"] if row["profile_retries"] is not None else 1
    default_timeout = row["profile_timeout"] or 2000
    readings: list[Reading] = []

    try:
        single = [e for e in entries if e["walk_strategy"] == "get"]
        if single:
            values = await bounded.get(target, [scalar_instance(e["numeric_oid"]) for e in single], timeout_ms=default_timeout, retries=retries)
            for e in single:
                instance = scalar_instance(e["numeric_oid"])
                if instance in values:
                    value, ok = _transform(e, values[instance])
                    readings.append(Reading(e["logical_name"], e["numeric_oid"], value, e["unit"], ok))
        for e in entries:
            if e["walk_strategy"] == "get":
                continue
            getnext = e["walk_strategy"] == "getnext"
            rows = await bounded.walk(
                target, e["numeric_oid"], max_rows=1 if getnext else e["max_rows"],
                timeout_ms=e["timeout_ms"] or default_timeout, retries=retries, probe=not getnext)
            converted = [(oid, *_transform(e, value)) for oid, value in rows]
            readings.append(Reading(e["logical_name"], e["numeric_oid"], [(o, v) for o, v, _ in converted], e["unit"], all(ok for *_, ok in converted)))
    except TransportTimeout as exc:
        return PollOutcome("timeout", rows=bounded.rows_returned, truncated=bounded.truncated, error=scrub(str(exc) or "timeout", secrets), profile_version=version,
                           truncated_columns=list(bounded.truncated_roots))
    except TransportError as exc:
        return PollOutcome("error", rows=bounded.rows_returned, truncated=bounded.truncated, error=scrub(str(exc) or type(exc).__name__, secrets), profile_version=version,
                           truncated_columns=list(bounded.truncated_roots))
    return PollOutcome("ok", rows=bounded.rows_returned, truncated=bounded.truncated, profile_version=version, readings=readings,
                       truncated_columns=list(bounded.truncated_roots))
