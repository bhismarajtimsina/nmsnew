"""python -m app.traps run   |   python -m app.traps health

Its own process, not a app.workers kind: a stream of unsolicited UDP datagrams doesn't fit a pull-based job queue.
See app/traps/listener.py.
"""
from __future__ import annotations

import argparse
import asyncio
import logging
import signal
import sys

from app.core.config import settings
from app.core.crypto import EncryptionService
from app.core.database import create_pool
from app.core.logging import configure_logging
from app.traps.listener import serve
from app.traps.ratelimit import TrapRateLimiter


async def run() -> int:
    configure_logging()
    log = logging.getLogger("cybersathy.trap_receiver")
    pool = await create_pool()
    # Only constructed when actually needed: TRAP_CHECK_COMMUNITY off (its default) never touches a device's
    # community, matching legacy's own `_env('TRAP_SERVICE_CHECK_COMMUNITY', false)` gate exactly.
    enc = EncryptionService.from_settings() if settings.trap_check_community else None
    limiter = TrapRateLimiter(
        source_rate=settings.trap_source_rate, source_burst=settings.trap_source_burst,
        global_rate=settings.trap_global_rate, global_burst=settings.trap_global_burst,
    )
    transport, protocol = await serve(
        pool, host=settings.trap_listener_host, port=settings.trap_listener_port,
        enc=enc, check_community=settings.trap_check_community,
        limiter=limiter, max_in_flight=settings.trap_max_in_flight,
    )
    log.info("trap receiver listening on %s:%s, check_community=%s",
             settings.trap_listener_host, settings.trap_listener_port, settings.trap_check_community)
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)
    try:
        await stop.wait()
        await protocol.drain()
    finally:
        transport.close()
        await pool.close()
    c = protocol.counters
    log.info("trap receiver stopped: accepted=%d unknown=%d unknown_source=%d bad_community=%d malformed=%d "
             "rate_limited_source=%d rate_limited_global=%d overloaded=%d",
             c.accepted, c.unknown, c.unknown_source, c.bad_community, c.malformed,
             c.rate_limited_source, c.rate_limited_global, c.overloaded)
    return 0


async def health() -> int:
    """Binds nothing: just confirms the database this process would use is reachable."""
    pool = await create_pool()
    try:
        await pool.fetchval("select 1")
    finally:
        await pool.close()
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(prog="python -m app.traps")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("run")
    sub.add_parser("health")
    args = parser.parse_args()
    if args.command == "health":
        return asyncio.run(health())
    return asyncio.run(run())


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        sys.exit(0)
