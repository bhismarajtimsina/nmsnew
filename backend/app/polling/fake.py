"""A scripted SNMP transport for tests. It never opens a socket."""
from __future__ import annotations

from typing import Any, Sequence

from app.polling.transport import Target, TransportError, TransportTimeout, VarBind


class FakeTransport:
    """Answers from a script keyed by device address. Records every request so tests can assert exactly what was asked."""

    def __init__(self) -> None:
        self.gets: dict[str, dict[str, Any]] = {}
        self.tables: dict[tuple[str, str], list[tuple[str, Any]]] = {}
        self.timeouts: set[str] = set()
        self.errors: dict[str, str] = {}
        self.ignore_walk_limit = False
        self.calls: list[tuple[str, str, tuple[str, ...]]] = []
        self.seen_communities: list[str | None] = []
        self.delay = 0.0
        self.walk_limits: list[tuple[str, str, int, int]] = []   # (address, root, max_rows, timeout_ms)
        self.get_limits: list[tuple[str, int, int]] = []          # (address, timeout_ms, retries)
        self.sets: list[tuple[str, tuple[tuple[str, str, Any], ...]]] = []  # (address, ((oid, type, value), ...))
        self.refuse_sets: dict[str, str] = {}   # address -> error the device returns for any set
        self.ignore_sets: set[str] = set()      # addresses that accept a set but do not apply it

    def script_get(self, address: str, values: dict[str, Any]) -> None:
        self.gets.setdefault(address, {}).update(values)

    def script_table(self, address: str, root: str, rows: list[tuple[str, Any]]) -> None:
        self.tables[(address, root)] = rows

    async def _pause(self) -> None:
        if self.delay:
            import asyncio

            await asyncio.sleep(self.delay)

    def _fail(self, target: Target) -> None:
        if target.address in self.timeouts:
            raise TransportTimeout(f"no response from {target.address}")
        if target.address in self.errors:
            raise TransportError(self.errors[target.address])

    async def get(self, target: Target, oids: Sequence[str], *, timeout_ms: int, retries: int) -> dict[str, Any]:
        self.calls.append(("get", target.address, tuple(oids)))
        self.get_limits.append((target.address, timeout_ms, retries))
        self.seen_communities.append(target.credentials.community)
        await self._pause()
        self._fail(target)
        known = self.gets.get(target.address, {})
        return {oid: known[oid] for oid in oids if oid in known}

    async def walk(self, target: Target, root_oid: str, *, max_rows: int, timeout_ms: int, retries: int) -> list[tuple[str, Any]]:
        self.calls.append(("walk", target.address, (root_oid,)))
        self.walk_limits.append((target.address, root_oid, max_rows, timeout_ms))
        self.seen_communities.append(target.credentials.community)
        await self._pause()
        self._fail(target)
        rows = list(self.tables.get((target.address, root_oid), []))
        return rows if self.ignore_walk_limit else rows[:max_rows]

    async def set(self, target: Target, varbinds: Sequence[VarBind], *, timeout_ms: int) -> dict[str, Any]:
        self.calls.append(("set", target.address, tuple(vb.oid for vb in varbinds)))
        self.sets.append((target.address, tuple((vb.oid, vb.type, vb.value) for vb in varbinds)))
        self.seen_communities.append(target.credentials.community)
        await self._pause()
        self._fail(target)
        if target.address in self.refuse_sets:
            raise TransportError(self.refuse_sets[target.address])
        if target.address not in self.ignore_sets:
            self.gets.setdefault(target.address, {}).update({vb.oid: vb.value for vb in varbinds})
        return {vb.oid: vb.value for vb in varbinds}
