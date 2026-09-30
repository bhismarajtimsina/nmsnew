"""python -m app.pinger run   |   python -m app.pinger health

Its own process, like the trap receiver: an active periodic network probe doesn't fit the Redis Streams job-consumer
shape app.workers uses. See app/pinger/service.py.
"""
from __future__ import annotations

import argparse
import asyncio
import logging
import signal

from prometheus_client import start_http_server

from app.core.config import settings
from app.core.database import create_pool
from app.core.logging import configure_logging
from app.pinger.service import run_cycle


async def run() -> int:
    configure_logging()
    log = logging.getLogger("cybersathy.pinger")
    pool = await create_pool()
    start_http_server(settings.pinger_metrics_port)
    log.info("pinger metrics on :%d, cycling every %ds", settings.pinger_metrics_port, settings.pinger_cycle_seconds)
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)
    try:
        while not stop.is_set():
            async with pool.acquire() as conn:
                summary = await run_cycle(
                    conn, count=settings.pinger_count, timeout=settings.pinger_timeout_seconds,
                    misses_for_down=settings.pinger_misses_for_down, privileged=settings.pinger_privileged,
                )
            log.info("cycle: %s", summary)
            try:
                await asyncio.wait_for(stop.wait(), timeout=settings.pinger_cycle_seconds)
            except asyncio.TimeoutError:
                pass
    finally:
        await pool.close()
    log.info("pinger stopped")
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
    parser = argparse.ArgumentParser(prog="python -m app.pinger")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("run")
    sub.add_parser("health")
    args = parser.parse_args()
    if args.command == "health":
        return asyncio.run(health())
    return asyncio.run(run())


if __name__ == "__main__":
    raise SystemExit(main())
