"""Dangerous actions (Plan 26): list what the caller may run, dry-run one to get a confirmation, then execute it with
that confirmation. The rules are in app/actions/safety.py; every route here needs the global dangerous-action gate,
and each action's own permission is checked by the service."""
from __future__ import annotations

from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path

from app.actions import safety
from app.api import schemas
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require

router = APIRouter(prefix=settings.api_prefix, tags=["actions"])
ActionKey = Annotated[str, Path(max_length=80, pattern=r"^[a-z_.]+$")]


def _http(exc: safety.ActionError) -> HTTPException:
    return HTTPException(status_code=exc.status, detail=exc.message)


@router.get("/actions", response_model=schemas.ActionList)
async def list_actions(user: Annotated[CurrentUser, Depends(require("dangerous_actions.execute"))]) -> dict[str, Any]:
    return {"items": safety.available_actions(user)}


@router.post("/actions/{action}/prepare", response_model=schemas.ActionPrepared)
async def prepare_action(
    action: ActionKey, body: schemas.ActionPrepareIn,
    user: Annotated[CurrentUser, Depends(require("dangerous_actions.execute"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    """The dry run: every target listed, nothing sent to a device. Returns a confirmation valid for one execute, by this
    user, for exactly these targets and parameters, for two minutes."""
    try:
        async with conn.transaction():
            return await safety.prepare(conn, user, action, body.targets, body.params, ip=user.client_ip)
    except safety.ActionError as exc:
        raise _http(exc) from exc


@router.post("/actions/{action}/execute", response_model=schemas.ActionExecuted)
async def execute_action(
    action: ActionKey, body: schemas.ActionExecuteIn,
    user: Annotated[CurrentUser, Depends(require("dangerous_actions.execute"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    # Deliberately no surrounding transaction: consuming the confirmation must commit on its own, so a rejected or
    # failed request still burns it (see safety._consume).
    try:
        return await safety.execute(conn, user, action, body.token, body.targets, body.params, ip=user.client_ip)
    except safety.ActionError as exc:
        raise _http(exc) from exc
