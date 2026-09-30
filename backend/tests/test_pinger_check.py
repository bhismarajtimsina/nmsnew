"""Pure up/down debounce logic: no ICMP, no database. See app/pinger/check.py's docstring for why this worker does
not decide alarms itself - Alertmanager already does, from the gauge this worker exposes."""
import pytest

from app.pinger.check import PingState, apply_result


def test_a_single_reply_is_immediately_up_from_unknown():
    state, changed = apply_result(PingState(), alive=True, misses_for_down=3)
    assert state.status == "up" and state.consecutive_misses == 0 and changed is True


def test_a_single_miss_from_unknown_does_not_flip_to_down_below_the_threshold():
    state, changed = apply_result(PingState(), alive=False, misses_for_down=3)
    assert state.status == "unknown" and state.consecutive_misses == 1 and changed is False


def test_down_only_after_the_configured_consecutive_misses():
    state = PingState(status="up")
    for i in range(1, 3):
        state, changed = apply_result(state, alive=False, misses_for_down=3)
        assert state.status == "up" and changed is False, i
    state, changed = apply_result(state, alive=False, misses_for_down=3)
    assert state.status == "down" and changed is True


def test_a_single_reply_recovers_from_down_immediately():
    state = PingState(status="down", consecutive_misses=5)
    state, changed = apply_result(state, alive=True, misses_for_down=3)
    assert state.status == "up" and state.consecutive_misses == 0 and changed is True


def test_repeated_misses_once_already_down_are_not_reported_as_new_transitions():
    state = PingState(status="down", consecutive_misses=5)
    state, changed = apply_result(state, alive=False, misses_for_down=3)
    assert state.status == "down" and changed is False


def test_repeated_replies_once_already_up_are_not_reported_as_new_transitions():
    state = PingState(status="up")
    state, changed = apply_result(state, alive=True, misses_for_down=3)
    assert state.status == "up" and changed is False


def test_the_threshold_is_configurable_per_call():
    state, changed = apply_result(PingState(status="up"), alive=False, misses_for_down=1)
    assert state.status == "down" and changed is True


def test_an_invalid_threshold_is_rejected():
    with pytest.raises(ValueError):
        apply_result(PingState(), alive=True, misses_for_down=0)
