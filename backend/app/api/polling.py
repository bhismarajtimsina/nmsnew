from __future__ import annotations

import uuid
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field
from redis.asyncio import Redis
from redis.exceptions import RedisError

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.redis import get_redis
from app.core.security import CurrentUser, require
from app.polling.engine import load_device, pollable_reason
from app.repositories import devices as device_repo
from app.repositories import polling as polling_repo
from app.workers import heartbeat
from app.workers.queue import JobQueue, SigningKeyMissing
from app.workers.runner import POLL_STREAM

router = APIRouter(prefix=settings.api_prefix, tags=["polling"])


class PollRequest(BaseModel):
    profile: str = Field(min_length=1, max_length=120, pattern=r"^[a-z0-9_.-]+$")


def _device_id(value: str) -> str:
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


@router.get("/devices/{device_id}/poll-history", response_model=schemas.PollHistory)
async def poll_history(
    device_id: str,
    user: Annotated[CurrentUser, Depends(require("pollers.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
) -> dict[str, Any]:
    history = await polling_repo.poll_history(conn, user, _device_id(device_id), limit)
    if history is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return history


@router.post("/devices/{device_id}/poll", status_code=status.HTTP_202_ACCEPTED, response_model=schemas.PollQueued)
async def request_poll(
    device_id: str,
    payload: PollRequest,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("pollers.run"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """Ask for a poll now. Scoped, rate-limited per user, audited, and refused at once when the device cannot be polled."""
    did = _device_id(device_id)
    if await device_repo.get_device(conn, user, did) is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    try:
        key = f"cs:ratelimit:poll:{user.id}"
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, 60)
        if count > settings.poll_manual_per_minute:
            raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail="Too many manual polls; slow down", headers={"Retry-After": "60"})
    except RedisError as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail="Polling is temporarily unavailable") from exc
    row = await load_device(conn, did)
    reason = await pollable_reason(conn, row)
    if reason:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail=reason)
    if not await conn.fetchval("select exists(select 1 from oid_profiles where name = $1 and status = 'active')", payload.profile):
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No active profile with that name")
    job_id = str(uuid.uuid4())
    queue = JobQueue(redis, settings.job_signing_key)
    try:
        await queue.publish(POLL_STREAM, f"manual:{job_id}", {"device_id": did, "profile": payload.profile, "requested_by": user.id, "kind": "manual"})
    except SigningKeyMissing as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail="Polling is not configured") from exc
    except RedisError as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail="Polling is temporarily unavailable") from exc
    await write_audit(conn, action="poll.requested", actor_user_id=user.id, resource_type="device", resource_id=did, ip=user.client_ip,
                      user_agent=request.headers.get("user-agent"), metadata={"profile": payload.profile, "job": job_id})
    return {"job_id": job_id, "status": "queued", "profile": payload.profile}


@router.get("/workers", response_model=list[schemas.WorkerOut])
async def list_workers(
    _: Annotated[CurrentUser, Depends(require("system.status.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await heartbeat.list_workers(conn)


@router.get("/dead-letters", response_model=list[schemas.DeadLetterOut])
async def list_dead_letters(
    _: Annotated[CurrentUser, Depends(require("system.status.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        "select id, stream, job_id, message_id, fields, reason, deliveries, created_at, resolved_at from dead_letter_jobs order by created_at desc limit $1", limit)
    import json

    return [{**dict(r), "id": str(r["id"]), "fields": r["fields"] if isinstance(r["fields"], dict) else json.loads(r["fields"])} for r in rows]
