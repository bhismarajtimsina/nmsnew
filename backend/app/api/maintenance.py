from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field

from app.alerting.maintenance import release_suppressed
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import maintenance as repo

router = APIRouter(prefix=f"{settings.api_prefix}/maintenance-windows", tags=["maintenance"])


class WindowCreate(BaseModel):
    device_id: str | None = None
    device_group_id: str | None = None
    starts_at: datetime | None = None  # now, when omitted
    ends_at: datetime
    reason: str = Field(min_length=1, max_length=500)


def _uuid(value: str | None) -> str | None:
    if value is None:
        return None
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


def _aware(value: datetime) -> datetime:
    if value.tzinfo is None:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="timestamps need a time zone")
    return value


@router.get("")
async def list_windows(
    user: Annotated[CurrentUser, Depends(require("events.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    current_only: Annotated[bool, Query()] = True,
    device_id: Annotated[str | None, Query()] = None,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> dict[str, Any]:
    rows = await repo.list_windows(conn, user, current_only=current_only, device_id=_uuid(device_id), limit=limit, offset=offset)
    return {"items": [repo.as_dict(r) for r in rows], "limit": limit, "offset": offset}


@router.post("", status_code=status.HTTP_201_CREATED)
async def create_window(
    payload: WindowCreate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("maintenance.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    starts_at = _aware(payload.starts_at) if payload.starts_at else datetime.now(timezone.utc)
    ends_at = _aware(payload.ends_at)
    try:
        async with conn.transaction():
            window_id = await repo.create_window(
                conn, user, device_id=_uuid(payload.device_id), device_group_id=_uuid(payload.device_group_id),
                starts_at=starts_at, ends_at=ends_at, reason=payload.reason,
            )
            row = repo.as_dict(await repo.get_window(conn, user, window_id))
            await write_audit(conn, action="maintenance_window.created", actor_user_id=user.id, resource_type="maintenance_window",
                              resource_id=window_id, ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                              after={k: str(row[k]) if row[k] is not None else None
                                     for k in ("device_id", "device_group_id", "starts_at", "ends_at", "reason")})
    except repo.NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    except repo.Invalid as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    return row


@router.post("/{window_id}/cancel")
async def cancel_window(
    window_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("maintenance.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    wid = _uuid(window_id)
    try:
        async with conn.transaction():
            if not await repo.cancel_window(conn, user, wid):
                raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Already canceled or already over")
            await write_audit(conn, action="maintenance_window.canceled", actor_user_id=user.id, resource_type="maintenance_window",
                              resource_id=wid, ip=user.client_ip, user_agent=request.headers.get("user-agent"))
    except repo.NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    # Whatever the window was holding back and is still open is announced now, not at the next scheduled release.
    released = await release_suppressed(conn)
    return {"status": "canceled", "released_events": released}
