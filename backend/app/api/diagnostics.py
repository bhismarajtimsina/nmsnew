"""On-demand diagnostics (Plan 38): ICMP ping of an in-scope device, queued for a worker. The probe logic and limits
are in app/diagnostics/ping.py."""
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path, Request, status
from redis.asyncio import Redis
from redis.exceptions import RedisError

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.redis import get_redis
from app.core.security import CurrentUser, require
from app.diagnostics import ping as diag
from app.repositories import devices as device_repo
from app.workers.queue import JobQueue, SigningKeyMissing

router = APIRouter(prefix=settings.api_prefix, tags=["diagnostics"])
PERMISSION = "diagnostics.icmp_ping"
UNAVAILABLE = "Diagnostics are temporarily unavailable"


def _out(request_id: str, record: diag.Record) -> dict[str, Any]:
    return {"request_id": request_id, "device_id": record.device_id, "status": record.status, "result": record.result,
            "error": record.error}


@router.post("/diagnostics/ping", response_model=schemas.DiagnosticOut, status_code=status.HTTP_202_ACCEPTED)
async def request_ping(
    body: schemas.PingRequest,
    request: Request,
    user: Annotated[CurrentUser, Depends(require(PERMISSION))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """Queue a ping of the device's management address. The address is read by the worker from the database; the
    request names a device, never an address."""
    if not settings.diagnostics_enabled:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                            detail="Diagnostics are switched off (DIAGNOSTICS_ENABLED); nothing was sent")
    device = await device_repo.get_device(conn, user, body.device_id)
    if device is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    request_id = diag.new_request_id()
    record = diag.Record(user_id=user.id, device_id=body.device_id, count=body.count)
    try:
        await diag.check_rate(redis, user.id, body.device_id)
        await diag.save(redis, request_id, record)
        await JobQueue(redis, settings.job_signing_key).publish(diag.DIAG_STREAM, f"diag:{request_id}", {"request_id": request_id})
    except diag.RateLimited as exc:
        raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail=str(exc), headers={"Retry-After": "60"}) from exc
    except (SigningKeyMissing, RedisError) as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail=UNAVAILABLE) from exc
    await write_audit(conn, action="diagnostics.ping.requested", actor_user_id=user.id, resource_type="device",
                      resource_id=body.device_id, ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                      metadata={"request_id": request_id, "count": body.count})
    return _out(request_id, record)


@router.get("/diagnostics/{request_id}", response_model=schemas.DiagnosticOut)
async def diagnostic_result(
    request_id: Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")],
    user: Annotated[CurrentUser, Depends(require(PERMISSION))],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """A request's status and result, for the user who asked; 404 for anyone else, and once it has expired."""
    try:
        record = await diag.load(redis, request_id)
    except RedisError as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail=UNAVAILABLE) from exc
    if record is None or record.user_id != user.id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return _out(request_id, record)
