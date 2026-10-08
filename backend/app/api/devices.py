from __future__ import annotations

import uuid
from typing import Annotated, Any, Literal

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field, field_validator
from redis.asyncio import Redis

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.netutil import validate_management_ip
from app.core.redis import get_redis
from app.core.security import CurrentUser, require, require_dangerous
from app.realtime.bus import notify_devices_changed
from app.repositories.device_groups import NotVisible
from app.services.discovery import queue_discovery
from app.repositories import devices as device_repo
from app.repositories import interfaces as interface_repo

router = APIRouter(prefix=settings.api_prefix, tags=["devices"])


class Page(BaseModel):
    items: list[dict[str, Any]]
    total: int
    limit: int
    offset: int


def _uuid_or_404(conn_value: str) -> str:
    import uuid

    try:
        return str(uuid.UUID(conn_value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


@router.get("/devices", response_model=schemas.DevicePage)
async def list_devices(
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
    device_type: Annotated[str | None, Query(max_length=40)] = None,
    device_status: Annotated[str | None, Query(alias="status", max_length=40)] = None,
    search: Annotated[str | None, Query(max_length=120)] = None,
) -> Page:
    rows, total = await device_repo.list_devices(conn, user, limit=limit, offset=offset, device_type=device_type, status=device_status, search=search)
    return Page(items=[device_repo.as_dict(r) for r in rows], total=total, limit=limit, offset=offset)


@router.get("/devices/overview", response_model=schemas.DeviceOverviewPage)
async def device_overview(
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=1000)] = 500,
    offset: Annotated[int, Query(ge=0)] = 0,
    sort: Literal["name", "ip"] = "name",
    search: Annotated[str | None, Query(max_length=120)] = None,
) -> dict[str, Any]:
    """The device list page: groups, models, last ping and interface counts in one call, read from the database only."""
    items, total = await device_repo.device_overview(conn, user, limit=limit, offset=offset, sort=sort, search=search)
    return {"items": items, "total": total, "limit": limit, "offset": offset}


