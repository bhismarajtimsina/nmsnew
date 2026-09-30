"""The scheduler: decides what is due and queues it. It never talks to a device.

  * **One leader.** Whichever process holds a PostgreSQL advisory lock on a dedicated connection acts; the others stand by.
    If the leader dies its connection closes, the lock is released, and a standby takes over within one tick.
  * **Misfires.** A job that is late by more than its grace period is handled by its policy: `skip` (record it and wait for the
    next slot), `run_once`, or `catch_up` (run each missed slot, up to a cap).
  * **Overlap.** By default a job does not start while its previous run is still going.
  * **Timeouts.** A run that exceeds its limit is recorded as an error, and a run left `running` by a crash is marked abandoned.
  * **Idempotency.** Every publish is keyed by job and scheduled slot, so a repeated run of the same slot never queues twice.
"""
from __future__ import annotations

import asyncio
import logging
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Callable

import asyncpg

from app.core.config import Settings
from app.scheduling import cron
from app.scheduling.jobs import JOB_TYPES, InvalidJob, JobContext, validate_params
from app.workers.queue import JobQueue

logger = logging.getLogger("cybersathy.scheduler")

LEADER_LOCK_KEY = 0x4353_5343_4844  # "CSSCHD"


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


@dataclass
class TickResult:
    leader: bool = False
    ran: int = 0
    skipped: int = 0
    misfired: int = 0
    errors: int = 0


