"""Transport path and redundancy-group state (Plan 27), ported from legacy `Paths/Controllers/StateCalculator.php`.

A path is an ordered list of links between two endpoint devices. Legacy decides each hop from the pinger alone: down
when either device is unreachable, unknown when either is unmeasured, degraded when either answers slower than
PATHS_DEGRADED_LATENCY_MS (150; 0 turns degradation off). Here each hop also takes its link's own state
(app/topology/state.py), so a hop whose interface is down is down even while both devices still answer pings.

The path is the worst of its hops in this order: down, unknown, degraded, up. "Never claim a path is up while part of it
is unmeasured" is kept from legacy. A path with no hops is unknown.

A group (paths sharing a group key) is an outage when no path is usable (up or degraded), unprotected when it has more
than one path and at least one is not usable, protected when every path is usable, and up when it has a single path.

Pure functions over plain values; the database side is app/repositories/paths.py.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Literal

PathState = Literal["up", "degraded", "down", "unknown"]
# Legacy PathState::NUMERIC, the values the ported path_down / path_degraded alarm rules compare against.
NUMERIC = {"up": 1.0, "degraded": 0.5, "down": 0.0, "unknown": -1.0}
_ORDER = ("down", "unknown", "degraded", "up")


@dataclass(frozen=True)
class Endpoint:
    device_id: str
    ping: str | None          # device_ping_status.status: up, down, unknown, or None when never checked
    latency_ms: float | None


@dataclass(frozen=True)
class Hop:
    position: int
    link_id: str
    link_state: str | None     # from app/topology/state.py; None when the link no longer exists
    endpoints: tuple[Endpoint, ...] = field(default_factory=tuple)


def hop_state(hop: Hop, degraded_ms: int) -> tuple[PathState, str | None]:
    """The hop's state and, when it is not up, why."""
    if hop.link_state is None:
        return "unknown", "link_missing"
    if hop.link_state == "down" or any(e.ping == "down" for e in hop.endpoints):
        return "down", "link_down" if hop.link_state == "down" else "device_unreachable"
    if hop.link_state == "unknown" or any(e.ping != "up" or e.latency_ms is None for e in hop.endpoints):
        return "unknown", "unmeasured"
    if degraded_ms > 0 and any(e.latency_ms is not None and e.latency_ms > degraded_ms for e in hop.endpoints):
        return "degraded", "slow"
    return "up", None


def path_state(hops: list[Hop], degraded_ms: int) -> tuple[PathState, dict[str, Any]]:
    if not hops:
        return "unknown", {"reason": "no_segments", "hops": [], "degraded_threshold_ms": degraded_ms}
    detail = []
    states = []
    for hop in sorted(hops, key=lambda h: h.position):
        state, reason = hop_state(hop, degraded_ms)
        states.append(state)
        detail.append({"position": hop.position, "link_id": hop.link_id, "state": state, "reason": reason,
                       "endpoints": [{"device_id": e.device_id, "latency_ms": e.latency_ms, "ping": e.ping} for e in hop.endpoints]})
    worst = next(s for s in _ORDER if s in states)
    return worst, {"hops": detail, "degraded_threshold_ms": degraded_ms}  # type: ignore[return-value]


def group_state(members: list[tuple[str, str, int, PathState]]) -> dict[str, Any]:
    """members: (path id, name, priority, state)."""
    total = len(members)
    usable = sum(1 for *_, state in members if state in ("up", "degraded"))
    redundant = total > 1
    if usable == 0:
        state = "outage"
    elif redundant and usable < total:
        state = "unprotected"
    elif redundant:
        state = "protected"
    else:
        state = "up"
    return {"state": state, "total": total, "usable": usable, "redundant": redundant, "protected": redundant and usable == total,
            "members": [{"path_id": pid, "name": name, "priority": prio, "state": s, "usable": s in ("up", "degraded")}
                        for pid, name, prio, s in sorted(members, key=lambda m: (m[2], m[1]))]}


def chain_gaps(endpoint_a: str, endpoint_b: str, links: list[tuple[str, str]]) -> list[str]:
    """Problems with an ordered list of links (src device, dest device) as a route from A to B; empty when it is one
    unbroken route. Each link may be walked either way round. Legacy never checked this."""
    if not links:
        return []
    problems = []
    here = endpoint_a
    for position, (src, dest) in enumerate(links, 1):
        if here == src:
            here = dest
        elif here == dest:
            here = src
        else:
            problems.append(f"segment {position} does not start where the route has reached")
            here = dest
    if here != endpoint_b:
        problems.append("the route does not end at endpoint B")
    return problems
