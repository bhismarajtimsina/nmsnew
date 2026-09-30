"""Tracks this process's own locally connected WebSocket clients and their subscribed channel patterns, and fans
out a published event to whichever of them match - the same per-connection filtering
`WebSocketServerCommand::registerOnPipeMessageHandler` does per Swoole worker process. Every process that accepts
WebSocket connections runs its own `ConnectionManager` and its own Redis subscriber loop (app/realtime/bus.py); a
publish reaches every process, and each one only pushes to its own locally connected clients - exactly mirroring
the legacy design (a Redis-backed broadcast fanning into N independent worker processes) rather than a shortcut
that only works with a single process.

Kept free of any real transport: `send` is an injected async callable, not a bound WebSocket method, so the
dispatch logic (which connections match, in what order) is testable without opening a socket at all.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Awaitable, Callable

from app.realtime.permissions import channel_matches

Sender = Callable[[dict], Awaitable[None]]


@dataclass
class Connection:
    send: Sender
    permissions: frozenset[str]
    scope_all: bool
    channels: set[str] = field(default_factory=set)


class ConnectionManager:
    def __init__(self) -> None:
        self._connections: dict[int, Connection] = {}

    def add(self, key: int, conn: Connection) -> None:
        self._connections[key] = conn

    def remove(self, key: int) -> None:
        self._connections.pop(key, None)

    def get(self, key: int) -> Connection | None:
        return self._connections.get(key)

    def __len__(self) -> int:
        return len(self._connections)

    async def dispatch(self, name: str, data: dict) -> int:
        """Pushes one event to every locally connected client whose subscribed channels include a match. Returns
        how many received it."""
        delivered = 0
        for conn in list(self._connections.values()):
            if any(channel_matches(pattern, name) for pattern in conn.channels):
                await conn.send({"type": "event", "channel": name, "data": data})
                delivered += 1
        return delivered