@router.get("/devices/{device_id}", response_model=schemas.DeviceOut)
async def get_device(
    device_id: str,
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    row = await device_repo.get_device(conn, user, _uuid_or_404(device_id))
    if row is None:
        # Same answer for "does not exist" and "not yours": callers learn nothing about other scopes.
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return device_repo.as_dict(row)


@router.get("/devices/{device_id}/overview", response_model=schemas.DeviceOverviewOut)
async def device_overview_one(
    device_id: str,
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    """One device with its group, model, last ping and interface counts: the header of its detail page."""
    item = await device_repo.device_overview_one(conn, user, str(_uuid_or_404(device_id)))
    if item is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return item


@router.get("/devices/{device_id}/interfaces", response_model=schemas.InterfacePage)
async def list_device_interfaces(
    device_id: str,
    user: Annotated[CurrentUser, Depends(require("interfaces.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> Page:
    rows, total = await interface_repo.list_interfaces(conn, user, limit=limit, offset=offset, device_id=_uuid_or_404(device_id))
    return Page(items=[interface_repo.as_dict(r) for r in rows], total=total, limit=limit, offset=offset)


@router.get("/interfaces", response_model=schemas.InterfacePage)
async def list_interfaces(
    user: Annotated[CurrentUser, Depends(require("interfaces.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
    device_id: Annotated[str | None, Query()] = None,
    oper_status: Annotated[str | None, Query(max_length=40)] = None,
    favorite: Annotated[bool | None, Query()] = None,
    tag: Annotated[str | None, Query(max_length=60)] = None,
) -> Page:
    device = _uuid_or_404(device_id) if device_id else None
    rows, total = await interface_repo.list_interfaces(
        conn, user, limit=limit, offset=offset, device_id=device, oper_status=oper_status, favorite=favorite, tag=tag
    )
    return Page(items=[interface_repo.as_dict(r) for r in rows], total=total, limit=limit, offset=offset)


@router.get("/interfaces/tags", response_model=list[str])
async def list_known_tags(
    user: Annotated[CurrentUser, Depends(require("interfaces.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    q: Annotated[str | None, Query(max_length=60)] = None,
) -> list[str]:
    return await interface_repo.list_known_tags(conn, user, query=q)


@router.get("/interfaces/{interface_id}", response_model=schemas.InterfaceOut)
async def get_interface(
    interface_id: str,
    user: Annotated[CurrentUser, Depends(require("interfaces.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    row = await interface_repo.get_interface(conn, user, _uuid_or_404(interface_id))
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return interface_repo.as_dict(row)


@router.get("/interfaces/{interface_id}/history", response_model=schemas.InterfaceHistoryPage)
async def get_interface_status_history(
    interface_id: str,
    user: Annotated[CurrentUser, Depends(require("interfaces.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> Page:
    try:
        rows, total = await interface_repo.list_status_history(conn, user, _uuid_or_404(interface_id), limit=limit, offset=offset)
    except NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    items = [{"id": str(r["id"]), "changed_at": r["changed_at"], "admin_status": r["admin_status"], "oper_status": r["oper_status"]} for r in rows]
    return Page(items=items, total=total, limit=limit, offset=offset)


@router.get("/interfaces/{interface_id}/marks", response_model=schemas.InterfaceMarks)
async def get_interface_marks(
    interface_id: str,
    user: Annotated[CurrentUser, Depends(require("interfaces.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    try:
        return await interface_repo.get_marks(conn, user, _uuid_or_404(interface_id))
    except NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


class FavoriteSet(BaseModel):
    favorite: bool


@router.put("/interfaces/{interface_id}/favorite", response_model=schemas.FavoriteOut)
async def set_interface_favorite(
    interface_id: str,
    payload: FavoriteSet,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("interfaces.mark"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, bool]:
    iid = _uuid_or_404(interface_id)
    try:
        await interface_repo.set_favorite(conn, user, iid, payload.favorite)
    except NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    await write_audit(conn, action="interface.favorite_set", actor_user_id=user.id, resource_type="interface", resource_id=iid,
                      ip=user.client_ip, user_agent=request.headers.get("user-agent"), after={"favorite": payload.favorite})
    return {"favorite": payload.favorite}


class TagsSet(BaseModel):
    tags: list[str] = Field(default_factory=list, max_length=50)


@router.put("/interfaces/{interface_id}/tags", response_model=schemas.TagsOut)
async def set_interface_tags(
    interface_id: str,
    payload: TagsSet,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("interfaces.mark"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, list[str]]:
    iid = _uuid_or_404(interface_id)
    try:
        tags = await interface_repo.set_tags(conn, user, iid, payload.tags)
    except NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    await write_audit(conn, action="interface.tags_set", actor_user_id=user.id, resource_type="interface", resource_id=iid,
                      ip=user.client_ip, user_agent=request.headers.get("user-agent"), after={"tags": tags})
    return {"tags": tags}


class ProtectionSet(BaseModel):
    protected: bool


@router.put("/interfaces/{interface_id}/protection", response_model=schemas.ProtectionOut)
async def set_interface_protection(
    interface_id: str,
    payload: ProtectionSet,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("interfaces.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, bool]:
    """Protect an interface (typically the uplink a switch is managed through) so no device action can shut it down."""
    iid = _uuid_or_404(interface_id)
    async with conn.transaction():
        previous = await interface_repo.set_protected(conn, user, iid, payload.protected)
        if previous is None:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
        await write_audit(conn, action="interface.protection_set", actor_user_id=user.id, resource_type="interface", resource_id=iid,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                          before={"protected": previous}, after={"protected": payload.protected})
    return {"protected": payload.protected}


DeviceType = Literal["switch", "olt", "router", "sensor", "other"]


class DeviceCreate(BaseModel):
    name: str = Field(min_length=1, max_length=160)
    hostname: str | None = Field(default=None, max_length=255)
    management_ip: str
    device_type: DeviceType
    vendor_slug: str | None = Field(default=None, max_length=60)
    family_slug: str | None = Field(default=None, max_length=80)
    group_id: str | None = None
    access_profile_id: str | None = None

    @field_validator("management_ip")
    @classmethod
    def usable_address(cls, value: str) -> str:
        try:
            return validate_management_ip(value, settings.management_networks)
        except ValueError as exc:
            raise ValueError(str(exc)) from exc


class DeviceUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=160)
    hostname: str | None = Field(default=None, max_length=255)
    management_ip: str | None = None
    device_type: DeviceType | None = None
    vendor_slug: str | None = Field(default=None, max_length=60)
    family_slug: str | None = Field(default=None, max_length=80)
    group_id: str | None = None
    access_profile_id: str | None = None
    polling_enabled: bool | None = None

    @field_validator("management_ip")
    @classmethod
    def usable_address(cls, value: str | None) -> str | None:
        return None if value is None else validate_management_ip(value, settings.management_networks)


def _opt_uuid(value: str | None) -> str | None:
    if value is None:
        return None
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Invalid id") from exc


def _rejected(exc: device_repo.Rejected) -> HTTPException:
    return HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail={"reasons": exc.reasons})


def _not_found() -> HTTPException:
    return HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")


@router.post("/devices", status_code=status.HTTP_201_CREATED, response_model=schemas.DeviceCreated)
async def create_device(
    payload: DeviceCreate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("devices.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """Add a device. It starts with polling off; only a safe discovery is queued."""
    try:
        async with conn.transaction():
            device_id = await device_repo.create_device(
                conn, user, name=payload.name, hostname=payload.hostname, management_ip=payload.management_ip,
                device_type=payload.device_type, vendor_slug=payload.vendor_slug, family_slug=payload.family_slug,
                group_id=_opt_uuid(payload.group_id), access_profile_id=_opt_uuid(payload.access_profile_id),
            )
            discovery = await queue_discovery(conn, None, device_id, user.id)  # published after commit, below
            device = device_repo.as_dict(await device_repo.get_device(conn, user, device_id))
            await write_audit(conn, action="device.created", actor_user_id=user.id, resource_type="device", resource_id=device_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), after=device,
                              metadata={"discovery": discovery})
    except NotVisible as exc:
        raise _not_found() from exc
    except device_repo.Rejected as exc:
        raise _rejected(exc) from exc
    if discovery["status"] == "queued":
        await _publish(redis, discovery["job_id"], conn)
    await notify_devices_changed(redis, "created")
    return {"device": device, "discovery": discovery}


async def _publish(redis: Redis, job_id: str, conn: asyncpg.Connection) -> None:
    from app.services.discovery import publish_job

    await publish_job(conn, redis, job_id)


@router.patch("/devices/{device_id}", response_model=schemas.DeviceOut)
async def update_device(
    device_id: str,
    payload: DeviceUpdate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("devices.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    did = _uuid_or_404(device_id)
    changes = payload.model_dump(exclude_unset=True)
    for key in ("group_id", "access_profile_id"):
        if key in changes:
            changes[key] = _opt_uuid(changes[key])
    try:
        async with conn.transaction():
            before = await device_repo.get_device(conn, user, did)
            if before is None:
                raise NotVisible("device")
            await device_repo.update_device(conn, user, did, changes)
            after = device_repo.as_dict(await device_repo.get_device(conn, user, did))
            before_view = device_repo.as_dict(before)
            await write_audit(conn, action="device.updated", actor_user_id=user.id, resource_type="device", resource_id=did,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                              before={k: before_view.get(k) for k in before_view if after.get(k) != before_view.get(k)},
                              after={k: after.get(k) for k in after if after.get(k) != before_view.get(k)})
    except NotVisible as exc:
        raise _not_found() from exc
    except device_repo.Rejected as exc:
        raise _rejected(exc) from exc
    await notify_devices_changed(redis, "updated")
    return after


@router.delete("/devices/{device_id}", response_model=schemas.StatusOut)
async def delete_device(
    device_id: str,
    request: Request,
    confirm: Annotated[str, Query(description="The device's exact name, typed to confirm")],
    user: Annotated[CurrentUser, Depends(require_dangerous("devices.delete"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, str]:
    did = _uuid_or_404(device_id)
    try:
        async with conn.transaction():
            before = await device_repo.get_device(conn, user, did)
            if before is None:
                raise NotVisible("device")
            if confirm != before["name"]:
                raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Confirmation does not match the device name")
            await device_repo.delete_device(conn, user, did)
            await write_audit(conn, action="device.deleted", actor_user_id=user.id, resource_type="device", resource_id=did,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), before=device_repo.as_dict(before))
    except NotVisible as exc:
        raise _not_found() from exc
    await notify_devices_changed(redis, "deleted")
    return {"status": "deleted"}


@router.get("/devices/{device_id}/discovery", response_model=list[schemas.DiscoveryJobOut])
async def device_discovery(
    device_id: str,
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    jobs = await device_repo.recent_discovery(conn, user, _uuid_or_404(device_id))
    if jobs is None:
        raise _not_found()
    return jobs


@router.post("/devices/{device_id}/discovery", status_code=status.HTTP_202_ACCEPTED, response_model=schemas.DiscoveryQueued)
async def request_discovery(
    device_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("devices.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """Ask for another safe discovery. One at a time, and not more often than every ten minutes."""
    did = _uuid_or_404(device_id)
    if await device_repo.get_device(conn, user, did) is None:
        raise _not_found()
    if await conn.fetchval(
        "select exists(select 1 from discovery_jobs where device_id = $1::uuid and status in ('queued','running') "
        "or (device_id = $1::uuid and requested_at > now() - interval '10 minutes' and status <> 'skipped'))", did,
    ):
        raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail="A discovery was requested recently for this device",
                            headers={"Retry-After": "600"})
    async with conn.transaction():
        discovery = await queue_discovery(conn, None, did, user.id)
        await write_audit(conn, action="device.discovery_requested", actor_user_id=user.id, resource_type="device", resource_id=did,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), metadata=discovery)
    if discovery["status"] == "queued":
        await _publish(redis, discovery["job_id"], conn)
    return discovery
