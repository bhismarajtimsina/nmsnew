"""Console sessions (Plan 38): ask for a ticket to open a device console through the gateway, and read past sessions'
transcripts. Opening needs `console.open`; reading needs `console.logs.view`; both are scoped by the device."""
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path, Query, status

from app.api import schemas
from app.console import broker
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import console as console_repo

router = APIRouter(prefix=settings.api_prefix, tags=["console"])
GATEWAY_PATH = "/console/ws"
SessionId = Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")]


@router.post("/console/sessions", response_model=schemas.ConsoleTicket, status_code=status.HTTP_201_CREATED)
async def request_console(
    body: schemas.ConsoleRequest,
    user: Annotated[CurrentUser, Depends(require("console.open"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    """A single-use ticket, valid for 30 seconds, that the console gateway redeems at `gateway_path?ticket=...`. The
    ticket is shown once and stored hashed."""
    try:
        out = await broker.request_session(conn, user, body.device_id, auto_auth=body.auto_auth, ip=user.client_ip)
    except broker.ConsoleError as exc:
        raise HTTPException(status_code=exc.status, detail=exc.message) from exc
    return {**out, "gateway_path": GATEWAY_PATH}


@router.get("/console/sessions", response_model=schemas.ConsoleSessionList)
async def list_console_sessions(
    user: Annotated[CurrentUser, Depends(require("console.logs.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    device_id: Annotated[str | None, Query(pattern=r"^[0-9a-fA-F-]{36}$")] = None,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
) -> dict[str, Any]:
    rows = await console_repo.list_sessions(conn, user, device_id=device_id, limit=limit)
    return {"items": [console_repo.as_dict(r) for r in rows]}


@router.get("/console/sessions/{session_id}/history", response_model=schemas.ConsoleHistory)
async def console_history(
    session_id: SessionId,
    user: Annotated[CurrentUser, Depends(require("console.logs.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    after_seq: Annotated[int, Query(ge=0)] = 0,
    limit: Annotated[int, Query(ge=1, le=1000)] = 500,
) -> dict[str, Any]:
    """A session's transcript, in order, a page at a time. Input typed at password prompts was never stored."""
    session = await console_repo.get_session(conn, user, session_id)
    if session is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    chunks = await console_repo.history(conn, session_id, after_seq=after_seq, limit=limit)
    return {"session": console_repo.as_dict(session), "chunks": [dict(c) for c in chunks]}
