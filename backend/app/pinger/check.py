"""Per-device up/down debounce, decoupled from ICMP itself so it is fully testable without a socket.

Simplified from the legacy Go pinger's rolling window (`docker/icmp-pinger/pinger.config.yml`:
`number_of_inspection: 7`, `must_inspections_success_for_up: 3`, `must_inspections_failed_for_down: 5`) to a plain
consecutive-count debounce instead: N consecutive misses for down (default 3), one reply for up - the shape
own-components.md already specifies. A rolling window buys smoothing this system does not need: nothing here is as
loss-sensitive as the legacy binary's combined ICMP+TCP multi-protocol check, and a simpler, fully-tested state
machine is worth more than reproducing an undocumented tuning choice exactly.

Critically, this does *not* decide alarms. Read the real legacy pipeline before assuming otherwise: the Go pinger
reports each result to `components/Pinger/Controllers/Controller.php`, which only updates `c_pinger_statuses` and
sets a Prometheus gauge (`pinger_host_status`) - it never writes a `c_events` row itself. The actual alarm is
Prometheus scraping that gauge and Alertmanager firing the `pinger_host_down` rule
(`pinger_host_status <= 0` for 1m, already ported byte-for-byte in app/registry/alarm_rule_data.py), which reaches
this system exactly the way Plan 20's `/webhooks/alertmanager` already handles any other Alertmanager rule. This
worker's only jobs are: ping, debounce, record status and history, and expose that same-named gauge - keeping the
legacy-ported alarm rule's PromQL working unchanged is why the gauge is NOT `cybersathy_`-prefixed (D-26's usual
naming rule has this one deliberate exception, noted in decisions.md).
"""
from __future__ import annotations

from dataclasses import dataclass

Status = str  # "unknown" | "up" | "down"


@dataclass(frozen=True)
class PingState:
    status: Status = "unknown"
    consecutive_misses: int = 0


def apply_result(state: PingState, *, alive: bool, misses_for_down: int) -> tuple[PingState, bool]:
    """One reply means up immediately; `misses_for_down` consecutive misses are needed to call it down.
    Returns the new state and whether this result is a real transition worth recording."""
    if misses_for_down < 1:
        raise ValueError("misses_for_down must be at least 1")
    if alive:
        new = PingState(status="up", consecutive_misses=0)
    else:
        misses = state.consecutive_misses + 1
        new_status = "down" if misses >= misses_for_down else state.status
        new = PingState(status=new_status, consecutive_misses=misses)
    return new, new.status != state.status
