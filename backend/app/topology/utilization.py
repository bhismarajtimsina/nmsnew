"""Link utilisation (Plan 27): how busy each link is, from the interface traffic counters the interface_basic profile
polls. Legacy recomputed this every two minutes and exported `link_utilization_prc` for its `high_link_utilization`
alarm; the rules here follow legacy's choices except where noted, and are pure functions so they are tested without a
database.

- **Rate:** octets counted over the period (legacy's `LINKS_UTILIZATION_CALCULATE_PERIOD`, 15 minutes by default),
  as bits per second. A counter that goes down was reset: the device restarted, the counters were cleared, or a
  32-bit counter wrapped. As Prometheus' `rate()` does (legacy's own query), the drop counts from zero, so a reset
  reads as less traffic, never as a burst that could raise a false alarm.
- **Percent:** bits per second over the interface's speed. Legacy divided binary megabits (`/1024/1024*8`) by a speed
  in "megabits" where 1G meant 1024, which read about 7% low on gigabit ports and 5% high on 100M ones; here both
  are plain bits per second.
- **Busiest direction:** a link's utilisation is its busiest direction at any end it measures. Legacy took the source
  end and used the destination only when the source had nothing; both ends carry the same traffic, so the highest
  is the same figure when both are right, and the useful one when one end's counters are stale.
"""
from __future__ import annotations

from typing import Any, Iterable

COUNTER_COLUMNS = {"interface.if_in_octets": "in", "interface.if_out_octets": "out"}
SPEED_COLUMN = "interface.if_speed"
# ifSpeed is a Gauge32: an interface faster than it can hold reports the maximum, and its real speed is in ifHighSpeed,
# which no profile reads yet. A speed at the maximum is unknown, not 4.29 Gbit/s.
IF_SPEED_MAX = 2**32 - 1


def _index(column_oid: str, oid: str) -> int | None:
    """The ifIndex an interface-table row is for: the one component after the column's own OID."""
    prefix = column_oid + "."
    if not oid.startswith(prefix):
        return None
    rest = oid[len(prefix):]
    return int(rest) if rest.isdigit() else None


def _count(value: Any) -> int | None:
    return value if isinstance(value, int) and not isinstance(value, bool) and value >= 0 else None


def samples_from(readings: Iterable[Any]) -> dict[int, dict[str, int | None]]:
    """One sample per ifIndex from a poll's readings: in and out octets, and the speed where it is known."""
    out: dict[int, dict[str, int | None]] = {}
    for r in readings:
        key = COUNTER_COLUMNS.get(r.name) or ("speed" if r.name == SPEED_COLUMN else None)
        if key is None or not isinstance(r.value, list):
            continue
        for oid, value in r.value:
            index, count = _index(r.oid, oid), _count(value)
            if index is None or count is None:
                continue
            if key == "speed" and not 0 < count < IF_SPEED_MAX:
                continue
            out.setdefault(index, {"in": None, "out": None, "speed": None})[key] = count
    return {i: s for i, s in out.items() if s["in"] is not None or s["out"] is not None}


def counter_rate(points: list[tuple[float, int]]) -> float | None:
    """Bits per second over (seconds, octets) points, oldest first; None without two points a moment apart."""
    elapsed = points[-1][0] - points[0][0] if points else 0
    if elapsed <= 0:
        return None
    total = 0
    for (_, previous), (_, current) in zip(points, points[1:]):
        total += current - previous if current >= previous else current
    return total * 8 / elapsed


def link_utilization(ends: list[dict[str, Any]]) -> dict[str, Any] | None:
    """The busiest direction over the measured ends, each {"side", "in_bps", "out_bps", "speed_bps"}. Where no end
    has a speed the percent is unknown and the busiest rate is reported alone. None when nothing was measured."""
    best: tuple[float, float, str, str, int | None] | None = None
    for end in ends:
        speed = end.get("speed_bps")
        for direction in ("in", "out"):
            bps = end.get(f"{direction}_bps")
            if bps is None:
                continue
            percent = bps / speed * 100 if speed else -1.0
            if best is None or (percent, bps) > (best[0], best[1]):
                best = (percent, bps, end["side"], direction, speed)
    if best is None:
        return None
    percent, bps, side, direction, speed = best
    return {"percent": round(percent, 1) if percent >= 0 else None, "mbps": round(bps / 1e6, 3),
            "speed_mbps": round(speed / 1e6, 3) if speed else None, "side": side, "direction": direction}


_METRICS = (
    ("link_utilization_prc", "Link utilization (%)", "percent"),
    ("link_utilization_mbps", "Link utilization Mbps", "mbps"),
    ("link_utilization_speed", "Link speed", "speed_mbps"),
)


def render_metrics(links: list[dict[str, Any]], utilization: dict[str, dict[str, Any] | None]) -> str:
    """legacy's names and labels, so the ported `high_link_utilization` and `link_down` rules work unchanged. `links`
    are presented links (app/topology/links.py) with every end visible. A utilisation figure is exported only where it
    is known. `link_status` is 1 for an up link and 0 for a down one; legacy also wrote 0 for any link without an Up
    interface at both ends, so a device-to-device link raised `link_down` forever. Here a link whose state is unknown
    exports no status, and a device-level link follows its devices' ping."""
    from app.topology.path_service import _line

    if not links:
        return ""
    lines: dict[str, list[str]] = {name: [] for name, _, _ in _METRICS}
    lines["link_status"] = []
    for link in links:
        labels = {"link_id": link["id"]}
        for side in ("src", "dest"):
            end = link[side]
            labels |= {f"{side}_device_id": end["device_id"], f"{side}_device_ip": end["ip"] or "",
                       f"{side}_device_name": end["name"], f"{side}_iface_name": end["interface"] or "N/A"}
        figures = utilization.get(str(link["id"])) or {}
        for name, _, key in _METRICS:
            if figures.get(key) is not None:
                lines[name].append(_line(name, labels, figures[key]))
        if link["state"] in ("up", "down"):
            lines["link_status"].append(_line("link_status", labels, 1 if link["state"] == "up" else 0))
    helps = {name: text for name, text, _ in _METRICS} | {"link_status": "Link status, 1 up and 0 down"}
    out: list[str] = []
    for name, body in lines.items():
        if body:
            out += [f"# HELP {name} {helps[name]}", f"# TYPE {name} gauge", *body]
    return "\n".join(out) + "\n" if out else ""
