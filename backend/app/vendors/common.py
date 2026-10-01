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


@dataclass(frozen=True)
class TextReason:
    text: str    # exactly what the OLT returned
    label: str   # the vendor's own word, as legacy shows it
    reason: str  # one of COMMON_REASONS


def lookup_text_reason(table: dict[str, tuple[str, str]], text: str | None) -> TextReason | None:
    """For OLTs that report a down reason as text rather than a code. Matched after trimming, case kept (legacy's maps
    are case-sensitive and "LOS" and "losi" mean different things). Unlisted text is "unknown" with the text visible."""
    if text is None:
        return None
    key = text.strip()
    label, reason = table.get(key, (key, "unknown"))
    return TextReason(key, label, reason)


def parse_number(raw: object) -> float | None:
    """A reading some OLTs return as a string ("-19.10", " 3.3 "). Not a number, empty, NaN or infinite is no
    reading, never 0."""
    if raw is None or isinstance(raw, bool):
        return None
    try:
        value = float(str(raw).strip())
    except ValueError:
        return None
    return value if value == value and value not in (float("inf"), float("-inf")) else None
