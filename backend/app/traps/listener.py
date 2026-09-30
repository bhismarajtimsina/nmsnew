"""The trap-receiver worker (D-19, own-components.md §3.2): a standalone UDP listener, run as its own process/
container, not a Redis Streams job consumer like app/workers - the legacy `wca-traplistener` it replaces was its own
container too, for the same reason: a stream of unsolicited datagrams from the network doesn't fit a pull-based job
queue.

It only ever receives. Nothing here opens a connection to a device, and at cutover it takes over the legacy
listener's own address and port so no device is reconfigured (D-19); until then it binds a port of its own.
"""
from __future__ import annotations

import asyncio
import logging

import asyncpg

from app.core.crypto import EncryptionService
from app.traps.decode import decode_trap, TrapDecodeError
from app.traps.ingest import record_trap
from app.traps.ratelimit import TrapRateLimiter

logger = logging.getLogger("cybersathy.trap_receiver")


class TrapCounters:
    def __init__(self) -> None:
        self.accepted = 0
        self.unknown = 0  # accepted, but the OID matched no trap_profiles row
        self.unknown_source = 0  # dropped: the source IP matched no device
        self.bad_community = 0  # dropped: check_community is on and the trap's community didn't match
        self.malformed = 0  # dropped: could not be decoded as an SNMP v1/v2c trap at all
        self.rate_limited_source = 0  # dropped undecoded: this source exceeded its own rate
        self.rate_limited_global = 0  # dropped undecoded: the receiver-wide rate was exceeded
        self.overloaded = 0  # dropped undecoded: max_in_flight packets were already being handled


# Legacy's trap listener bounded its work the same way: a 500-packet queue in front of its handlers
# (.trap-listener.yml, `script_handler.queue_size`). It had no per-source limit; that part is new.
DEFAULT_MAX_IN_FLIGHT = 500


class TrapProtocol(asyncio.DatagramProtocol):
    """One UDP packet is one datagram_received call, which is synchronous (the asyncio protocol API), so the actual
    decode-and-store work runs as a tracked background task - tracked so a test (or a graceful shutdown) can wait
    for every in-flight packet to finish being handled before checking what happened.

    Flood protection runs before any task is created: the rate limiter (per source, then global) and a cap on
    packets in flight, so a flood can neither exhaust memory with queued tasks nor the database pool with inserts."""

    def __init__(
        self, pool: asyncpg.Pool, counters: TrapCounters, *, enc: EncryptionService | None = None, check_community: bool = False,
        limiter: TrapRateLimiter | None = None, max_in_flight: int = DEFAULT_MAX_IN_FLIGHT,
    ) -> None:
        self.pool = pool
        self.counters = counters
        self.enc = enc
        self.check_community = check_community
        self.limiter = limiter
        self.max_in_flight = max_in_flight
        self._tasks: set[asyncio.Task] = set()

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        source_ip = addr[0]
        if len(self._tasks) >= self.max_in_flight:
            self.counters.overloaded += 1
            return
        if self.limiter is not None:
            refused = self.limiter.allow(source_ip)
            if refused == "source":
                self.counters.rate_limited_source += 1
                return
            if refused == "global":
                self.counters.rate_limited_global += 1
                return
        task = asyncio.ensure_future(self._handle(data, source_ip))
        self._tasks.add(task)
        task.add_done_callback(self._tasks.discard)

    async def _handle(self, data: bytes, source_ip: str) -> None:
        try:
            decoded = decode_trap(data)
        except TrapDecodeError as exc:
            self.counters.malformed += 1
            logger.info("malformed trap from %s: %s", source_ip, exc)
            return
        try:
            async with self.pool.acquire() as conn:
                outcome = await record_trap(conn, source_ip, decoded, enc=self.enc, check_community=self.check_community)
        except Exception:  # noqa: BLE001 - one bad packet or a transient database error must not kill the listener
            logger.exception("failed to record a trap from %s", source_ip)
            return
        if not outcome.accepted:
            self.counters.unknown_source += 1
            logger.info("dropped trap from unknown source %s", source_ip)
        elif not outcome.community_ok:
            self.counters.bad_community += 1
            logger.info("dropped trap from %s: community did not match its access profile", source_ip)
        elif outcome.known:
            self.counters.accepted += 1
        else:
            self.counters.unknown += 1

    async def drain(self) -> None:
        """Waits for every packet currently being handled to finish. Tests use this instead of a sleep."""
        while self._tasks:
            await asyncio.gather(*list(self._tasks))


async def serve(
    pool: asyncpg.Pool, host: str = "0.0.0.0", port: int = 1162, *,
    enc: EncryptionService | None = None, check_community: bool = False,
    limiter: TrapRateLimiter | None = None, max_in_flight: int = DEFAULT_MAX_IN_FLIGHT,
) -> tuple[asyncio.DatagramTransport, TrapProtocol]:
    """Binds the UDP socket and starts receiving. Call `.close()` on the returned transport to stop.

    Port 1162, not 162, by default: 162 is privileged and is the legacy listener's own port until cutover hands it
    over (D-19); this default is for development and tests, overridden by configuration in the real deployment.
    """
    loop = asyncio.get_running_loop()
    counters = TrapCounters()
    transport, protocol = await loop.create_datagram_endpoint(
        lambda: TrapProtocol(pool, counters, enc=enc, check_community=check_community, limiter=limiter,
                             max_in_flight=max_in_flight),
        local_addr=(host, port),
    )
    return transport, protocol
