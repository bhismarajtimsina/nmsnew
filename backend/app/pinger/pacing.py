"""The pinger's global packets-per-second cap (Plan 12, own-components.md §3.1).

Every probe reserves its packets (`count` per target) before sending, so a pass never sends faster than the cap
however many targets there are or however many run at once: N targets at `count` packets take at least
N * count / rate seconds after the first burst. Reservations are served one at a time, in order, so a waiting probe is
not overtaken by later ones.

Pacing also spreads the sends evenly, which is what jitter was meant to do, so no separate jitter is added.
"""
from __future__ import annotations

import asyncio
import time
from collections.abc import Awaitable, Callable

from app.traps.ratelimit import TokenBucket


class PacketPacer:
    def __init__(self, rate: float, burst: float, *, clock: Callable[[], float] = time.monotonic,
                 sleep: Callable[[float], Awaitable[None]] = asyncio.sleep) -> None:
        if rate <= 0 or burst < 1:
            raise ValueError("the pinger's packet rate must be positive and its burst at least one packet")
        self.rate = rate
        self.burst = burst
        self._clock = clock
        self._sleep = sleep
        self._bucket = TokenBucket(rate, burst, clock())
        self._lock = asyncio.Lock()

    async def acquire(self, packets: int) -> None:
        """Waits until `packets` fit under the cap, then spends them."""
        if not 0 < packets <= self.burst:
            raise ValueError(f"a probe of {packets} packets can never fit a burst of {self.burst:g}")
        async with self._lock:
            self._bucket.refill(self._clock())
            if self._bucket.tokens < packets:
                await self._sleep((packets - self._bucket.tokens) / self.rate)
            # Spent after one computed wait, not re-checked in a loop: rounding can leave the bucket a hair short, and
            # waiting again for that hair is a sleep too small for the clock to register, so the loop would never end.
            # The balance goes negative by what was owed; the next refill credits the time this probe waited, and a
            # shortfall from a short sleep is waited off by the next probe, so the average rate holds.
            self._bucket.tokens -= packets
