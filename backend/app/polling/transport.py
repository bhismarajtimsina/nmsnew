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

import re
from dataclasses import dataclass, field, replace
from typing import Any, Literal, Protocol, Sequence

MAX_GET_OIDS = 64
MAX_ROWS_HARD = 5000
MIN_TIMEOUT_MS, MAX_TIMEOUT_MS = 200, 30_000
MAX_RETRIES = 3
MAX_SET_VARBINDS = 4
_NUMERIC_OID = re.compile(r"^[0-9]+(\.[0-9]+)+$")
_CONTROL = re.compile(r"[\x00-\x1f\x7f]")


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
    # Only set for device actions (app/actions); polling never decrypts it.
    write_community: str | None = field(default=None, repr=False)

    def secrets(self) -> list[str]:
        return [s for s in (self.community, self.write_community, self.v3_auth_secret, self.v3_priv_secret) if s]


@dataclass(frozen=True)
class VarBind:
    """One value to SET. Only the two types device actions need are allowed."""
    oid: str
    type: Literal["integer", "octet_string"]
    value: int | str


@dataclass(frozen=True)
class Target:
    address: str
    credentials: Credentials = field(repr=False)
    port: int = 161


class SnmpTransport(Protocol):
    async def get(self, target: Target, oids: Sequence[str], *, timeout_ms: int, retries: int) -> dict[str, Any]: ...

    async def walk(self, target: Target, root_oid: str, *, max_rows: int, timeout_ms: int, retries: int) -> list[tuple[str, Any]]: ...

    async def set(self, target: Target, varbinds: Sequence[VarBind], *, timeout_ms: int) -> dict[str, Any]: ...


class DisabledTransport:
    """The production default. Nothing ever leaves the process."""

    async def get(self, target: Target, oids: Sequence[str], *, timeout_ms: int, retries: int) -> dict[str, Any]:
        raise TransportDisabled("the SNMP transport is disabled in this build")

    async def walk(self, target: Target, root_oid: str, *, max_rows: int, timeout_ms: int, retries: int) -> list[tuple[str, Any]]:
        raise TransportDisabled("the SNMP transport is disabled in this build")

    async def set(self, target: Target, varbinds: Sequence[VarBind], *, timeout_ms: int) -> dict[str, Any]:
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

    async def set(self, target: Target, varbinds: Sequence[VarBind], *, timeout_ms: int) -> dict[str, Any]:
        """A bounded SNMP SET for device actions (Plan 38). Refused before anything is sent when: there are no values or
        more than MAX_SET_VARBINDS, an OID is not numeric, a value does not fit its type, text carries a control
        character, or a v1/v2c target has no write community. The request carries the write community only, never the
        read one. A SET is never retried automatically: repeating a write is the caller's decision, not the transport's."""
        if not 1 <= len(varbinds) <= MAX_SET_VARBINDS:
            raise UnboundedRequest(f"a set names 1 to {MAX_SET_VARBINDS} values")
        for vb in varbinds:
            if not _NUMERIC_OID.match(vb.oid):
                raise UnboundedRequest("a set names numeric OIDs only")
            if vb.type == "integer":
                if not isinstance(vb.value, int) or isinstance(vb.value, bool) or not -2**31 <= vb.value < 2**31:
                    raise UnboundedRequest("an integer value must be a 32-bit integer")
            elif vb.type == "octet_string":
                if not isinstance(vb.value, str) or len(vb.value) > 255 or _CONTROL.search(vb.value):
                    raise UnboundedRequest("a text value must be at most 255 characters with no control characters")
            else:
                raise UnboundedRequest(f"unsupported value type {vb.type!r}")
        creds = target.credentials
        if creds.version in ("v1", "v2c"):
            if not creds.write_community:
                raise UnboundedRequest("this device's access profile has no write community")
            target = replace(target, credentials=replace(creds, community=creds.write_community, write_community=None))
        timeout = self._timeout(timeout_ms)
        self.requests.append(RequestRecord("set", target.address, tuple(vb.oid for vb in varbinds), None, timeout))
        return await self.inner.set(target, list(varbinds), timeout_ms=timeout)
