"""Link state from what the NMS already measures (Plan 27): each end's interface status where the link names an
interface, the device's ping status otherwise, and always the ping status first, since an unreachable device's
interface counters are stale. Pure functions over plain values, so the rules are tested without a database.

A link is down when either end is down, unknown when either end is unmeasured, and up only when both ends are up:
never claim a link is up while part of it is unmeasured (the rule legacy's path calculator uses for paths).
"""
from __future__ import annotations

from typing import Literal

State = Literal["up", "down", "unknown"]

# ifOperStatus values (RFC 2863) as stored in interfaces.oper_status.
_IFACE = {"up": "up", "down": "down", "lowerLayerDown": "down", "notPresent": "down",
          "testing": "unknown", "dormant": "unknown", "unknown": "unknown"}


def end_state(*, ping: str | None, interface_bound: bool, oper_status: str | None, admin_status: str | None) -> State:
    if ping == "down":
        return "down"
    if interface_bound:
        if admin_status == "down":
            return "down"  # administratively shut: nothing passes, whatever the device reports for oper
        return _IFACE.get(oper_status or "unknown", "unknown")  # type: ignore[return-value]
    return "up" if ping == "up" else "unknown"


def link_state(a: State, b: State) -> State:
    if "down" in (a, b):
        return "down"
    if "unknown" in (a, b):
        return "unknown"
    return "up"
