"""What a worker does with a job."""
from __future__ import annotations

import json
import re
import uuid
from typing import Any

import asyncpg

from app.polling.engine import Context, Sink, claim_slot, credentials_from, load_device, pollable_reason, poll_device, record_failure, record_success, scrub
from app.polling.transport import BoundedTransport, Target, TransportError, TransportTimeout
from app.registry.detection import detect
from app.repositories import device_models as device_model_repo
from app.workers.queue import Message, Permanent, Skipped

SAFE_OIDS = {"1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0", "1.3.6.1.2.1.1.5.0"}
OID_SYS_DESCR, OID_SYS_OBJECT_ID, OID_SYS_UPTIME, OID_SYS_NAME = "1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0", "1.3.6.1.2.1.1.5.0"
_CONTROL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
_OBJECT_ID = re.compile(r"^[0-9]+(\.[0-9]+)+$")


def clean_text(value: Any, limit: int, *, single_line: bool = False) -> str:
    """Device output is untrusted input. Strip control characters and cap the length before it is stored or shown."""
    text = _CONTROL.sub("", str(value))
    if single_line:
        text = " ".join(text.split())
    return text[:limit]


async def _finish_job(conn: asyncpg.Connection, discovery_id: str, status: str, *, error: str | None = None, result: dict[str, Any] | None = None) -> None:
    await conn.execute(
        "update discovery_jobs set status = $2, finished_at = now(), error = $3, result = $4::jsonb where id = $1::uuid",
        discovery_id, status, error, json.dumps(result) if result is not None else None,
    )


async def handle_discovery(ctx: Context, message: Message) -> None:
    discovery_id = message.fields.get("discovery_id", "")
    try:
        uuid.UUID(discovery_id)
    except ValueError as exc:
        raise Permanent("message has no valid discovery_id") from exc

    async with ctx.pool.acquire() as conn:
        # Claim the job atomically. A second delivery of the same job finds it no longer queued and does nothing, so the
        # device is contacted once however many times the message arrives. A job stuck `running` after a crash is re-claimable.
        job = await conn.fetchrow(
            """
            update discovery_jobs set status = 'running', started_at = now()
            where id = $1::uuid and (status = 'queued' or (status = 'running' and started_at < now() - interval '5 minutes'))
            returning id, device_id, oids, timeout_ms, retries
            """,
            discovery_id,
        )
        if job is None:
            raise Skipped("discovery job already handled or unknown")
        # What to ask comes from the database row, never from the message.
        oids = list(job["oids"])
        if not set(oids) <= SAFE_OIDS:
            await _finish_job(conn, discovery_id, "failed", error="job names an OID outside the safe discovery set")
            raise Permanent("unsafe OIDs")
        device_id = str(job["device_id"])
        row = await load_device(conn, device_id)
        reason = "the device no longer exists" if row is None else await pollable_reason(conn, row, require_polling_enabled=False)
        if reason:
            await conn.execute("update discovery_jobs set status = 'skipped', finished_at = now(), error = $2 where id = $1::uuid", discovery_id, reason)
            raise Skipped(reason)
    if ctx.enc is None:
        async with ctx.pool.acquire() as conn:
            await _finish_job(conn, discovery_id, "failed", error="credential encryption is not configured")
        return

    async with ctx.locks.device(device_id, ctx.cfg.poll_device_lock_ms) as have_device:
        if not have_device:
            async with ctx.pool.acquire() as conn:
                await conn.execute("update discovery_jobs set status = 'queued', started_at = null where id = $1::uuid", discovery_id)
            raise Skipped("another operation on this device is in progress")
        async with ctx.pool.acquire() as conn:
            claimed, why_not = await claim_slot(conn, ctx.cfg, device_id)
            if not claimed:
                await conn.execute("update discovery_jobs set status = 'skipped', finished_at = now(), error = $2 where id = $1::uuid", discovery_id, why_not)
                raise Skipped(why_not)
        creds = credentials_from(row, ctx.enc)
        bounded = BoundedTransport(ctx.transport)
        try:
            values = await bounded.get(Target(row["address"], creds), oids, timeout_ms=job["timeout_ms"], retries=job["retries"])
        except TransportError as exc:
            error = scrub(str(exc) or ("timeout" if isinstance(exc, TransportTimeout) else "transport error"), creds.secrets())
            async with ctx.pool.acquire() as conn:
                await _finish_job(conn, discovery_id, "failed", error=error)
                await record_failure(conn, ctx.cfg, device_id, error)
            return

    sys_object_id = values.get(OID_SYS_OBJECT_ID)
    if sys_object_id is not None and not _OBJECT_ID.match(str(sys_object_id).strip().lstrip(".")):
        async with ctx.pool.acquire() as conn:
            await _finish_job(conn, discovery_id, "failed", error="the device returned a sysObjectID that is not an OID")
            await record_failure(conn, ctx.cfg, device_id, "invalid sysObjectID")
        return
    result = {
        "sys_name": clean_text(values[OID_SYS_NAME], 255, single_line=True) if OID_SYS_NAME in values else None,
        "sys_descr": clean_text(values[OID_SYS_DESCR], 2000) if OID_SYS_DESCR in values else None,
        "sys_object_id": str(sys_object_id).strip().lstrip(".") if sys_object_id is not None else None,
        "sys_uptime_ticks": int(values[OID_SYS_UPTIME]) if isinstance(values.get(OID_SYS_UPTIME), (int, float)) else None,
    }
    async with ctx.pool.acquire() as conn:
        rules = await device_model_repo.load_rules(conn)
        outcome = detect(rules, sys_descr=result["sys_descr"], sys_object_id=result["sys_object_id"])
        # Advisory only: a detected model never changes the vendor/family an operator already chose, so it can never
        # silently switch which poller family a device is subject to. An ambiguous or unmatched result changes nothing.
        detected_model_id = outcome.matched.id if outcome.matched else None
        result["detected_model"] = {
            "status": outcome.status,
            "key": outcome.matched.key if outcome.matched else None,
            "name": outcome.matched.name if outcome.matched else None,
        }
        if outcome.ambiguous:
            result["detected_model"]["candidates"] = [c.key for c in outcome.candidates]
        async with conn.transaction():
            await conn.execute(
                "update devices set sys_name = $2, sys_descr = $3, sys_object_id = $4, model_id = coalesce($5::uuid, model_id), updated_at = now() where id = $1::uuid",
                device_id, result["sys_name"], result["sys_descr"], result["sys_object_id"], detected_model_id,
            )
            await _finish_job(conn, discovery_id, "succeeded", result=result)
            await record_success(conn, device_id)


async def handle_poll(ctx: Context, message: Message, sink: Sink | None = None) -> None:
    device_id, profile = message.fields.get("device_id", ""), message.fields.get("profile", "")
    try:
        uuid.UUID(device_id)
    except ValueError as exc:
        raise Permanent("message has no valid device_id") from exc
    if not profile:
        raise Permanent("message names no profile")
    # A skip (busy, breaker open, switched off) is recorded by the engine and is not an error to retry.
    await poll_device(ctx, device_id, profile, sink)
