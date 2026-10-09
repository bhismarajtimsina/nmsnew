"""The worker loop: read jobs, run them with bounded concurrency, reclaim stuck ones, beat, dispatch."""
from __future__ import annotations

import asyncio
import logging
import socket
from typing import Any, Awaitable, Callable

import asyncpg

from app.polling.engine import Context, Sink
from app.polling.transport import DisabledTransport
from app.workers import handlers, heartbeat
from app.scheduling.scheduler import Scheduler
from app.diagnostics.ping import DIAG_STREAM, DisabledProber, Prober
from app.workers.queue import JobQueue, Message, Permanent, RetryLater, Skipped, verify

logger = logging.getLogger("cybersathy.worker")

DISCOVERY_STREAM = "discovery.jobs"
POLL_STREAM = "polling.jobs"
ACTION_STREAM = "actions.jobs"
KINDS = {"discovery", "poller", "dispatcher", "scheduler", "actions", "diagnostics"}


class Worker:
    def __init__(self, ctx: Context, kinds: set[str], *, worker_id: str | None = None, sink: Sink | None = None,
                 prober: Prober | None = None) -> None:
        unknown = kinds - KINDS
        if unknown:
            raise ValueError(f"unknown worker kind(s): {', '.join(sorted(unknown))}")
        self.ctx, self.kinds, self.sink = ctx, kinds, sink
        self.prober: Prober = prober or DisabledProber()
        self.worker_id = worker_id or f"{socket.gethostname()}-{'+'.join(sorted(kinds))}"
        self.queue = JobQueue(ctx.redis, ctx.cfg.job_signing_key, max_deliveries=ctx.cfg.worker_max_deliveries, retry_base_ms=ctx.cfg.worker_retry_base_ms)
        self.scheduler = Scheduler(ctx.pool, self.queue, ctx.cfg) if "scheduler" in kinds else None
        self.stop = asyncio.Event()
        self._sem = asyncio.Semaphore(ctx.cfg.worker_concurrency)
        self.processed = {"done": 0, "skipped": 0, "dead": 0, "retry": 0}

    @property
    def transport_enabled(self) -> bool:
        return not isinstance(self.ctx.transport, DisabledTransport)

    @property
    def diagnostics_enabled(self) -> bool:
        return not isinstance(self.prober, DisabledProber)

    def streams(self) -> list[tuple[str, Callable[[Message], Awaitable[None]]]]:
        found: list[tuple[str, Callable[[Message], Awaitable[None]]]] = []
        # Diagnostics use ICMP, not SNMP, so they have their own switch; the same rule holds: never consume a job that
        # could only fail.
        if "diagnostics" in self.kinds and self.diagnostics_enabled:
            found.append((DIAG_STREAM, lambda m: handlers.handle_diagnostic(self.ctx, m, self.prober)))
        if not self.transport_enabled:
            return found  # never consume a job we could only fail: nothing may leave the process without a transport
        if "discovery" in self.kinds:
            found.append((DISCOVERY_STREAM, lambda m: handlers.handle_discovery(self.ctx, m)))
        if "poller" in self.kinds:
            found.append((POLL_STREAM, lambda m: handlers.handle_poll(self.ctx, m, self.sink)))
        if "actions" in self.kinds:
            found.append((ACTION_STREAM, lambda m: handlers.handle_action(self.ctx, m)))
        return found

    def status(self) -> str:
        if self.kinds <= {"dispatcher", "scheduler"}:
            return "running"
        if self.streams():
            return "running"
        # worker_heartbeats.status is varchar(60): keep this short.
        return "idle: SNMP disabled, diagnostics off; consuming nothing"

    async def process(self, message: Message, handler: Callable[[Message], Awaitable[None]]) -> str:
        key = self.ctx.cfg.job_signing_key
        async with self.ctx.pool.acquire() as conn:
            if not key or not verify(message.fields, key):
                await self.queue.dead_letter(conn, message, "bad signature")
                return "dead"
            if message.deliveries > self.queue.max_deliveries:
                await self.queue.dead_letter(conn, message, "retries exhausted")
                return "dead"
        try:
            await handler(message)
        except Skipped as why:
            logger.info("skipped %s: %s", message.fields.get("job_id"), why)
            await self.queue.ack(message)
            return "skipped"
        except Permanent as why:
            async with self.ctx.pool.acquire() as conn:
                await self.queue.dead_letter(conn, message, str(why))
            return "dead"
        except RetryLater as why:
            logger.info("will retry %s: %s", message.fields.get("job_id"), why)
            return "retry"
        except Exception:  # noqa: BLE001 - an unexpected failure is retried with backoff, then dead-lettered
            logger.exception("job %s failed", message.fields.get("job_id"))
            return "retry"
        await self.queue.ack(message)
        return "done"

    async def _bounded(self, message: Message, handler: Callable[[Message], Awaitable[None]]) -> None:
        async with self._sem:
            outcome = await self.process(message, handler)
        self.processed[outcome] += 1

    async def run_once(self, *, block_ms: int = 100) -> dict[str, int]:
        """One pass: dispatch, reclaim what got stuck, read and process a batch. Used by tests and by the loop."""
        before = dict(self.processed)
        if self.scheduler is not None:
            await self.scheduler.tick()
        if "dispatcher" in self.kinds:
            await dispatch_discovery(self.ctx, self.queue)
            await self.queue.release_due(POLL_STREAM)          # polls the scheduler spread over a window are due now
        tasks = []
        for stream, handler in self.streams():
            await self.queue.ensure_group(stream)
            batch = await self.queue.reclaim(stream, self.worker_id) + await self.queue.read(stream, self.worker_id, count=self.ctx.cfg.worker_concurrency, block_ms=block_ms)
            tasks += [asyncio.create_task(self._bounded(m, handler)) for m in batch]
        if tasks:
            await asyncio.gather(*tasks)
        await self.beat()          # after the pass, so it reports what this pass established (for example scheduler leadership)
        return {k: self.processed[k] - before[k] for k in self.processed}

    async def beat(self) -> None:
        async with self.ctx.pool.acquire() as conn:
            await heartbeat.beat(conn, self.worker_id, "+".join(sorted(self.kinds)), self.status(),
                                 {"processed": self.processed, "published": self.queue.stats.published, "dead": self.queue.stats.dead,
                                  **({"scheduler_leader": self.scheduler.is_leader} if self.scheduler else {})})

    async def run_forever(self) -> None:
        logger.info("worker %s starting: %s", self.worker_id, self.status())
        while not self.stop.is_set():
            try:
                await self.run_once(block_ms=2000)
            except Exception:  # noqa: BLE001 - a bad pass must not kill the worker; back off and try again
                logger.exception("worker pass failed")
                await asyncio.sleep(2)
            if not self.streams():
                await asyncio.sleep(5)  # nothing to consume: just keep the heartbeat and the dispatcher alive
        if self.scheduler is not None:
            await self.scheduler.release()
        logger.info("worker %s stopped", self.worker_id)


async def dispatch_discovery(ctx: Context, queue: JobQueue) -> int:
    """Publish discovery jobs that were queued in the database but never reached the stream (Redis was down when the device
    was added), and re-publish ones that were published but never picked up. After three attempts the job is failed."""
    from app.services.discovery import publish_job

    published = 0
    async with ctx.pool.acquire() as conn:
        rows: list[asyncpg.Record] = await conn.fetch(
            """
            select id, publish_attempts from discovery_jobs
            where status = 'queued' and (published_at is null or published_at < now() - interval '15 minutes')
            order by requested_at limit 50
            """
        )
        for row in rows:
            if row["publish_attempts"] >= 3:
                await conn.execute(
                    "update discovery_jobs set status = 'failed', finished_at = now(), error = 'never picked up by a worker after 3 attempts' where id = $1 and status = 'queued'",
                    row["id"])
                continue
            if await publish_job(conn, ctx.redis, str(row["id"])):
                published += 1
    return published
