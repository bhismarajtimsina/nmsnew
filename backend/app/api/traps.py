from __future__ import annotations

import uuid
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel

from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import traps as trap_repo

router = APIRouter(prefix=settings.api_prefix, tags=["traps"])


class Page(BaseModel):
    items: list[dict[str, Any]]
    total: int
    limit: int
    offset: int


def _uuid_or_404(value: str) -> str:
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


@router.get("/trap-history", response_model=Page)
async def list_trap_history(
    user: Annotated[CurrentUser, Depends(require("traps.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
    device_id: Annotated[str | None, Query()] = None,
    known: Annotated[bool | None, Query()] = None,
) -> Page:
    did = _uuid_or_404(device_id) if device_id else None
    rows, total = await trap_repo.list_trap_history(conn, user, limit=limit, offset=offset, device_id=did, known_only=known)
    return Page(items=[trap_repo.as_dict(r) for r in rows], total=total, limit=limit, offset=offset)


@router.get("/trap-profiles")
async def list_trap_profiles(
    _: Annotated[CurrentUser, Depends(require("traps.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    rows = await trap_repo.list_trap_profiles(conn)
    return [{**dict(r), "id": str(r["id"]), "modules": list(r["modules"])} for r in rows]
