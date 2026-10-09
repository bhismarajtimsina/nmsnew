"""On-demand ICMP ping of a device's management address (Plan 38, diagnostics).

The API checks the permission, the device's scope and two rate limits, writes a short-lived request record to Redis
and queues a signed job. A worker of kind `diagnostics` checks the requester again with fresh data, reads the address
from the database (never from the request, so this cannot be pointed at an arbitrary host), sends a bounded number of
echo requests, and writes the result to the same record. Results are not stored in the database: the record expires
after RESULT_TTL_SECONDS, and the audit log keeps the summary.

Nothing is sent unless DIAGNOSTICS_ENABLED is on; the default prober refuses every probe.
"""
from __future__ import annotations

import json
import uuid
from dataclasses import asdict, dataclass, field
from typing import Any, Protocol

from redis.asyncio import Redis

DIAG_STREAM = "diagnostics.jobs"
RESULT_TTL_SECONDS = 600
MAX_COUNT = 5
PACKET_TIMEOUT_SECONDS = 1.0
PACKET_INTERVAL_SECONDS = 0.5
PER_USER_PER_MINUTE = 10
PER_DEVICE_PER_MINUTE = 6


@dataclass(frozen=True)
class PingResult:
    sent: int
    received: int
    rtts_ms: list[float] = field(default_factory=list)

    @property
    def loss_percent(self) -> float:
        return round(100.0 * (self.sent - self.received) / self.sent, 1) if self.sent else 100.0

    def summary(self) -> dict[str, Any]:
        rtts = self.rtts_ms
        return {"sent": self.sent, "received": self.received, "loss_percent": self.loss_percent,
                "min_ms": round(min(rtts), 2) if rtts else None,
                "avg_ms": round(sum(rtts) / len(rtts), 2) if rtts else None,
                "max_ms": round(max(rtts), 2) if rtts else None}


class ProbeDisabled(Exception):
    """The deployment has not switched diagnostics on."""


class Prober(Protocol):
    async def ping(self, address: str, *, count: int) -> PingResult: ...


class DisabledProber:
    """The default: refuses every probe, so nothing leaves the process until an operator opts in."""

    async def ping(self, address: str, *, count: int) -> PingResult:
        raise ProbeDisabled("diagnostics are switched off (DIAGNOSTICS_ENABLED)")


class IcmpProber:
    """Real echo requests through icmplib, the library the pinger already uses. Count and timeouts are capped here as
    well as in the API, so a forged job cannot ask for more."""

    def __init__(self, *, privileged: bool) -> None:
        self.privileged = privileged

    async def ping(self, address: str, *, count: int) -> PingResult:
        from icmplib import async_ping

        count = max(1, min(int(count), MAX_COUNT))
        host = await async_ping(address, count=count, interval=PACKET_INTERVAL_SECONDS, timeout=PACKET_TIMEOUT_SECONDS,
                                privileged=self.privileged)
        return PingResult(sent=host.packets_sent, received=host.packets_received, rtts_ms=list(host.rtts))


def build_prober(enabled: bool, privileged: bool) -> Prober:
    return IcmpProber(privileged=privileged) if enabled else DisabledProber()


def record_key(request_id: str) -> str:
    return f"cs:diag:{request_id}"


@dataclass
class Record:
    user_id: str
    device_id: str
    count: int
    status: str = "queued"        # queued -> succeeded | failed | refused
    result: dict[str, Any] | None = None
    error: str | None = None


async def save(redis: Redis, request_id: str, record: Record) -> None:
    await redis.set(record_key(request_id), json.dumps(asdict(record)), ex=RESULT_TTL_SECONDS)


async def load(redis: Redis, request_id: str) -> Record | None:
    raw = await redis.get(record_key(request_id))
    if raw is None:
        return None
    try:
        return Record(**json.loads(raw))
    except (TypeError, ValueError):
        return None


def new_request_id() -> str:
    return str(uuid.uuid4())


class RateLimited(Exception):
    pass


async def check_rate(redis: Redis, user_id: str, device_id: str) -> None:
    """Fixed one-minute windows, per user and per device. The device limit holds across users, so several operators
    cannot together flood one device's management address."""
    for key, limit, who in ((f"cs:ratelimit:diag:user:{user_id}", PER_USER_PER_MINUTE, "you"),
                            (f"cs:ratelimit:diag:device:{device_id}", PER_DEVICE_PER_MINUTE, "this device")):
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, 60)
        if count > limit:
            raise RateLimited(f"too many diagnostics for {who}; try again in a minute")
