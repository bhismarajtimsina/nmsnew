"""python -m app.workers run --kinds actions,diagnostics,discovery,poller,dispatcher   |   python -m app.workers health"""
from __future__ import annotations

import argparse
import asyncio
import logging
import os
import signal
import socket
import sys

from app.core.config import settings
from app.core.crypto import EncryptionNotConfigured, EncryptionService
from app.core.database import create_pool
from app.core.logging import configure_logging
from app.core.redis import create_redis
from app.polling.engine import Context
from app.polling.transport import DisabledTransport, SnmpTransport
from app.diagnostics.ping import build_prober
from app.workers.runner import KINDS, Worker


def build_transport() -> SnmpTransport:
    if settings.snmp_transport == "disabled":
        return DisabledTransport()
    # Choosing a real transport is decision D-17. Until it is made and reviewed, anything else is refused, not guessed at.
    raise SystemExit(f"SNMP_TRANSPORT={settings.snmp_transport!r} is not available in this build; use 'disabled'")


async def run(kinds: set[str]) -> int:
    configure_logging()
    log = logging.getLogger("cybersathy.worker")
    if not settings.job_signing_key:
        print("JOB_SIGNING_KEY is not set; refusing to start", file=sys.stderr)
        return 2
    pool, redis = await create_pool(), create_redis()
    try:
        enc = EncryptionService.from_settings()
    except EncryptionNotConfigured:
        enc = None
        log.warning("credential encryption is not configured; jobs that need credentials will fail")
    prober = build_prober(settings.diagnostics_enabled, settings.pinger_privileged)
    worker = Worker(Context(pool, redis, build_transport(), enc, settings), kinds, prober=prober)
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, worker.stop.set)
    try:
        await worker.run_forever()
    finally:
        await redis.aclose()
        await pool.close()
    return 0


async def health(worker_id: str | None) -> int:
    pool = await create_pool()
    try:
        async with pool.acquire() as conn:
            ok = await conn.fetchval(
                "select exists(select 1 from worker_heartbeats where worker_id like $1 and last_seen > now() - interval '30 seconds')",
                (worker_id or socket.gethostname()) + "%",
            )
    finally:
        await pool.close()
    return 0 if ok else 1


def main() -> int:
    parser = argparse.ArgumentParser(prog="python -m app.workers")
    sub = parser.add_subparsers(dest="command", required=True)
    runner = sub.add_parser("run")
    runner.add_argument("--kinds", default=os.getenv("WORKER_KINDS", "actions,diagnostics,dispatcher,discovery,poller,scheduler"))
    sub.add_parser("health")
    args = parser.parse_args()
    if args.command == "health":
        return asyncio.run(health(None))
    kinds = {k.strip() for k in args.kinds.split(",") if k.strip()}
    if not kinds or kinds - KINDS:
        print(f"kinds must be a subset of {sorted(KINDS)}", file=sys.stderr)
        return 2
    return asyncio.run(run(kinds))


if __name__ == "__main__":
    raise SystemExit(main())
