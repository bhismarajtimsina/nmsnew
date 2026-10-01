"""What every vendor module normalizes onto (Plans 15 to 19)."""
from __future__ import annotations

from dataclasses import dataclass

# The common alarm reasons every vendor's down cause maps to. Kept short on purpose: an operator acts on these, not on
# a vendor's twenty variants.
COMMON_REASONS = (
    "los",             # fibre / optical signal lost
    "power_off",       # dying gasp: the ONT lost power
    "loki",            # loss of key synchronisation
    "auth_fail",       # registration / authentication refused
    "signal_failure",  # frame, PLOAM, acknowledge or signal failures on the link
    "admin_action",    # deactivated, reset or re-registered on purpose
    "rogue_ont",
    "deleted",
    "none",            # no error
    "unknown",
)


@dataclass(frozen=True)
class DownReason:
    code: int
    label: str   # the vendor's own word, as legacy shows it
    reason: str  # one of COMMON_REASONS


def lookup_reason(tables: dict[str, dict[int, tuple[str, str]]], table: str, code: int | None) -> DownReason | None:
    """A raw code to the vendor's label and the common reason. An unlisted code is "unknown", never dropped, so a
    firmware that adds codes still raises an alarm with the raw code visible."""
    if code is None:
        return None
    label, reason = tables[table].get(code, (f"Code {code}", "unknown"))
    return DownReason(code, label, reason)
