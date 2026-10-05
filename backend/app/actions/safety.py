"""The shared safety layer for dangerous actions (Plan 26).

Two steps, always:
1. `prepare`: a dry run. Checks the permission and the global gate, the parameters, the cap, and that every target is
   inside the caller's scope, then returns a summary listing every target and a confirmation token. Nothing is sent
   to any device.
2. `execute`: consumes the token and runs the action through the executor Plan 38 registers for it. The token is
   single-use, short-lived, and bound to the user, the action, the exact targets and the parameters; it cannot be
   reused, moved to another target, or used after it expires. Permission and scope are checked again, because either
   may have changed in between. Each target gets a result record and an audit entry with before/after context,
   secrets redacted.

No executor is registered in this build, so `prepare` refuses every action with "not available": the layer exists
and is tested, and nothing can reach a device through it until Plan 38 adds an executor.
"""
from __future__ import annotations

import hashlib
import json
import re
import secrets
import uuid
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Awaitable, Callable, Protocol

import asyncpg

from app.actions.catalogue import ACTIONS, GATE, MAX_TARGETS_HARD, ActionSpec
from app.core.audit import write_audit
from app.core.security import CurrentUser
from app.repositories import devices as device_repo
from app.repositories import interfaces as interface_repo

CONFIRMATION_TTL_SECONDS = 120
_ONU_IDENTITY = re.compile(r"^[A-Za-z0-9:._/-]{1,64}$")
_TEXT_PARAM = re.compile(r"^[A-Za-z0-9 ._:/-]{1,120}$")


class ActionError(Exception):
    status = 400

    def __init__(self, message: str) -> None:
        super().__init__(message)
        self.message = message


class UnknownAction(ActionError):
    status = 404


class NotAllowed(ActionError):
    status = 403


class NotAvailable(ActionError):
    status = 501


class InvalidRequest(ActionError):
    status = 422


class TargetNotVisible(ActionError):
    status = 404


class ConfirmationRejected(ActionError):
    status = 409


@dataclass(frozen=True)
class Outcome:
    before: dict[str, Any] | None = None
    after: dict[str, Any] | None = None


class Executor(Protocol):
    def __call__(self, conn: asyncpg.Connection, user: CurrentUser, target: dict[str, Any], params: dict[str, Any]) -> Awaitable[Outcome]: ...


# action key -> executor. Plan 38 registers the real ones; tests register fakes. Empty in this build.
EXECUTORS: dict[str, Callable[..., Awaitable[Outcome]]] = {}


class ActionFailed(Exception):
    """Raised by an executor: the action ran and the device refused or failed it."""


def spec_for(user: CurrentUser, action: str) -> ActionSpec:
    spec = ACTIONS.get(action)
    if spec is None:
        raise UnknownAction("unknown action")
    if not (user.has_permission(spec.permission) and user.has_permission(GATE)):
        raise NotAllowed("not allowed to run this action")
    return spec


def _uuid(value: Any, what: str) -> str:
    try:
        return str(uuid.UUID(str(value)))
    except ValueError as exc:
        raise InvalidRequest(f"{what} is not a valid id") from exc


def normalize_targets(spec: ActionSpec, targets: list[dict[str, Any]]) -> list[dict[str, str]]:
    """Each target in its canonical form, duplicates removed, in a fixed order, so the same request always binds to the
    same confirmation. Refuses an empty list, unknown fields, and a list above the action's cap."""
    out: dict[str, dict[str, str]] = {}
    for raw in targets:
        if not isinstance(raw, dict):
            raise InvalidRequest("each target must be an object")
        if spec.target_kind == "device":
            keys, target = {"device_id"}, {"device_id": _uuid(raw.get("device_id"), "device_id")}
        elif spec.target_kind == "interface":
            keys, target = {"interface_id"}, {"interface_id": _uuid(raw.get("interface_id"), "interface_id")}
        else:
            identity = str(raw.get("onu", "")).strip()
            if not _ONU_IDENTITY.match(identity):
                raise InvalidRequest("onu must be the ONU's serial or MAC")
            keys, target = {"device_id", "onu"}, {"device_id": _uuid(raw.get("device_id"), "device_id"), "onu": identity}
        if set(raw) != keys:
            raise InvalidRequest(f"a {spec.target_kind} target has exactly the fields {sorted(keys)}")
        out[json.dumps(target, sort_keys=True)] = target
    if not out:
        raise InvalidRequest("name at least one target")
    if len(out) > min(spec.max_targets, MAX_TARGETS_HARD):
        raise InvalidRequest(f"at most {spec.max_targets} targets for {spec.key}")
    return [out[k] for k in sorted(out)]


