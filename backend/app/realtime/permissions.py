"""Per-channel WebSocket subscribe authorization, ported from the real `WebSocketServerCommand::isSubscribeAllowed`
and `config/ws-permissions.yml`. Pure: glob-pattern matching and a permission-set intersection - no network, no
database.

The real algorithm is first-match-wins, not "any matching rule allows it": the first rule whose pattern list matches
the channel decides the outcome - allow if the caller holds at least one of its required permissions, deny
immediately otherwise, without considering any later rule that might also match the same channel. A channel matched
by no rule at all is denied - closed by default, the legacy comment's own words, because some channels carry other
users' data, not just the caller's own.

A wildcard channel pattern from a caller whose role does not see everything is always rejected, regardless of which
permission it would otherwise need - ported from the same function's own rule (the legacy comment calls it "not
redesigning this check's own wildcard-ownership semantics, just making it not crash" - a narrow, deliberately
un-elegant safety rule kept exactly as-is rather than redesigned here either): a scoped caller may only subscribe to
a fully-qualified channel name, never a pattern containing "*".

The specific channel-to-permission mapping below is authored fresh for this system's own feature set (events,
devices, interfaces, traps, notifications) - `config/ws-permissions.yml` maps a different set of legacy components
(macros, links, switcher-core action logs) this system does not have. What is ported exactly is the algorithm and
the default-deny, first-match-wins, wildcard-needs-scope-all shape of it.
"""
from __future__ import annotations

import re
from dataclasses import dataclass


@dataclass(frozen=True)
class ChannelRule:
    patterns: tuple[str, ...]
    permissions: tuple[str, ...] = ()  # empty = public, no permission needed


# Every channel this system publishes today. A channel string always has the shape "<feature>.<event>", e.g.
# "events.created", "devices.updated" - never a bare "*" or a feature prefix with nothing after it.
CHANNEL_RULES: list[ChannelRule] = [
    ChannelRule(patterns=("events.*", "incidents.*"), permissions=("events.view",)),
    ChannelRule(patterns=("devices.*",), permissions=("devices.view",)),
    ChannelRule(patterns=("interfaces.*",), permissions=("interfaces.view",)),
    ChannelRule(patterns=("traps.*", "pinger.*"), permissions=("traps.view",)),
    ChannelRule(patterns=("notifications.*",), permissions=("notifications.history.view",)),
]


def channel_matches(pattern: str, channel: str) -> bool:
    """A subscribed pattern (possibly containing `*`) matching a fully-qualified event name - also used by
    app/realtime/manager.py to decide which connections a published event fans out to, the same glob shape
    `WebSocketServerCommand::registerOnPipeMessageHandler` matches an incoming event against each connection's own
    subscribed patterns with."""
    regex = "^" + re.escape(pattern).replace(r"\*", ".*") + "$"
    return re.match(regex, channel) is not None


def is_subscribe_allowed(
    rules: list[ChannelRule], *, channel: str, user_permissions: frozenset[str], scope_all: bool,
) -> bool:
    if not scope_all and "*" in channel:
        return False
    for rule in rules:
        if not any(channel_matches(pattern, channel) for pattern in rule.patterns):
            continue
        if not rule.permissions:
            return True
        return bool(set(rule.permissions) & user_permissions)
    return False
