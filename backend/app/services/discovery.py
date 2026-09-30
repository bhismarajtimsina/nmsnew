"""Queue a safe discovery of a device.

Discovery answers one question, "what is this device?", by reading the four system OIDs. Nothing else is ever queued:
the database rejects a job that names any other OID, and the vendor's policy cannot be widened (see migration 0006).

The database row is the source of truth. Publishing to the Redis stream is best effort: if Redis is unavailable the job
stays `queued` and the dispatcher (Plan 12) publishes it later.
"""
from __future__ import annotations

import json
import logging
from typing import Any

import asyncpg
from redis.asyncio import Redis
from redis.exceptions import RedisError

from app.repositories import vendors as vendor_repo

logger = logging.getLogger("cybersathy.discovery")

DEFAULT_OIDS = ["1.3.6.1.2.1.1.1.0", "1.3.6.1.2.1.1.2.0", "1.3.6.1.2.1.1.3.0", "1.3.6.1.2.1.1.5.0"]
STREAM = "discovery.jobs"
STREAM_MAXLEN = 10_000


async def queue_discovery(conn: asyncpg.Connection, redis: Redis | None, device_id: str, requested_by: str | None) -> dict[str, Any]:
    device = await conn.fetchrow(
        """
        select d.id, d.polling_owner, d.access_profile_id, v.slug as vendor_slug, f.slug as family_slug,
               v.discovery_oids, v.discovery_timeout_ms, v.discovery_retries,
               p.timeout_ms as profile_timeout, p.retries as profile_retries
        from devices d
        left join vendor_model_families f on f.id = d.family_id
        left join vendors v on v.id = f.vendor_id
        left join device_access_profiles p on p.id = d.access_profile_id
        where d.id = $1::uuid
        """,
        device_id,
    )
    skip: str | None = None
    if device["polling_owner"] != "cybersathy":
        skip = "the device is still polled by the legacy system"
    elif device["access_profile_id"] is None:
        skip = "no access profile: nothing to authenticate with"
    elif device["vendor_slug"] is not None and not await vendor_repo.polling_allowed(conn, device["vendor_slug"], device["family_slug"]):
        skip = "polling is switched off for this vendor or model family"

    oids = list(device["discovery_oids"]) if device["discovery_oids"] else DEFAULT_OIDS
    timeout = min(device["profile_timeout"] or device["discovery_timeout_ms"] or 2000, 10_000)
    retries = min(device["profile_retries"] if device["profile_retries"] is not None else (device["discovery_retries"] if device["discovery_retries"] is not None else 1), 3)

    job_id = str(await conn.fetchval(
        """
        insert into discovery_jobs (device_id, status, oids, timeout_ms, retries, requested_by, error)
        values ($1::uuid, $2, $3::text[], $4, $5, $6::uuid, $7) returning id
        """,
        device_id, "skipped" if skip else "queued", oids, timeout, retries, requested_by, skip,
    ))
    status = "skipped" if skip else "queued"
    if not skip and redis is not None:
        await publish_job(conn, redis, job_id)
    return {"job_id": job_id, "status": status, "reason": skip}


async def publish_job(conn: asyncpg.Connection, redis: Redis, job_id: str) -> bool:
    """Put a queued job on the stream, reading what to send from the database row so the stream can never carry more than the
    row allows. Signed, and idempotent per attempt. Call after the transaction that created the job has committed.
    A failure leaves the job `queued` and unpublished: the dispatcher picks it up."""
    from app.core.config import settings
    from app.workers.queue import JobQueue, SigningKeyMissing

    job = await conn.fetchrow("select id, device_id, oids, timeout_ms, retries, status, publish_attempts from discovery_jobs where id = $1::uuid", job_id)
    if job is None or job["status"] != "queued":
        return False
    attempt = job["publish_attempts"] + 1
    queue = JobQueue(redis, settings.job_signing_key, maxlen=STREAM_MAXLEN)
    try:
        published = await queue.publish(
            STREAM, f"{job['id']}:{attempt}",
            {"discovery_id": str(job["id"]), "device_id": str(job["device_id"]), "oids": json.dumps(list(job["oids"])),
             "timeout_ms": job["timeout_ms"], "retries": job["retries"]},
        )
    except SigningKeyMissing:
        logger.error("JOB_SIGNING_KEY is not set; discovery job %s stays queued", job_id)
        return False
    except RedisError:
        logger.warning("could not publish discovery job %s; it stays queued in the database", job_id)
        return False
    if published:
        await conn.execute("update discovery_jobs set published_at = now(), publish_attempts = $2 where id = $1::uuid", job_id, attempt)
    return published
