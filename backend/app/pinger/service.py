"""Pings targets, debounces, records status and history, and exposes the `pinger_host_status` gauge the already-
ported `pinger_host_down` Alertmanager rule depends on. See app/pinger/check.py's docstring for why this worker
decides no alarm itself - that is Alertmanager's job, reached through Plan 20's webhook exactly as for any other rule.

Targets: devices with `polling_enabled` and `polling_owner = 'cybersathy'` (own-components.md §3.1) - the same
ownership switch the SNMP polling engine already uses, so the legacy pinger keeps pinging everything else until an
operator hands a device group over.

No rate limiting or concurrent fan-out yet: devices are checked one at a time, in a plain loop. own-components.md's
design calls for a global packets-per-second cap and jitter across a concurrent batch; that is a performance and
safety-margin refinement on top of a correct sequential pass, not a correctness requirement of the debounce or the
gauge, and is left for a follow-up round (the same kind of deliberately-deferred gap as the trap receiver's rate
limiting, Plan 21).
"""
from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Awaitable, Callable

import asyncpg
from icmplib import async_ping
from prometheus_client import Gauge

from app.pinger.check import PingState, apply_result

logger = logging.getLogger("cybersathy.pinger")

# Not cybersathy_-prefixed: this is the exact legacy metric name the already-ported pinger_host_down alarm rule's
# PromQL (`pinger_host_status <= 0`) depends on (D-26's usual naming rule has this one deliberate exception).
PINGER_HOST_STATUS = Gauge(
    "pinger_host_status", "ICMP reachability: > 0 is up (latency in ms), <= 0 is down", ["ip", "device_id", "name"]
)


@dataclass
class Target:
    device_id: str
    name: str
    ip: str


async def list_targets(conn: asyncpg.Connection) -> list[Target]:
    rows = await conn.fetch(
        "select id, name, host(management_ip) as ip from devices "
        "where polling_enabled and polling_owner = 'cybersathy' and management_ip is not null"
    )
    return [Target(device_id=str(r["id"]), name=r["name"], ip=r["ip"]) for r in rows]


async def check_one(
    conn: asyncpg.Connection, target: Target, *, count: int, timeout: float, misses_for_down: int, privileged: bool
) -> bool:
    """Pings one target, applies the debounce, updates its status row and, on a transition, its history and the
    gauge. Returns whether the target answered this check."""
    alive, _ = await _check(conn, target, count=count, timeout=timeout, misses_for_down=misses_for_down, privileged=privileged)
    return alive


async def _check(
    conn: asyncpg.Connection, target: Target, *, count: int, timeout: float, misses_for_down: int, privileged: bool
) -> tuple[bool, bool]:
    """check_one's work; also returns whether the target's up/down status changed."""
    try:
        host = await async_ping(target.ip, count=count, timeout=timeout, privileged=privileged)
        alive = host.is_alive
        latency_ms = host.avg_rtt if alive else None
    except Exception:  # noqa: BLE001 - a resolution failure or socket error is just "no reply", not a crash
        logger.exception("ping failed for %s (%s)", target.name, target.ip)
        alive, latency_ms = False, None

    row = await conn.fetchrow("select status, consecutive_misses from device_ping_status where device_id = $1::uuid", target.device_id)
    if row is None:
        await conn.execute("insert into device_ping_status (device_id) values ($1::uuid) on conflict do nothing", target.device_id)
        state = PingState()
    else:
        state = PingState(status=row["status"], consecutive_misses=row["consecutive_misses"])

    new_state, changed = apply_result(state, alive=alive, misses_for_down=misses_for_down)
    await conn.execute(
        "update device_ping_status set status = $2, consecutive_misses = $3, latency_ms = $4, last_checked_at = now(), "
        "last_changed_at = case when $5 then now() else last_changed_at end where device_id = $1::uuid",
        target.device_id, new_state.status, new_state.consecutive_misses, latency_ms, changed,
    )
    if changed:
        await conn.execute(
            "insert into device_ping_history (device_id, status, latency_ms) values ($1::uuid, $2, $3)",
            target.device_id, new_state.status, latency_ms,
        )

    PINGER_HOST_STATUS.labels(ip=target.ip, device_id=target.device_id, name=target.name).set(latency_ms if alive else 0)
    return alive, changed


async def run_cycle(
    conn: asyncpg.Connection, *, count: int = 3, timeout: float = 1.0, misses_for_down: int = 3, privileged: bool = False,
    on_change: Callable[[], Awaitable[None]] | None = None,
) -> dict[str, int]:
    """One pass over every current target. `on_change` is awaited once at the end of a pass in which any target
    went up or down (not once per target, so a site-wide outage is one notice, not thousands)."""
    targets = await list_targets(conn)
    up = down = changed = 0
    for target in targets:
        alive, flipped = await _check(conn, target, count=count, timeout=timeout, misses_for_down=misses_for_down, privileged=privileged)
        up, down = (up + 1, down) if alive else (up, down + 1)
        changed += flipped
    if changed and on_change is not None:
        await on_change()
    return {"targets": len(targets), "up": up, "down": down, "changed": changed}
