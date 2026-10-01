"""The kinds of scheduled job, their parameters, and what each does.

A job's `job_type` is one of a fixed list and its `params` are validated against that type's model, with unknown fields
refused. There is no field that holds a command line, so a schedule can never run an arbitrary program.
"""
from __future__ import annotations

import hashlib
import time
from dataclasses import dataclass
from typing import Annotated, Any, Awaitable, Callable, Literal

import asyncpg
from pydantic import BaseModel, ConfigDict, Field, ValidationError, model_validator

from app.workers.queue import JobQueue

POLL_STREAM = "polling.jobs"


class PollGroupParams(BaseModel):
    """Queue a poll of every device that may be polled and matches the filters."""

    model_config = ConfigDict(extra="forbid")
    profile: Annotated[str, Field(min_length=1, max_length=120, pattern=r"^[a-z0-9_.-]+$")]
    device_type: Literal["switch", "olt", "router", "sensor", "other"] | None = None
    vendor_slug: Annotated[str | None, Field(max_length=60)] = None
    family_slug: Annotated[str | None, Field(max_length=80)] = None
    jitter_seconds: Annotated[int, Field(ge=0, le=300)] = 20
    max_devices: Annotated[int, Field(ge=1, le=20000)] = 5000


# Deleting history is limited by a floor per target, so a schedule cannot be edited to wipe recent records.
RETENTION_FLOOR_DAYS = {"login_attempts": 30, "schedule_runs": 7, "discovery_jobs": 14, "dead_letter_jobs": 30}


class RetentionParams(BaseModel):
    model_config = ConfigDict(extra="forbid")
    target: Literal["login_attempts", "schedule_runs", "discovery_jobs", "dead_letter_jobs"]
    days: Annotated[int, Field(ge=1, le=3650)]

    @model_validator(mode="after")
    def respect_the_floor(self) -> "RetentionParams":
        floor = RETENTION_FLOOR_DAYS[self.target]
        if self.days < floor:
            raise ValueError(f"{self.target} must be kept for at least {floor} days")
        return self


class CleanupSessionsParams(BaseModel):
    model_config = ConfigDict(extra="forbid")
    older_than_days: Annotated[int, Field(ge=1, le=365)] = 7


class SyncActiveAlertsParams(BaseModel):
    """Close events whose alert Alertmanager no longer reports as active (app/alerting/sync.py)."""
    model_config = ConfigDict(extra="forbid")
    dry_run: bool = False
    timeout_seconds: Annotated[int, Field(ge=1, le=60)] = 15


@dataclass
class JobContext:
    pool: asyncpg.Pool
    queue: JobQueue
    slot: str  # the scheduled minute, identical for every attempt at that slot: it is what makes publishing idempotent
    job_key: str
    now_ms: int


Handler = Callable[[JobContext, BaseModel], Awaitable[str]]

_RETENTION_SQL = {
    "login_attempts": "delete from login_attempts where occurred_at < now() - make_interval(days => $1)",
    "schedule_runs": "delete from schedule_runs where started_at < now() - make_interval(days => $1) and status <> 'running'",
    "discovery_jobs": "delete from discovery_jobs where status in ('succeeded','failed','skipped') and finished_at < now() - make_interval(days => $1)",
    "dead_letter_jobs": "delete from dead_letter_jobs where resolved_at is not null and resolved_at < now() - make_interval(days => $1)",
}


