"""On-demand MAC lookup on one switch (Plan 13): queued for a worker, one bounded GET. The rules are in
app/diagnostics/mac.py and app/services/mac_lookup.py."""
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
from app.diagnostics import mac as lookup
from app.diagnostics.ping import RateLimited, check_rate
from app.repositories import devices as device_repo
from app.services.mac_lookup import fdb_port_oids
from app.workers.queue import JobQueue, SigningKeyMissing

router = APIRouter(prefix=settings.api_prefix, tags=["search"])
UNAVAILABLE = "MAC lookup is temporarily unavailable"


def _out(request_id: str, record: lookup.Record) -> dict[str, Any]:
    return {"request_id": request_id, "device_id": record.device_id, "mac": record.mac, "vlans": record.vlans,
            "status": record.status, "result": record.result, "error": record.error}


@router.post("/search/mac", response_model=schemas.MacLookupOut, status_code=status.HTTP_202_ACCEPTED)
async def request_mac_lookup(
    body: schemas.MacLookupRequest,
    request: Request,
    user: Annotated[CurrentUser, Depends(require(lookup.PERMISSION))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """Queue a lookup of where one MAC is learned on one switch, in the VLANs named (at most 32). A miss means "not
    learned in these VLANs", not "not on this switch"."""
    if settings.snmp_transport == "disabled":
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                            detail="The SNMP transport is disabled in this build (D-17); nothing was sent")
    try:
        mac = lookup.normalise(body.mac)
        fdb_port_oids(mac, body.vlans)  # the same checks the worker applies: VLAN range and count
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    if await device_repo.get_device(conn, user, body.device_id) is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    request_id = lookup.new_request_id()
    record = lookup.Record(user_id=user.id, device_id=body.device_id, mac=mac, vlans=sorted(set(body.vlans)))
    try:
        await check_rate(redis, user.id, body.device_id, kind="maclookup")
        await lookup.save(redis, request_id, record)
        await JobQueue(redis, settings.job_signing_key).publish(lookup.MAC_STREAM, f"mac:{request_id}", {"request_id": request_id})
    except RateLimited as exc:
        raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail=str(exc), headers={"Retry-After": "60"}) from exc
    except (SigningKeyMissing, RedisError) as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail=UNAVAILABLE) from exc
    await write_audit(conn, action="search.mac.requested", actor_user_id=user.id, resource_type="device",
                      resource_id=body.device_id, ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                      metadata={"request_id": request_id, "mac": mac, "vlans": record.vlans})
    return _out(request_id, record)


@router.get("/search/mac/{request_id}", response_model=schemas.MacLookupOut)
async def mac_lookup_result(
    request_id: Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")],
    user: Annotated[CurrentUser, Depends(require(lookup.PERMISSION))],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """A lookup's status and result, for the user who asked; 404 for anyone else, and once it has expired."""
    try:
        record = await lookup.load(redis, request_id)
    except RedisError as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail=UNAVAILABLE) from exc
    if record is None or record.user_id != user.id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return _out(request_id, record)
