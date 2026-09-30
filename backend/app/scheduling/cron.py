"""Five-field cron expressions, evaluated in one declared time zone.

Supported: `*`, lists (`1,5,9`), ranges (`1-5`), steps (`*/15`, `10-40/5`, `5/10`), month and weekday names (`jan`, `mon`),
weekday 0 or 7 for Sunday, and the macros @hourly @daily @midnight @weekly @monthly @yearly @annually.

Day matching follows the traditional rule: when both day-of-month and day-of-week are restricted, a day matches if EITHER
does; when one of them is `*`, the other decides.

Daylight-saving: a wall-clock time that does not exist (the spring gap) is skipped, and one that occurs twice (the autumn
overlap) runs once, the first time. Use UTC (the default) to avoid the question.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

MACROS = {
    "@hourly": "0 * * * *", "@daily": "0 0 * * *", "@midnight": "0 0 * * *", "@weekly": "0 0 * * 0",
    "@monthly": "0 0 1 * *", "@yearly": "0 0 1 1 *", "@annually": "0 0 1 1 *",
}
MONTH_NAMES = {n: i for i, n in enumerate(["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"], 1)}
DAY_NAMES = {n: i for i, n in enumerate(["sun", "mon", "tue", "wed", "thu", "fri", "sat"])}
SEARCH_YEARS = 9


class CronError(ValueError):
    pass


@dataclass(frozen=True)
class CronExpr:
    source: str
    minutes: frozenset[int]
    hours: frozenset[int]
    days: frozenset[int]
    months: frozenset[int]
    weekdays: frozenset[int]  # 0 = Sunday .. 6 = Saturday
    days_star: bool
    weekdays_star: bool

    def day_matches(self, day: datetime) -> bool:
        in_dom, in_dow = day.day in self.days, ((day.weekday() + 1) % 7) in self.weekdays
        if self.days_star and self.weekdays_star:
            return True
        if self.days_star:
            return in_dow
        if self.weekdays_star:
            return in_dom
        return in_dom or in_dow


def _number(token: str, names: dict[str, int], low: int, high: int, what: str) -> int:
    value = names.get(token.lower()) if names else None
    if value is None:
        if not token.isdigit():
            raise CronError(f"{what}: {token!r} is not a number")
        value = int(token)
    if not low <= value <= high:
        raise CronError(f"{what}: {value} is outside {low}-{high}")
    return value


def _field(text: str, low: int, high: int, what: str, names: dict[str, int] | None = None) -> tuple[frozenset[int], bool]:
    values: set[int] = set()
    star = text == "*"
    for part in text.split(","):
        if not part:
            raise CronError(f"{what}: empty item")
        base, _, step_text = part.partition("/")
        step = 1
        if step_text:
            if not step_text.isdigit() or int(step_text) < 1:
                raise CronError(f"{what}: bad step {step_text!r}")
            step = int(step_text)
        if base == "*":
            start, end = low, high
        elif "-" in base:
            a, _, b = base.partition("-")
            start, end = _number(a, names or {}, low, high, what), _number(b, names or {}, low, high, what)
            if start > end:
                raise CronError(f"{what}: range {base} runs backwards")
        else:
            start = _number(base, names or {}, low, high, what)
            end = high if step_text else start
        values.update(range(start, end + 1, step))
    return frozenset(values), star and not step_text


def parse(expression: str) -> CronExpr:
    text = MACROS.get(expression.strip().lower(), expression).strip()
    parts = text.split()
    if len(parts) != 5:
        raise CronError("a schedule needs five fields: minute hour day-of-month month day-of-week")
    minutes, _ = _field(parts[0], 0, 59, "minute")
    hours, _ = _field(parts[1], 0, 23, "hour")
    days, days_star = _field(parts[2], 1, 31, "day of month")
    months, _ = _field(parts[3], 1, 12, "month", MONTH_NAMES)
    weekdays, weekdays_star = _field(parts[4], 0, 7, "day of week", DAY_NAMES)
    weekdays = frozenset(0 if d == 7 else d for d in weekdays)
    return CronExpr(expression, minutes, hours, days, months, weekdays, days_star, weekdays_star)


def next_after(expr: CronExpr, after: datetime, tz_name: str = "UTC") -> datetime:
    """The first moment strictly after `after` that matches, as an aware UTC datetime. Raises CronError if there is none."""
    tz = ZoneInfo(tz_name)
    local = after.astimezone(tz).replace(tzinfo=None, second=0, microsecond=0) + timedelta(minutes=1)
    limit = local + timedelta(days=366 * SEARCH_YEARS)
    while local < limit:
        if local.month not in expr.months:
            local = (local.replace(day=1, hour=0, minute=0) + timedelta(days=32)).replace(day=1)
            continue
        if not expr.day_matches(local):
            local = local.replace(hour=0, minute=0) + timedelta(days=1)
            continue
        if local.hour not in expr.hours:
            local = local.replace(minute=0) + timedelta(hours=1)
            continue
        if local.minute not in expr.minutes:
            local += timedelta(minutes=1)
            continue
        moment = local.replace(tzinfo=tz)
        utc = moment.astimezone(timezone.utc)
        if utc.astimezone(tz).replace(tzinfo=None) == local:  # false only in the spring gap: that time does not exist
            return utc
        local += timedelta(minutes=1)
    raise CronError("this schedule never fires")


def upcoming(expr: CronExpr, after: datetime, count: int, tz_name: str = "UTC") -> list[datetime]:
    out, cursor = [], after
    for _ in range(count):
        cursor = next_after(expr, cursor, tz_name)
        out.append(cursor)
    return out