async def run_poll_group(ctx: JobContext, params: PollGroupParams) -> str:
    async with ctx.pool.acquire() as conn:
        if not await conn.fetchval("select exists(select 1 from oid_profiles where name = $1 and status = 'active')", params.profile):
            raise RuntimeError(f"no active profile named {params.profile!r}")
        rows = await conn.fetch(
            """
            select d.id from devices d
            join vendor_model_families f on f.id = d.family_id
            join vendors v on v.id = f.vendor_id
            left join device_poll_state s on s.device_id = d.id
            where d.polling_enabled and d.polling_owner = 'cybersathy' and d.access_profile_id is not null
              and v.polling_enabled and f.polling_enabled
              and ($1::text is null or d.device_type = $1) and ($2::text is null or v.slug = $2) and ($3::text is null or f.slug = $3)
              and (s.breaker_open_until is null or s.breaker_open_until <= now())
            order by d.id limit $4
            """,
            params.device_type, params.vendor_slug, params.family_slug, params.max_devices + 1,
        )
        held_back = await conn.fetchval(
            """
            select count(*) from devices d join vendor_model_families f on f.id = d.family_id join vendors v on v.id = f.vendor_id
            join device_poll_state s on s.device_id = d.id
            where d.polling_enabled and d.polling_owner = 'cybersathy' and s.breaker_open_until > now()
            """
        )
    truncated = len(rows) > params.max_devices
    queued = 0
    for row in rows[: params.max_devices]:
        device_id = str(row["id"])
        # Each device gets a fixed offset inside the window (from its id), so its poll lands at the same moment every cycle.
        offset = int(hashlib.sha256(device_id.encode()).hexdigest(), 16) % (params.jitter_seconds * 1000 + 1) if params.jitter_seconds else 0
        published = await ctx.queue.publish_at(
            POLL_STREAM, f"sched:{ctx.job_key}:{ctx.slot}:{device_id}",
            {"device_id": device_id, "profile": params.profile, "kind": "scheduled", "schedule": ctx.job_key}, ctx.now_ms + offset)
        queued += published
    note = f"queued {queued} poll(s)"
    if held_back:
        note += f", {held_back} device(s) held back by an open circuit breaker"
    if truncated:
        note += f"; stopped at max_devices={params.max_devices}"
    return note


async def run_retention(ctx: JobContext, params: RetentionParams) -> str:
    async with ctx.pool.acquire() as conn:
        status = await conn.execute(_RETENTION_SQL[params.target], params.days)
    return f"{params.target}: {status.lower()} (older than {params.days} days)"


async def run_cleanup_sessions(ctx: JobContext, params: CleanupSessionsParams) -> str:
    async with ctx.pool.acquire() as conn:
        status = await conn.execute(
            "delete from user_sessions where expires_at < now() - make_interval(days => $1) or revoked_at < now() - make_interval(days => $1)",
            params.older_than_days)
    return f"user_sessions: {status.lower()}"


async def run_sync_active_alerts(ctx: JobContext, params: SyncActiveAlertsParams) -> str:
    from app.alerting.sync import HttpAlertmanager, sync_active_alerts
    from app.core.config import settings

    client = HttpAlertmanager(settings.alertmanager_url, params.timeout_seconds)
    async with ctx.pool.acquire() as conn:
        summary = await sync_active_alerts(
            conn, client, dry_run=params.dry_run, flap_window_seconds=settings.event_flap_window_seconds
        )
    if summary.skipped_reason:
        return f"skipped: {summary.skipped_reason}"
    verb = "would resolve" if params.dry_run else "resolved"
    return f"{summary.active} active alert(s); {verb} {len(summary.resolved_ids)} orphaned event(s)"


JOB_TYPES: dict[str, tuple[type[BaseModel], Handler]] = {
    "poll_group": (PollGroupParams, run_poll_group),  # type: ignore[dict-item]
    "retention": (RetentionParams, run_retention),  # type: ignore[dict-item]
    "cleanup_sessions": (CleanupSessionsParams, run_cleanup_sessions),  # type: ignore[dict-item]
    "sync_active_alerts": (SyncActiveAlertsParams, run_sync_active_alerts),  # type: ignore[dict-item]
}


class InvalidJob(ValueError):
    pass


def validate_params(job_type: str, params: dict[str, Any]) -> BaseModel:
    if job_type not in JOB_TYPES:
        raise InvalidJob(f"unknown job type {job_type!r}")
    try:
        return JOB_TYPES[job_type][0].model_validate(params)
    except ValidationError as exc:
        raise InvalidJob("; ".join(f"{'.'.join(map(str, e['loc'])) or 'params'}: {e['msg']}" for e in exc.errors())) from exc


def now_ms() -> int:
    return int(time.time() * 1000)