class Scheduler:
    def __init__(self, pool: asyncpg.Pool, queue: JobQueue, cfg: Settings, *, clock: Callable[[], datetime] = utcnow,
                 runtime_cap_seconds: float | None = None) -> None:
        self.pool, self.queue, self.cfg, self.clock = pool, queue, cfg, clock
        self.runtime_cap = runtime_cap_seconds  # a test hook: lowers the limit so a timeout can be shown without waiting minutes
        self._lock_conn: asyncpg.Connection | None = None

    @property
    def is_leader(self) -> bool:
        return self._lock_conn is not None

    async def ensure_leader(self) -> bool:
        if self._lock_conn is not None:
            try:
                await self._lock_conn.fetchval("select 1")
                return True
            except (asyncpg.PostgresError, OSError, asyncpg.InterfaceError):
                self._lock_conn = None  # the session is gone, and with it the lock
        conn = await asyncpg.connect(self.cfg.postgres_dsn)
        if await conn.fetchval("select pg_try_advisory_lock($1)", LEADER_LOCK_KEY):
            self._lock_conn = conn
            logger.info("this scheduler is now the leader")
            return True
        await conn.close()
        return False

    async def release(self) -> None:
        if self._lock_conn is not None:
            try:
                await self._lock_conn.close()
            finally:
                self._lock_conn = None

    def next_run(self, crontab: str, after: datetime) -> datetime:
        return cron.next_after(cron.parse(crontab), after, self.cfg.scheduler_timezone)

    async def tick(self) -> TickResult:
        result = TickResult()
        if not await self.ensure_leader():
            return result
        result.leader = True
        now = self.clock()
        async with self.pool.acquire() as conn:
            await conn.execute(
                "update schedule_runs r set status = 'error', finished_at = now(), error = 'abandoned: the scheduler stopped during this run' "
                "from schedule_jobs j where r.job_id = j.id and r.status = 'running' and r.started_at < now() - make_interval(secs => j.max_runtime_seconds + 30)")
            for row in await conn.fetch("select id, crontab from schedule_jobs where enabled and next_run_at is null"):
                try:
                    await conn.execute("update schedule_jobs set next_run_at = $2 where id = $1", row["id"], self.next_run(row["crontab"], now))
                except cron.CronError:
                    await conn.execute("update schedule_jobs set enabled = false where id = $1", row["id"])
            due = [r["key"] for r in await conn.fetch("select key from schedule_jobs where enabled and next_run_at <= $1 order by next_run_at limit 50", now)]
        for key in due:
            await self._handle(key, now, result)
        return result

    async def _record(self, conn: asyncpg.Connection, job_id, scheduled_for: datetime, status: str, output: str | None = None, error: str | None = None) -> None:
        await conn.execute(
            "insert into schedule_runs (job_id, scheduled_for, status, output, error, finished_at) values ($1, $2, $3, $4, $5, now())",
            job_id, scheduled_for, status, output, (error or None) and error[:500])

    async def _handle(self, key: str, now: datetime, result: TickResult) -> None:
        async with self.pool.acquire() as conn:
            job = await conn.fetchrow("select * from schedule_jobs where key = $1 and enabled and next_run_at <= $2", key, now)
            if job is None:
                return
            scheduled_for: datetime = job["next_run_at"]
            try:
                expr = cron.parse(job["crontab"])
                following = cron.next_after(expr, now, self.cfg.scheduler_timezone)
            except cron.CronError as exc:
                await self._record(conn, job["id"], scheduled_for, "error", error=f"invalid schedule, job disabled: {exc}")
                await conn.execute("update schedule_jobs set enabled = false, updated_at = now() where id = $1", job["id"])
                result.errors += 1
                return

            late = (now - scheduled_for).total_seconds()
            slots = [scheduled_for]
            if late > job["misfire_grace_seconds"]:
                if job["misfire_policy"] == "skip":
                    await self._record(conn, job["id"], scheduled_for, "misfired", output=f"missed by {int(late)} s; waiting for the next slot")
                    await conn.execute("update schedule_jobs set next_run_at = $2 where id = $1", job["id"], following)
                    result.misfired += 1
                    return
                if job["misfire_policy"] == "catch_up":
                    slots, cursor = [scheduled_for], scheduled_for
                    while len(slots) < job["catch_up_max"]:
                        cursor = cron.next_after(expr, cursor, self.cfg.scheduler_timezone)
                        if cursor > now:
                            break
                        slots.append(cursor)
            if job["overlap_policy"] == "forbid" and await conn.fetchval(
                "select exists(select 1 from schedule_runs where job_id = $1 and status = 'running' and started_at > now() - make_interval(secs => $2))",
                job["id"], float(job["max_runtime_seconds"]),
            ):
                await self._record(conn, job["id"], scheduled_for, "skipped", output="the previous run is still going")
                await conn.execute("update schedule_jobs set next_run_at = $2 where id = $1", job["id"], following)
                result.skipped += 1
                return

        for slot in slots:
            await self._run(job, slot, now, result)
        async with self.pool.acquire() as conn:
            await conn.execute("update schedule_jobs set next_run_at = $2, last_run_at = $3 where id = $1", job["id"], following, now)

    async def _run(self, job: asyncpg.Record, slot: datetime, now: datetime, result: TickResult) -> None:
        async with self.pool.acquire() as conn:
            run_id = await conn.fetchval("insert into schedule_runs (job_id, scheduled_for, status) values ($1, $2, 'running') returning id", job["id"], slot)
        status, output, error = "ok", None, None
        try:
            params = validate_params(job["job_type"], _as_dict(job["params"]))
            handler = JOB_TYPES[job["job_type"]][1]
            ctx = JobContext(self.pool, self.queue, slot.astimezone(timezone.utc).strftime("%Y%m%dT%H%MZ"), job["key"], int(now.timestamp() * 1000))
            limit = min(job["max_runtime_seconds"], self.runtime_cap) if self.runtime_cap else job["max_runtime_seconds"]
            output = await asyncio.wait_for(handler(ctx, params), timeout=limit)
        except asyncio.TimeoutError:
            status, error = "error", f"timed out after {job['max_runtime_seconds']} s"
        except InvalidJob as exc:
            status, error = "error", f"invalid parameters: {exc}"
        except Exception as exc:  # noqa: BLE001 - one failing job must never stop the scheduler
            logger.exception("scheduled job %s failed", job["key"])
            status, error = "error", f"{type(exc).__name__}: {exc}"
        async with self.pool.acquire() as conn:
            await conn.execute("update schedule_runs set status = $2, finished_at = now(), output = $3, error = $4 where id = $1", run_id, status, output, (error or None) and error[:500])
        if status == "ok":
            result.ran += 1
        else:
            result.errors += 1


def _as_dict(value) -> dict:
    import json

    return value if isinstance(value, dict) else json.loads(value)
