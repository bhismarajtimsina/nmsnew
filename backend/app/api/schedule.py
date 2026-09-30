from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Annotated, Any, Literal

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field

from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.scheduling import cron
from app.scheduling.jobs import InvalidJob, validate_params

router = APIRouter(prefix=f"{settings.api_prefix}/system/schedule", tags=["schedule"])

COLUMNS = ("key, job_type, params, crontab, enabled, editable, misfire_policy, misfire_grace_seconds, catch_up_max, overlap_policy, "
           "max_runtime_seconds, description, last_run_at, next_run_at")


class JobUpdate(BaseModel):
    crontab: str | None = Field(default=None, min_length=1, max_length=120)
    enabled: bool | None = None
    params: dict[str, Any] | None = None
    misfire_policy: Literal["skip", "run_once", "catch_up"] | None = None
    misfire_grace_seconds: int | None = Field(default=None, ge=30, le=86400)
    catch_up_max: int | None = Field(default=None, ge=1, le=10)
    overlap_policy: Literal["forbid", "allow"] | None = None
    max_runtime_seconds: int | None = Field(default=None, ge=10, le=3600)


def _view(row: asyncpg.Record) -> dict[str, Any]:
    data = dict(row)
    data["params"] = data["params"] if isinstance(data["params"], dict) else json.loads(data["params"])
    try:
        data["upcoming"] = [d.isoformat() for d in cron.upcoming(cron.parse(data["crontab"]), datetime.now(timezone.utc), 3, settings.scheduler_timezone)]
    except cron.CronError:
        data["upcoming"] = []
    data["timezone"] = settings.scheduler_timezone
    return data


async def _get(conn: asyncpg.Connection, key: str) -> asyncpg.Record:
    row = await conn.fetchrow(f"select {COLUMNS} from schedule_jobs where key = $1", key)
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No such scheduled job")
    return row


@router.get("")
async def list_jobs(
    _: Annotated[CurrentUser, Depends(require("system.schedule.reports.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return [_view(r) for r in await conn.fetch(f"select {COLUMNS} from schedule_jobs order by key")]


@router.get("/{key}/runs")
async def job_runs(
    key: str,
    _: Annotated[CurrentUser, Depends(require("system.schedule.reports.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
) -> list[dict[str, Any]]:
    job = await _get(conn, key)
    rows = await conn.fetch(
        "select r.id, r.scheduled_for, r.started_at, r.finished_at, r.status, r.output, r.error from schedule_runs r "
        "join schedule_jobs j on j.id = r.job_id where j.key = $1 order by r.started_at desc limit $2", job["key"], limit)
    return [{**dict(r), "id": str(r["id"])} for r in rows]


@router.patch("/{key}")
async def update_job(
    key: str,
    payload: JobUpdate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("system.schedule.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    before = await _get(conn, key)
    if not before["editable"]:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="This job is fixed and cannot be edited")
    changes = payload.model_dump(exclude_unset=True, exclude_none=True)
    crontab = changes.get("crontab", before["crontab"])
    try:
        cron.parse(crontab)
        params = validate_params(before["job_type"], changes["params"] if "params" in changes else _view(before)["params"])
    except cron.CronError as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=f"crontab: {exc}") from exc
    except InvalidJob as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    enabling = changes.get("enabled") is True and not before["enabled"]
    if before["job_type"] == "poll_group" and (enabling or (before["enabled"] and "params" in changes)):
        profile = params.profile  # type: ignore[attr-defined]
        if not await conn.fetchval("select exists(select 1 from oid_profiles where name = $1 and status = 'active')", profile):
            raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail=f"There is no active profile named {profile!r} to poll with")
    async with conn.transaction():
        for column in ("misfire_policy", "misfire_grace_seconds", "catch_up_max", "overlap_policy", "max_runtime_seconds", "enabled", "crontab"):
            if column in changes:
                await conn.execute(f"update schedule_jobs set {column} = $2, updated_at = now() where key = $1", key, changes[column])
        if "params" in changes:
            await conn.execute("update schedule_jobs set params = $2::jsonb, updated_at = now() where key = $1", key, json.dumps(params.model_dump(exclude_none=True)))
        if "crontab" in changes or enabling:
            await conn.execute("update schedule_jobs set next_run_at = $2 where key = $1", key, cron.next_after(cron.parse(crontab), datetime.now(timezone.utc), settings.scheduler_timezone))
        after = await _get(conn, key)
        await write_audit(conn, action="schedule.updated", actor_user_id=user.id, resource_type="schedule_job", ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"),
                          before={k: _view(before).get(k) for k in changes}, after={k: _view(after).get(k) for k in changes}, metadata={"key": key})
    return _view(after)


@router.post("/{key}/run", status_code=status.HTTP_202_ACCEPTED)
async def run_now(
    key: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("system.schedule.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    job = await _get(conn, key)
    if not job["enabled"]:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Enable the job first")
    if job["last_run_at"] and (datetime.now(timezone.utc) - job["last_run_at"]).total_seconds() < 60:
        raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail="This job ran less than a minute ago", headers={"Retry-After": "60"})
    async with conn.transaction():
        await conn.execute("update schedule_jobs set next_run_at = now() where key = $1", key)
        await write_audit(conn, action="schedule.run_requested", actor_user_id=user.id, resource_type="schedule_job", ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"), metadata={"key": key})
    return {"status": "the scheduler will run it on its next tick"}
