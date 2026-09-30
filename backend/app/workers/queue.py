"""Job queue on Redis Streams.

  * **Idempotent publishing**: a job id can be published once; a duplicate is dropped.
  * **Signed jobs**: every message carries an HMAC over its fields. A worker acts only on messages the API or the dispatcher
    produced, never on something written to the stream by anyone else.
  * **Consumer groups**: each message is delivered to one worker.
  * **Bounded retries with backoff**: a message that was not acknowledged is claimed again only after an exponentially growing
    delay, and after `max_deliveries` deliveries it is moved to the dead-letter table instead of being tried again.
  * **Bounded streams**: `MAXLEN` caps memory.
  * **Locks**: one poll of a device at a time, and a cap on concurrent polls per vendor, so a slow vendor cannot absorb every worker.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import time
import uuid
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from typing import Any, AsyncIterator

import asyncpg
from redis.asyncio import Redis
from redis.exceptions import ResponseError

GROUP = "workers"
IDEMPOTENCY_TTL_SECONDS = 24 * 3600


class SigningKeyMissing(RuntimeError):
    pass


class Skipped(Exception):
    """The job was valid but must not run now (device busy, owner changed, switched off). Acknowledged, not retried."""


class Permanent(Exception):
    """The job can never succeed. It is dead-lettered at once."""


class RetryLater(Exception):
    """A transient problem. The job stays pending and is claimed again after a backoff."""


def canonical(fields: dict[str, str]) -> bytes:
    return json.dumps({k: v for k, v in fields.items() if k != "sig"}, sort_keys=True, separators=(",", ":")).encode("utf-8")


def sign(fields: dict[str, str], key: str) -> str:
    return hmac.new(key.encode("utf-8"), canonical(fields), hashlib.sha256).hexdigest()


def verify(fields: dict[str, str], key: str) -> bool:
    given = fields.get("sig", "")
    return bool(given) and hmac.compare_digest(given, sign(fields, key))


@dataclass
class Message:
    stream: str
    id: str
    fields: dict[str, str]
    deliveries: int = 1


@dataclass
class QueueStats:
    published: int = 0
    duplicates: int = 0
    dead: int = 0
    reclaimed: int = 0
    extra: dict[str, Any] = field(default_factory=dict)


class JobQueue:
    def __init__(self, redis: Redis, signing_key: str | None, *, maxlen: int = 10_000, max_deliveries: int = 3, retry_base_ms: int = 30_000) -> None:
        self.redis = redis
        self.key = signing_key
        self.maxlen = maxlen
        self.max_deliveries = max_deliveries
        self.retry_base_ms = retry_base_ms
        self.stats = QueueStats()

    async def publish(self, stream: str, job_id: str, fields: dict[str, Any]) -> bool:
        """Publish once. Returns False when this job id was already published (nothing is added)."""
        if not self.key:
            raise SigningKeyMissing("JOB_SIGNING_KEY is not set; refusing to publish an unsigned job")
        idem = f"cs:job:{stream}:{job_id}"
        if not await self.redis.set(idem, "1", nx=True, ex=IDEMPOTENCY_TTL_SECONDS):
            self.stats.duplicates += 1
            return False
        message = {k: str(v) for k, v in fields.items()}
        message["job_id"] = job_id
        message["sig"] = sign(message, self.key)
        try:
            await self.redis.xadd(stream, message, maxlen=self.maxlen, approximate=self.maxlen >= 1000)
        except Exception:
            await self.redis.delete(idem)  # nothing was published, so the id must stay usable
            raise
        self.stats.published += 1
        return True

    async def publish_at(self, stream: str, job_id: str, fields: dict[str, Any], due_ms: int) -> bool:
        """Publish later. The signed message waits in a sorted set until `due_ms`, then `release_due` moves it to the stream.
        Idempotent like `publish`. Used to spread a burst of polls over a window instead of hitting every device at once."""
        if not self.key:
            raise SigningKeyMissing("JOB_SIGNING_KEY is not set; refusing to publish an unsigned job")
        idem = f"cs:job:{stream}:{job_id}"
        if not await self.redis.set(idem, "1", nx=True, ex=IDEMPOTENCY_TTL_SECONDS):
            self.stats.duplicates += 1
            return False
        message = {k: str(v) for k, v in fields.items()}
        message["job_id"] = job_id
        message["sig"] = sign(message, self.key)
        try:
            await self.redis.zadd(f"cs:delayed:{stream}", {json.dumps(message, sort_keys=True): due_ms})
        except Exception:
            await self.redis.delete(idem)
            raise
        return True

    async def release_due(self, stream: str, *, limit: int = 500, now_ms: int | None = None) -> int:
        """Move messages whose time has come onto the stream. Safe to call from several workers: each entry is claimed by
        removing it from the set first, so it is released exactly once."""
        now = now_ms if now_ms is not None else int(time.time() * 1000)
        key = f"cs:delayed:{stream}"
        released = 0
        for raw in await self.redis.zrangebyscore(key, "-inf", now, start=0, num=limit):
            if await self.redis.zrem(key, raw):
                await self.redis.xadd(stream, json.loads(raw), maxlen=self.maxlen, approximate=self.maxlen >= 1000)
                released += 1
        return released

    async def ensure_group(self, stream: str) -> None:
        try:
            await self.redis.xgroup_create(stream, GROUP, id="0", mkstream=True)
        except ResponseError as exc:
            if "BUSYGROUP" not in str(exc):
                raise

    async def read(self, stream: str, consumer: str, *, count: int = 10, block_ms: int = 2000) -> list[Message]:
        reply = await self.redis.xreadgroup(GROUP, consumer, {stream: ">"}, count=count, block=block_ms)
        return [Message(stream, mid, fields, 1) for _, entries in (reply or []) for mid, fields in entries]

    async def ack(self, message: Message) -> None:
        await self.redis.xack(message.stream, GROUP, message.id)

    async def reclaim(self, stream: str, consumer: str) -> list[Message]:
        """Take over messages a worker read but never acknowledged (it crashed or is stuck), respecting the backoff:
        the n-th redelivery waits `retry_base_ms * 2**(n-1)`."""
        claimed: list[Message] = []
        pending = await self.redis.xpending_range(stream, GROUP, min="-", max="+", count=100)
        for entry in pending:
            needed = self.retry_base_ms * (2 ** (entry["times_delivered"] - 1))
            if entry["time_since_delivered"] < needed:
                continue
            taken = await self.redis.xclaim(stream, GROUP, consumer, min_idle_time=needed, message_ids=[entry["message_id"]])
            for mid, fields in taken:
                if not fields:  # trimmed away while pending
                    await self.redis.xack(stream, GROUP, mid)
                    continue
                claimed.append(Message(stream, mid, fields, entry["times_delivered"] + 1))
        self.stats.reclaimed += len(claimed)
        return claimed

    async def dead_letter(self, conn: asyncpg.Connection, message: Message, reason: str) -> None:
        await conn.execute(
            "insert into dead_letter_jobs (stream, job_id, message_id, fields, reason, deliveries) values ($1, $2, $3, $4::jsonb, $5, $6)",
            message.stream, message.fields.get("job_id"), message.id,
            json.dumps({k: v for k, v in message.fields.items() if k != "sig"}), reason[:200], message.deliveries,
        )
        await self.ack(message)
        self.stats.dead += 1


_DEVICE_RELEASE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end"
_VENDOR_ACQUIRE = """
redis.call('zremrangebyscore', KEYS[1], '-inf', ARGV[1])
if redis.call('zcard', KEYS[1]) >= tonumber(ARGV[3]) then return 0 end
redis.call('zadd', KEYS[1], tonumber(ARGV[1]) + tonumber(ARGV[2]), ARGV[4])
redis.call('pexpire', KEYS[1], tonumber(ARGV[2]) * 2)
return 1
"""


class Locks:
    """One poll of a device at a time, and a cap on concurrent polls per vendor. Both expire on their own, so a crashed worker
    cannot hold them forever."""

    def __init__(self, redis: Redis) -> None:
        self.redis = redis

    @asynccontextmanager
    async def device(self, device_id: str, ttl_ms: int) -> AsyncIterator[bool]:
        key, token = f"cs:lock:device:{device_id}", uuid.uuid4().hex
        got = bool(await self.redis.set(key, token, nx=True, px=ttl_ms))
        try:
            yield got
        finally:
            if got:
                await self.redis.eval(_DEVICE_RELEASE, 1, key, token)

    @asynccontextmanager
    async def vendor(self, vendor_slug: str, limit: int, ttl_ms: int) -> AsyncIterator[bool]:
        key, token = f"cs:sem:vendor:{vendor_slug}", uuid.uuid4().hex
        got = bool(await self.redis.eval(_VENDOR_ACQUIRE, 1, key, int(time.time() * 1000), ttl_ms, limit, token))
        try:
            yield got
        finally:
            if got:
                await self.redis.zrem(key, token)
