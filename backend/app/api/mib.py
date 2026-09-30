from __future__ import annotations

import uuid
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, Field

from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import mib as repo

router = APIRouter(prefix=f"{settings.api_prefix}/mib", tags=["mib"])


class CheckRequest(BaseModel):
    name: str = Field(min_length=1, max_length=160)
    numeric_oid: str = Field(min_length=1, max_length=255, pattern=r"^[0-9]+(\.[0-9]+)+$")


@router.get("/files")
async def list_files(
    _: Annotated[CurrentUser, Depends(require("mib.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await repo.list_files(conn)


@router.get("/objects")
async def search_objects(
    _: Annotated[CurrentUser, Depends(require("mib.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    query: Annotated[str | None, Query(max_length=160)] = None,
    file_id: Annotated[str | None, Query()] = None,
    limit: Annotated[int, Query(ge=1, le=500)] = 100,
) -> list[dict[str, Any]]:
    fid = None
    if file_id is not None:
        try:
            fid = str(uuid.UUID(file_id))
        except ValueError as exc:
            raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Invalid file_id") from exc
    return await repo.search_objects(conn, query=query, file_id=fid, limit=limit)


@router.post("/check")
async def check_definition(
    payload: CheckRequest,
    _: Annotated[CurrentUser, Depends(require("mib.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    """Does a declared logical name and numeric OID agree with what an imported MIB assigns that name? Used to check
    an authored OID definition (Plan 6) against the vendor's own MIB text, offline."""
    return await repo.check_definition(conn, payload.name, payload.numeric_oid)
