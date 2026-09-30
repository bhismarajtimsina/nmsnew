"""python -m app.notifications run   |   python -m app.notifications health

Its own process, like the trap receiver and the pinger: `app.workers`' Redis-Streams job consumers are pull-based
against a queue something else feeds; this instead polls `notifications` directly and self-paces (idle sleep when
nothing is due), the same shape `NotificationSenderService.php`'s own long-running `service` command has. See
app/notifications/service.py.
"""
from __future__ import annotations

import argparse
import asyncio
import logging
import signal

from app.core.config import settings
from app.core.database import create_pool
from app.core.logging import configure_logging
from app.notifications.sender import UnconfiguredChannel
from app.notifications.service import run_cycle


async def run() -> int:
    configure_logging()
    log = logging.getLogger("cybersathy.notifications")
    pool = await create_pool()
    channel = UnconfiguredChannel()
    in_flight: set[str] = set()
    log.info(
        "notification sender starting, max_concurrent=%d, idle=%ds",
        settings.notification_sender_max_concurrent, settings.notification_sender_idle_seconds,
    )
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)
    try:
        while not stop.is_set():
            summary = await run_cycle(
                pool, channel, in_flight=in_flight, max_concurrent=settings.notification_sender_max_concurrent,
                check_previous_message=settings.notification_check_previous_message,
            )
            log.info("cycle: %s", summary)
            if summary["found"] == 0:
                try:
                    await asyncio.wait_for(stop.wait(), timeout=settings.notification_sender_idle_seconds)
                except asyncio.TimeoutError:
                    pass
    finally:
        await pool.close()
    log.info("notification sender stopped")
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
    parser = argparse.ArgumentParser(prog="python -m app.notifications")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("run")
    sub.add_parser("health")
    args = parser.parse_args()
    if args.command == "health":
        return asyncio.run(health())
    return asyncio.run(run())


if __name__ == "__main__":
    raise SystemExit(main())
