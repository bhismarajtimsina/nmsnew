"""Dangerous actions (Plan 26): list what the caller may run, dry-run one to get a confirmation, then execute it with
that confirmation. The rules are in app/actions/safety.py; every route here needs the global dangerous-action gate,
and each action's own permission is checked by the service."""
from __future__ import annotations

from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path
from redis.asyncio import Redis
from redis.exceptions import RedisError

from app.actions import safety
from app.api import schemas
from app.core.config import settings
from app.core.database import get_conn
from app.core.redis import get_redis
from app.core.security import CurrentUser, require
from app.workers.queue import JobQueue, SigningKeyMissing
from app.workers.runner import ACTION_STREAM

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
    redis: Annotated[Redis, Depends(get_redis)],
) -> dict[str, Any]:
    """Consume the confirmation, queue one result per target, and hand the work to a worker. Returns at once with the
    results `queued`; GET /actions/results/{confirmation_id} shows them progress."""
    # Deliberately no surrounding transaction: consuming the confirmation must commit on its own, so a rejected or
    # failed request still burns it (see safety._consume).
    try:
        queued = await safety.execute(conn, user, action, body.token, body.targets, body.params, ip=user.client_ip)
    except safety.ActionError as exc:
        raise _http(exc) from exc
    confirmation_id = queued["confirmation_id"]
    try:
        await JobQueue(redis, settings.job_signing_key).publish(ACTION_STREAM, f"action:{confirmation_id}", {"confirmation_id": confirmation_id})
    except (SigningKeyMissing, RedisError) as exc:
        await safety.fail_queued(conn, confirmation_id, "the action could not be queued")
        raise HTTPException(status_code=503, detail="Device actions cannot be queued right now; nothing was sent") from exc
    return queued


@router.get("/actions/results/{confirmation_id}", response_model=schemas.ActionExecuted)
async def action_results(
    confirmation_id: Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")],
    user: Annotated[CurrentUser, Depends(require("dangerous_actions.execute"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    """The results of an action this user requested; 404 for anyone else's."""
    found = await safety.results(conn, user, confirmation_id)
    if found is None:
        raise HTTPException(status_code=404, detail="Not found")
    return found