def normalize_params(spec: ActionSpec, params: dict[str, Any]) -> dict[str, str]:
    if set(params) != set(spec.params):
        raise InvalidRequest(f"{spec.key} takes exactly the parameters {sorted(spec.params)}")
    out = {}
    for name, allowed in spec.params.items():
        value = str(params[name])
        if (allowed is not None and value not in allowed) or (allowed is None and not _TEXT_PARAM.match(value)):
            raise InvalidRequest(f"invalid value for {name}")
        out[name] = value
    return out


def _digest(params: dict[str, str]) -> str:
    return hashlib.sha256(json.dumps(params, sort_keys=True).encode()).hexdigest()


def _token_hash(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


async def describe_target(conn: asyncpg.Connection, user: CurrentUser, kind: str, target: dict[str, str]) -> dict[str, Any] | None:
    """What the confirmation shows for a target, or None when it is outside the caller's scope (or does not exist:
    the two are indistinguishable on purpose)."""
    if kind == "interface":
        row = await interface_repo.get_interface(conn, user, target["interface_id"])
        if row is None:
            return None
        device = await device_repo.get_device(conn, user, str(row["device_id"]))
        return {**target, "interface": row["name"], "device_id": str(row["device_id"]),
                "device": device["name"] if device else None}
    device = await device_repo.get_device(conn, user, target["device_id"])
    if device is None:
        return None
    described = {**target, "device": device["name"], "ip": str(device["management_ip"]) if device["management_ip"] else None}
    return described


async def _describe_all(conn: asyncpg.Connection, user: CurrentUser, spec: ActionSpec, targets: list[dict[str, str]]) -> list[dict[str, Any]]:
    described = []
    for target in targets:
        item = await describe_target(conn, user, spec.target_kind, target)
        if item is None:
            raise TargetNotVisible("a target does not exist or is outside your scope")
        described.append(item)
    return described


async def prepare(conn: asyncpg.Connection, user: CurrentUser, action: str, targets: list[dict[str, Any]], params: dict[str, Any],
                  *, ip: str | None = None) -> dict[str, Any]:
    spec = spec_for(user, action)
    if action not in EXECUTORS:
        raise NotAvailable(f"{spec.title} is not available yet (Plan 38)")
    canonical = normalize_targets(spec, targets)
    clean = normalize_params(spec, params)
    described = await _describe_all(conn, user, spec, canonical)
    token = secrets.token_urlsafe(32)
    summary = {"action": spec.key, "title": spec.title, "targets": described, "count": len(canonical), "params": clean}
    expires_at: datetime = await conn.fetchval(
        """
        insert into action_confirmations (token_hash, user_id, action, targets, target_count, params_digest, summary, expires_at)
        values ($1, $2::uuid, $3, $4::jsonb, $5, $6, $7::jsonb, now() + make_interval(secs => $8)) returning expires_at
        """,
        _token_hash(token), user.id, spec.key, json.dumps(canonical), len(canonical), _digest(clean), json.dumps(summary),
        CONFIRMATION_TTL_SECONDS,
    )
    await write_audit(conn, action="action.prepare", actor_user_id=user.id, resource_type="action", ip=ip,
                      metadata={"action": spec.key, "count": len(canonical), "targets": canonical, "params": clean})
    return {"token": token, "expires_at": expires_at, "summary": summary}


async def _consume(conn: asyncpg.Connection, user: CurrentUser, spec: ActionSpec, token: str, canonical: list[dict[str, str]],
                   clean: dict[str, str]) -> str:
    """Marks the confirmation used and returns its id, or raises. A token presented with anything that does not match
    what it was issued for (another target, other parameters, another action) is burned as well: one wrong use and it
    is gone. The reason is not said, so a caller cannot probe which part was wrong.

    The UPDATE is one statement run outside any transaction, so the burn commits before anything is checked: an
    exception raised afterwards cannot roll it back."""
    if conn.is_in_transaction():
        raise RuntimeError("consume a confirmation outside a transaction, so the burn always commits")
    row = await conn.fetchrow(
        """
        update action_confirmations set used_at = now()
        where token_hash = $1 and user_id = $2::uuid and used_at is null
        returning id, action, targets, params_digest, expires_at > used_at as fresh
        """,
        _token_hash(token), user.id,
    )
    stored_targets = json.loads(row["targets"]) if row is not None and isinstance(row["targets"], str) else (row["targets"] if row else None)
    if (row is None or not row["fresh"] or row["action"] != spec.key or stored_targets != canonical
            or row["params_digest"] != _digest(clean)):
        raise ConfirmationRejected("the confirmation is invalid, expired, already used, or for a different request")
    return str(row["id"])


async def execute(conn: asyncpg.Connection, user: CurrentUser, action: str, token: str, targets: list[dict[str, Any]],
                  params: dict[str, Any], *, ip: str | None = None) -> dict[str, Any]:
    spec = spec_for(user, action)
    executor = EXECUTORS.get(action)
    if executor is None:
        raise NotAvailable(f"{spec.title} is not available yet (Plan 38)")
    canonical = normalize_targets(spec, targets)
    clean = normalize_params(spec, params)
    confirmation_id = await _consume(conn, user, spec, token, canonical, clean)
    results = []
    for target in canonical:
        described = await describe_target(conn, user, spec.target_kind, target)
        before = after = None
        if described is None:  # scope shrank, or the target was deleted, since the dry run
            status, error = "refused", "the target is no longer inside your scope"
        else:
            try:
                outcome = await executor(conn, user, target, clean)
                status, error, before, after = "succeeded", None, outcome.before, outcome.after
            except ActionFailed as exc:
                status, error = "failed", str(exc) or "the device did not accept the action"
        await conn.execute(
            """
            insert into action_results (confirmation_id, user_id, action, target, status, error, before, after, finished_at)
            values ($1::uuid, $2::uuid, $3, $4::jsonb, $5, $6, $7::jsonb, $8::jsonb, now())
            """,
            confirmation_id, user.id, spec.key, json.dumps(target), status, error,
            json.dumps(_redacted(before)) if before is not None else None, json.dumps(_redacted(after)) if after is not None else None,
        )
        await write_audit(conn, action="action.execute", actor_user_id=user.id, resource_type="action", ip=ip,
                          before=before, after=after,
                          metadata={"action": spec.key, "target": target, "params": clean, "status": status, "error": error,
                                    "confirmation_id": confirmation_id})
        results.append({"target": target, "status": status, "error": error})
    return {"action": spec.key, "confirmation_id": confirmation_id, "results": results}


def _redacted(value: dict[str, Any]) -> dict[str, Any]:
    from app.core.audit import redact

    return redact(value)


def available_actions(user: CurrentUser) -> list[dict[str, Any]]:
    """The actions this user may run, each with whether this build can run it yet."""
    return [{"key": s.key, "title": s.title, "target_kind": s.target_kind, "max_targets": s.max_targets,
             "params": {k: list(v) if v else None for k, v in s.params.items()}, "available": s.key in EXECUTORS}
            for s in ACTIONS.values() if user.has_permission(s.permission) and user.has_permission(GATE)]
