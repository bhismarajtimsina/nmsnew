"""Unit coverage of ConnectionManager.dispatch - the local fan-out filter every process runs its own copy of. No
transport, no Redis: `send` is a plain async callable collecting what it was given."""
from __future__ import annotations

from app.realtime.manager import Connection, ConnectionManager


def _collector() -> tuple[list[dict], object]:
    received: list[dict] = []

    async def send(message: dict) -> None:
        received.append(message)

    return received, send


async def test_dispatch_delivers_to_a_matching_subscriber():
    manager = ConnectionManager()
    received, send = _collector()
    manager.add(1, Connection(send=send, permissions=frozenset(), scope_all=False, channels={"events.*"}))

    delivered = await manager.dispatch("events.created", {"id": "1"})

    assert delivered == 1
    assert received == [{"type": "event", "channel": "events.created", "data": {"id": "1"}}]


async def test_dispatch_skips_a_non_matching_subscriber():
    manager = ConnectionManager()
    received, send = _collector()
    manager.add(1, Connection(send=send, permissions=frozenset(), scope_all=False, channels={"devices.*"}))

    delivered = await manager.dispatch("events.created", {})

    assert delivered == 0
    assert received == []


async def test_dispatch_reaches_every_matching_connection_and_counts_them():
    manager = ConnectionManager()
    received1, send1 = _collector()
    received2, send2 = _collector()
    manager.add(1, Connection(send=send1, permissions=frozenset(), scope_all=False, channels={"events.*"}))
    manager.add(2, Connection(send=send2, permissions=frozenset(), scope_all=False, channels={"events.created"}))

    delivered = await manager.dispatch("events.created", {})

    assert delivered == 2 and len(received1) == 1 and len(received2) == 1


def test_add_get_remove_and_len():
    manager = ConnectionManager()
    received, send = _collector()
    conn = Connection(send=send, permissions=frozenset(), scope_all=False)

    manager.add(1, conn)
    assert manager.get(1) is conn and len(manager) == 1

    manager.remove(1)
    assert manager.get(1) is None and len(manager) == 0

    manager.remove(1)  # removing an already-absent key is a no-op, not an error


def test_a_new_connection_starts_with_no_subscribed_channels():
    _, send = _collector()
    assert Connection(send=send, permissions=frozenset(), scope_all=False).channels == set()
