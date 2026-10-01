"""How the engine talks to a device, and the limits that apply whatever the implementation.

`SnmpTransport` is the only door to a device. This build has no real implementation: `DisabledTransport` refuses everything,
and it is what the workers are wired to. Choosing and wiring a real one is decision D-17, to be made by a spike against
recorded fixtures and a simulator, never against a device.

`BoundedTransport` wraps any transport and enforces the rules the plans require: every walk has a row limit and a timeout,
a response longer than the limit is cut and flagged (one probe row past the limit is asked for, so a table larger than
the limit is told apart from one that fits it exactly), timeouts are clamped, and a single request cannot ask for more than a fixed
number of OIDs.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Protocol, Sequence

MAX_GET_OIDS = 64
MAX_ROWS_HARD = 5000
MIN_TIMEOUT_MS, MAX_TIMEOUT_MS = 200, 30_000
MAX_RETRIES = 3


class TransportError(Exception):
    pass


class TransportTimeout(TransportError):
    pass


class TransportDisabled(TransportError):
    pass


class UnboundedRequest(TransportError):
    """A request that would have no row limit or no timeout. Raised before anything is sent."""


@dataclass(frozen=True)
class Credentials:
    version: str
    community: str | None = field(default=None, repr=False)
    v3_username: str | None = None
    v3_auth_protocol: str | None = None
    v3_auth_secret: str | None = field(default=None, repr=False)
    v3_priv_protocol: str | None = None
    v3_priv_secret: str | None = field(default=None, repr=False)

    def secrets(self) -> list[str]:
        return [s for s in (self.community, self.v3_auth_secret, self.v3_priv_secret) if s]


@dataclass(frozen=True)
class Target:
    address: str
    credentials: Credentials = field(repr=False)
    port: int = 161


class SnmpTransport(Protocol):
    async def get(self, target: Target, oids: Sequence[str], *, timeout_ms: int, retries: int) -> dict[str, Any]: ...

    async def walk(self, target: Target, root_oid: str, *, max_rows: int, timeout_ms: int, retries: int) -> list[tuple[str, Any]]: ...


class DisabledTransport:
    """The production default. Nothing ever leaves the process."""

    async def get(self, target: Target, oids: Sequence[str], *, timeout_ms: int, retries: int) -> dict[str, Any]:
        raise TransportDisabled("the SNMP transport is disabled in this build")

    async def walk(self, target: Target, root_oid: str, *, max_rows: int, timeout_ms: int, retries: int) -> list[tuple[str, Any]]:
        raise TransportDisabled("the SNMP transport is disabled in this build")


@dataclass
class RequestRecord:
    kind: str
    address: str
    oids: tuple[str, ...]
    max_rows: int | None = None
    timeout_ms: int = 0


class BoundedTransport:
    def __init__(self, inner: SnmpTransport) -> None:
        self.inner = inner
        self.requests: list[RequestRecord] = []
        self.rows_returned = 0
        self.truncated = False
        self.truncated_roots: list[str] = []

    @staticmethod
    def _timeout(value: int) -> int:
        return max(MIN_TIMEOUT_MS, min(int(value), MAX_TIMEOUT_MS))

    async def get(self, target: Target, oids: Sequence[str], *, timeout_ms: int, retries: int) -> dict[str, Any]:
        if not oids:
            raise UnboundedRequest("a get needs at least one OID")
        if len(oids) > MAX_GET_OIDS:
            raise UnboundedRequest(f"a single get may name at most {MAX_GET_OIDS} OIDs")
        timeout = self._timeout(timeout_ms)
        self.requests.append(RequestRecord("get", target.address, tuple(oids), None, timeout))
        result = await self.inner.get(target, list(oids), timeout_ms=timeout, retries=max(0, min(retries, MAX_RETRIES)))
        self.rows_returned += len(result)
        return result

    async def walk(self, target: Target, root_oid: str, *, max_rows: int | None, timeout_ms: int | None, retries: int,
                   probe: bool = True) -> list[tuple[str, Any]]:
        """probe=False is for a read that wants only the first rows by design (a getnext): no probe row is asked for, so
        stopping at max_rows is not reported as truncation."""
        if max_rows is None or timeout_ms is None:
            raise UnboundedRequest("a walk needs both max_rows and timeout_ms")
        if not 1 <= max_rows <= MAX_ROWS_HARD:
            raise UnboundedRequest(f"max_rows must be between 1 and {MAX_ROWS_HARD}")
        timeout = self._timeout(timeout_ms)
        self.requests.append(RequestRecord("walk", target.address, (root_oid,), max_rows, timeout))
        # One probe row past the limit: getting it back is how a table larger than the limit is known to be cut. Without
        # it, a well-behaved transport stopping at exactly max_rows would make a cut table look complete.
        rows = await self.inner.walk(target, root_oid, max_rows=max_rows + 1 if probe else max_rows, timeout_ms=timeout, retries=max(0, min(retries, MAX_RETRIES)))
        if len(rows) > max_rows:  # also stops a transport that ignores the limit from flooding the engine
            rows, self.truncated = rows[:max_rows], True
            self.truncated_roots.append(root_oid)
        self.rows_returned += len(rows)
        return rows
