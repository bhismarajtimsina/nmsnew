"""Flood protection for the trap receiver (Plan 21, risk K-15): a token bucket per source address and one global
bucket, checked on the source IP alone - before a packet is decoded or reaches the database, so a flood costs one
dictionary lookup per datagram and nothing more.

In-process, not Redis-backed: exactly one trap-receiver process binds the UDP port (D-19), so there is no second
instance to share state with, and a Redis round trip per datagram would put a network hop on the hot path of the very
flood this exists to shed. If the receiver is ever scaled out behind a UDP load balancer, this is the piece to move.

A packet takes a token from both buckets or from neither: a global-cap refusal never spends the sender's own budget,
and a per-source refusal never spends the global one, so one noisy device cannot drain the shared cap by being
refused.
"""
from __future__ import annotations

import time
from collections.abc import Callable


class TokenBucket:
    __slots__ = ("rate", "burst", "tokens", "updated")

    def __init__(self, rate: float, burst: float, now: float) -> None:
        self.rate = rate
        self.burst = burst
        self.tokens = burst
        self.updated = now

    def refill(self, now: float) -> None:
        elapsed = now - self.updated
        if elapsed > 0:
            self.tokens = min(self.burst, self.tokens + elapsed * self.rate)
        self.updated = now

    def full(self) -> bool:
        return self.tokens >= self.burst


class TrapRateLimiter:
    """`allow(source_ip)` returns None when the packet may proceed, or the reason it may not: "source" or "global".

    Idle sources are forgotten once `max_sources` buckets exist: a bucket that has refilled to its burst is
    indistinguishable from a fresh one, so dropping it changes no decision and keeps memory bounded however many
    addresses (spoofed or real) send. The sweep runs at most once per `prune_interval` seconds, so a flood of new
    addresses arriving while nothing is prunable cannot turn every packet into a full scan; between sweeps the table
    may briefly exceed `max_sources`."""

    def __init__(
        self, *, source_rate: float, source_burst: float, global_rate: float, global_burst: float,
        max_sources: int = 10_000, prune_interval: float = 1.0, clock: Callable[[], float] = time.monotonic,
    ) -> None:
        if min(source_rate, source_burst, global_rate, global_burst) <= 0:
            raise ValueError("trap rate limits must be positive")
        self.source_rate = source_rate
        self.source_burst = source_burst
        self.max_sources = max_sources
        self.prune_interval = prune_interval
        self.clock = clock
        self._next_prune = float("-inf")
        self._global = TokenBucket(global_rate, global_burst, clock())
        self._sources: dict[str, TokenBucket] = {}

    def allow(self, source_ip: str) -> str | None:
        now = self.clock()
        bucket = self._sources.get(source_ip)
        if bucket is None:
            if len(self._sources) >= self.max_sources and now >= self._next_prune:
                self._prune(now)
                self._next_prune = now + self.prune_interval
            bucket = self._sources[source_ip] = TokenBucket(self.source_rate, self.source_burst, now)
        bucket.refill(now)
        self._global.refill(now)
        if bucket.tokens < 1:
            return "source"
        if self._global.tokens < 1:
            return "global"
        bucket.tokens -= 1
        self._global.tokens -= 1
        return None

    def _prune(self, now: float) -> None:
        for ip, bucket in list(self._sources.items()):
            bucket.refill(now)
            if bucket.full():
                del self._sources[ip]

    @property
    def tracked_sources(self) -> int:
        return len(self._sources)
