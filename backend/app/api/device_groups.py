from __future__ import annotations

import uuid
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, Field

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import device_groups as repo

router = APIRouter(prefix=f"{settings.api_prefix}/device-groups", tags=["device-groups"])


class GroupCreate(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    parent_id: str | None = None
    description: str | None = Field(default=None, max_length=2000)


class GroupUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=120)
    parent_id: str | None = None
    description: str | None = Field(default=None, max_length=2000)


def _uuid(value: str | None) -> str | None:
    if value is None:
        return None
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


def _not_found() -> HTTPException:
    return HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")


@router.get("", response_model=list[schemas.DeviceGroupOut])
async def list_groups(
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return [repo.as_dict(r) for r in await repo.list_groups(conn, user)]


@router.get("/{group_id}", response_model=schemas.DeviceGroupOut)
async def get_group(
    group_id: str,
    user: Annotated[CurrentUser, Depends(require("devices.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    row = await repo.get_group(conn, user, _uuid(group_id))
    if row is None:
        raise _not_found()
    return repo.as_dict(row)


@router.post("", status_code=status.HTTP_201_CREATED, response_model=schemas.DeviceGroupOut)
async def create_group(
    payload: GroupCreate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("device_groups.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    try:
        async with conn.transaction():
            group_id = await repo.create_group(conn, user, payload.name, _uuid(payload.parent_id), payload.description)
            row = repo.as_dict(await repo.get_group(conn, user, group_id))
            await write_audit(conn, action="device_group.created", actor_user_id=user.id, resource_type="device_group", resource_id=group_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), after={"name": payload.name, "parent_id": payload.parent_id})
    except repo.NotVisible as exc:
        raise _not_found() from exc
    except repo.Conflict as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail=str(exc)) from exc
    return row


@router.patch("/{group_id}", response_model=schemas.DeviceGroupOut)
async def update_group(
    group_id: str,
    payload: GroupUpdate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("device_groups.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    gid = _uuid(group_id)
    changes = payload.model_dump(exclude_unset=True)
    if "parent_id" in changes:
        changes["parent_id"] = _uuid(changes["parent_id"])
    try:
        async with conn.transaction():
            before = await repo.get_group(conn, user, gid)
            if before is None:
                raise repo.NotVisible("group")
            await repo.update_group(conn, user, gid, changes)
            row = repo.as_dict(await repo.get_group(conn, user, gid))
            await write_audit(conn, action="device_group.updated", actor_user_id=user.id, resource_type="device_group", resource_id=gid,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                              before={k: repo.as_dict(before).get(k) for k in changes}, after={k: row.get(k) for k in changes})
    except repo.NotVisible as exc:
        raise _not_found() from exc
    except repo.Conflict as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail=str(exc)) from exc
    return row


@router.delete("/{group_id}", response_model=schemas.StatusOut)
async def delete_group(
    group_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("device_groups.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    gid = _uuid(group_id)
    try:
        async with conn.transaction():
            before = await repo.get_group(conn, user, gid)
            if before is None:
                raise repo.NotVisible("group")
            await repo.delete_group(conn, user, gid)
            await write_audit(conn, action="device_group.deleted", actor_user_id=user.id, resource_type="device_group", resource_id=gid,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), before={"name": before["name"]})
    except repo.NotVisible as exc:
        raise _not_found() from exc
    except repo.Conflict as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail=str(exc)) from exc
    return {"status": "deleted"}
