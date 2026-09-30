"""Pure WebSocket channel-subscribe authorization: no network, no database. Ported from the real
WebSocketServerCommand::isSubscribeAllowed - see app/realtime/permissions.py's docstring for the two non-obvious
real rules this pins down (first-match-wins, not "any matching rule allows it"; a scoped caller can never use a
wildcard, no matter what permission it would otherwise need)."""
import pytest

from app.realtime.permissions import ChannelRule, is_subscribe_allowed

RULES = [
    ChannelRule(patterns=("events.*",), permissions=("events.view",)),
    ChannelRule(patterns=("devices.*",), permissions=("devices.view",)),
    ChannelRule(patterns=("public.*",)),  # no permissions listed = public
]


def test_a_holder_of_the_required_permission_is_allowed():
    assert is_subscribe_allowed(RULES, channel="events.created", user_permissions=frozenset({"events.view"}), scope_all=True) is True


def test_a_non_holder_is_denied():
    assert is_subscribe_allowed(RULES, channel="events.created", user_permissions=frozenset({"devices.view"}), scope_all=True) is False


def test_a_channel_matched_by_no_rule_at_all_is_denied():
    assert is_subscribe_allowed(RULES, channel="totally.unknown", user_permissions=frozenset({"events.view", "devices.view"}), scope_all=True) is False


def test_a_rule_with_no_listed_permission_is_public():
    assert is_subscribe_allowed(RULES, channel="public.announcement", user_permissions=frozenset(), scope_all=True) is True


def test_the_first_matching_rule_wins_even_if_a_later_rule_would_also_match():
    # Two rules for the same channel: the first one (denying, since the caller lacks its permission) must win, not
    # fall through to the second (which the caller *does* satisfy) - this is the exact real algorithm, not an OR.
    rules = [
        ChannelRule(patterns=("events.*",), permissions=("events.view",)),
        ChannelRule(patterns=("events.*",), permissions=("notifications.history.view",)),
    ]
    allowed = is_subscribe_allowed(rules, channel="events.created", user_permissions=frozenset({"notifications.history.view"}), scope_all=True)
    assert allowed is False


def test_a_scope_all_caller_may_use_a_wildcard():
    assert is_subscribe_allowed(RULES, channel="events.*", user_permissions=frozenset({"events.view"}), scope_all=True) is True


def test_a_scoped_caller_is_rejected_for_any_wildcard_even_with_the_right_permission():
    assert is_subscribe_allowed(RULES, channel="events.*", user_permissions=frozenset({"events.view"}), scope_all=False) is False


def test_a_scoped_caller_can_still_use_a_fully_qualified_channel_name():
    assert is_subscribe_allowed(RULES, channel="events.created", user_permissions=frozenset({"events.view"}), scope_all=False) is True


@pytest.mark.parametrize("channel", ["notevents.created", "devevents.created", ""])
def test_unrelated_names_do_not_match_a_prefix_pattern(channel):
    assert is_subscribe_allowed(RULES, channel=channel, user_permissions=frozenset({"events.view"}), scope_all=True) is False


def test_a_dot_after_the_prefix_is_required_by_the_pattern_but_anything_after_that_matches():
    # "events.*" -> regex events\..* : a further-nested name still matches, since * matches literal dots too -
    # this is the real legacy glob behavior (str_replace('\*', '.*', ...)), not a namespaced/segment-aware match.
    assert is_subscribe_allowed(RULES, channel="events.created.extra", user_permissions=frozenset({"events.view"}), scope_all=True) is True
