from datetime import datetime, timedelta, timezone

import pytest

from app.scheduling.cron import CronError, next_after, parse, upcoming


def u(*args):
    return datetime(*args, tzinfo=timezone.utc)


@pytest.mark.parametrize("expr,after,expected", [
    ("*/15 * * * *", u(2026, 9, 28, 10, 7), u(2026, 9, 28, 10, 15)),
    ("*/15 * * * *", u(2026, 9, 28, 10, 15), u(2026, 9, 28, 10, 30)),            # strictly after, never the same minute
    ("*/15 * * * *", u(2026, 9, 28, 10, 15, 30), u(2026, 9, 28, 10, 30)),        # seconds are ignored
    ("*/15 * * * *", u(2026, 9, 28, 10, 45), u(2026, 9, 28, 11, 0)),
    ("0 3 * * *", u(2026, 9, 28, 10, 7), u(2026, 9, 29, 3, 0)),
    ("0 3 * * *", u(2026, 9, 28, 2, 59), u(2026, 9, 28, 3, 0)),
    ("30 2 * * 1", u(2026, 9, 28, 10, 0), u(2026, 10, 5, 2, 30)),                # 2026-09-28 is a Monday
    ("0 0 1 * *", u(2026, 9, 28, 10, 0), u(2026, 10, 1, 0, 0)),
    ("0 0 31 * *", u(2026, 9, 28, 10, 0), u(2026, 10, 31, 0, 0)),                # September has no 31st
    ("0 0 29 2 *", u(2026, 1, 1, 0, 0), u(2028, 2, 29, 0, 0)),                   # only leap years
    ("0 9 * jan mon-fri", u(2026, 9, 28, 0, 0), u(2027, 1, 1, 9, 0)),           # names, and 2027-01-01 is a Friday
    ("0 0 * * 0", u(2026, 9, 28, 0, 0), u(2026, 10, 4, 0, 0)),
    ("0 0 * * 7", u(2026, 9, 28, 0, 0), u(2026, 10, 4, 0, 0)),                   # 7 is Sunday too
    ("10-40/10 * * * *", u(2026, 9, 28, 10, 0), u(2026, 9, 28, 10, 10)),
    ("5/20 * * * *", u(2026, 9, 28, 10, 6), u(2026, 9, 28, 10, 25)),
    ("1,15,45 * * * *", u(2026, 9, 28, 10, 20), u(2026, 9, 28, 10, 45)),
    ("@hourly", u(2026, 9, 28, 10, 20), u(2026, 9, 28, 11, 0)),
    ("@daily", u(2026, 9, 28, 10, 20), u(2026, 9, 29, 0, 0)),
    ("@weekly", u(2026, 9, 28, 10, 20), u(2026, 10, 4, 0, 0)),
    ("@monthly", u(2026, 9, 28, 10, 20), u(2026, 10, 1, 0, 0)),
    ("@yearly", u(2026, 9, 28, 10, 20), u(2027, 1, 1, 0, 0)),
    ("0 0 31 12 *", u(2026, 12, 31, 0, 0), u(2027, 12, 31, 0, 0)),               # across a year boundary
])
def test_next_run_matches_known_answers(expr, after, expected):
    assert next_after(parse(expr), after) == expected


def test_day_of_month_and_day_of_week_combine_with_or_when_both_are_restricted():
    # the 13th, or any Friday. From Monday 28 Sep 2026 the first match is Friday 2 Oct, not the 13th.
    assert next_after(parse("0 0 13 * 5"), u(2026, 9, 28, 0, 0)) == u(2026, 10, 2, 0, 0)
    assert next_after(parse("0 0 13 * 5"), u(2026, 10, 2, 0, 0)) == u(2026, 10, 9, 0, 0)
    assert next_after(parse("0 0 13 * 5"), u(2026, 10, 9, 0, 0)) == u(2026, 10, 13, 0, 0)
    # with * in one of them the other alone decides
    assert next_after(parse("0 0 13 * *"), u(2026, 9, 28, 0, 0)) == u(2026, 10, 13, 0, 0)
    assert next_after(parse("0 0 * * 5"), u(2026, 9, 28, 0, 0)) == u(2026, 10, 2, 0, 0)


def test_upcoming_lists_increasing_moments():
    moments = upcoming(parse("*/10 * * * *"), u(2026, 9, 28, 10, 0), 4)
    assert moments == [u(2026, 9, 28, 10, 10), u(2026, 9, 28, 10, 20), u(2026, 9, 28, 10, 30), u(2026, 9, 28, 10, 40)]


@pytest.mark.parametrize("bad", ["", "* * * *", "* * * * * *", "60 * * * *", "* 24 * * *", "* * 0 * *", "* * 32 * *", "* * * 13 *", "* * * * 8",
                                 "5-1 * * * *", "*/0 * * * *", "*/x * * * *", "1,,2 * * * *", "a * * * *", "* * * foo *", "@sometimes", "-1 * * * *"])
def test_invalid_expressions_are_refused_with_a_reason(bad):
    with pytest.raises(CronError):
        parse(bad)


@pytest.mark.parametrize("never", ["0 0 31 2 *", "0 0 30 2 *", "0 0 31 4 *"])
def test_a_schedule_that_can_never_fire_is_reported(never):
    with pytest.raises(CronError):
        next_after(parse(never), u(2026, 1, 1, 0, 0))


def test_a_time_zone_with_an_odd_offset_is_honoured():
    # Nepal is UTC+05:45. 03:00 there is 21:15 UTC the evening before.
    assert next_after(parse("0 3 * * *"), u(2026, 9, 28, 0, 0), "Asia/Kathmandu") == u(2026, 9, 28, 21, 15)
    assert next_after(parse("0 3 * * *"), u(2026, 9, 28, 21, 15), "Asia/Kathmandu") == u(2026, 9, 29, 21, 15)


def test_daylight_saving_gap_is_skipped_and_the_overlap_runs_once():
    berlin = "Europe/Berlin"
    # 2026-03-29: clocks go 02:00 -> 03:00, so 02:30 does not exist that day.
    assert next_after(parse("30 2 * * *"), u(2026, 3, 28, 12, 0), berlin) == u(2026, 3, 28, 1, 30) + timedelta(days=2) - timedelta(hours=1)
    assert next_after(parse("30 2 * * *"), u(2026, 3, 28, 12, 0), berlin) == u(2026, 3, 30, 0, 30)
    # 2026-10-25: clocks go 03:00 -> 02:00, so 02:30 happens twice. It must run only the first time.
    first = next_after(parse("30 2 * * *"), u(2026, 10, 24, 22, 0), berlin)
    assert first == u(2026, 10, 25, 0, 30)
    assert next_after(parse("30 2 * * *"), first, berlin) == u(2026, 10, 26, 1, 30)
