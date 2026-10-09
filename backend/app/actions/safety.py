"""The shared safety layer for dangerous actions (Plan 26), with the queued execution Plan 38 adds.

Three steps, always:
1. `prepare` (API): a dry run. Checks the global kill switch, that a driver exists, the permission and the global
   gate, the parameters, the cap, and that every target is inside the caller's scope, then returns a summary listing
   every target and a confirmation token. Nothing is sent to any device.
2. `execute` (API): consumes the token and records one `queued` result per target. The token is single-use,
   short-lived, and bound to the user, the action, the exact targets and the parameters; it cannot be reused, moved
   to another target, or used after it expires. The route then publishes a signed job. The API process never talks
   to a device.
3. `run_confirmation` (worker): claims each queued result, checks the requester's permission and scope again with
   fresh data (either may have changed since), runs the driver, and records the result and an audit entry with
   before/after context, secrets redacted. A worker on the disabled transport consumes no jobs at all, so in a build
   without a real transport the results simply stay queued.

Device actions are off unless DEVICE_ACTIONS_ENABLED is set: `prepare` answers 503 while the switch is off.
"""
from __future__ import annotations

import hashlib
import json
import re
import secrets
import uuid
from datetime import datetime
from typing import Any

import asyncpg

from app.actions.base import ActionFailed, Outcome  # noqa: F401  (re-exported for drivers and tests)
from app.actions.catalogue import ACTIONS, GATE, MAX_TARGETS_HARD, ActionSpec
from app.core.audit import redact, write_audit
from app.core.config import settings
from app.core.crypto import EncryptionService
from app.core.security import CurrentUser, fetch_role_permissions
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


class SwitchedOff(ActionError):
    status = 503


def drivers() -> dict[str, Any]:
    """action key -> driver factory `(transport, enc) -> executor`. Imported late: drivers import this module's errors."""
    from app.actions.drivers import DRIVERS

    return DRIVERS


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
                  *, stop_on_failure: bool = False, ip: str | None = None) -> dict[str, Any]:
    spec = spec_for(user, action)
    ensure_available(spec)
    canonical = normalize_targets(spec, targets)
    clean = normalize_params(spec, params)
    described = await _describe_all(conn, user, spec, canonical)
    token = secrets.token_urlsafe(32)
    summary = {"action": spec.key, "title": spec.title, "targets": described, "count": len(canonical), "params": clean,
               "stop_on_failure": stop_on_failure}
    expires_at: datetime = await conn.fetchval(
        """
        insert into action_confirmations (token_hash, user_id, action, targets, target_count, params_digest, summary, expires_at,
                                          stop_on_failure)
        values ($1, $2::uuid, $3, $4::jsonb, $5, $6, $7::jsonb, now() + make_interval(secs => $8), $9) returning expires_at
        """,
        _token_hash(token), user.id, spec.key, json.dumps(canonical), len(canonical), _digest(clean), json.dumps(summary),
        CONFIRMATION_TTL_SECONDS, stop_on_failure,
    )
    await write_audit(conn, action="action.prepare", actor_user_id=user.id, resource_type="action", ip=ip,
                      metadata={"action": spec.key, "count": len(canonical), "targets": canonical, "params": clean,
                                "stop_on_failure": stop_on_failure})
    return {"token": token, "expires_at": expires_at, "summary": summary}


async def _consume(conn: asyncpg.Connection, user: CurrentUser, spec: ActionSpec, token: str, canonical: list[dict[str, str]],
                   clean: dict[str, str], stop_on_failure: bool) -> str:
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
        returning id, action, targets, params_digest, stop_on_failure, expires_at > used_at as fresh
        """,
        _token_hash(token), user.id,
    )
    stored_targets = json.loads(row["targets"]) if row is not None and isinstance(row["targets"], str) else (row["targets"] if row else None)
    if (row is None or not row["fresh"] or row["action"] != spec.key or stored_targets != canonical
            or row["params_digest"] != _digest(clean) or row["stop_on_failure"] != stop_on_failure):
        raise ConfirmationRejected("the confirmation is invalid, expired, already used, or for a different request")
    return str(row["id"])


SKIPPED_REASON = "not run: an earlier target did not succeed and this request stops on the first failure"


def ensure_available(spec: ActionSpec) -> None:
    if not settings.device_actions_enabled:
        raise SwitchedOff("device actions are switched off (DEVICE_ACTIONS_ENABLED)")
    if spec.key not in drivers():
        raise NotAvailable(f"{spec.title} is not available yet (Plan 38)")


async def execute(conn: asyncpg.Connection, user: CurrentUser, action: str, token: str, targets: list[dict[str, Any]],
                  params: dict[str, Any], *, stop_on_failure: bool = False, ip: str | None = None) -> dict[str, Any]:
    """Consume the confirmation and queue one result per target. Runs nothing: the caller publishes the job, and a
    worker runs it (`run_confirmation`)."""
    spec = spec_for(user, action)
    ensure_available(spec)
    canonical = normalize_targets(spec, targets)
    clean = normalize_params(spec, params)
    confirmation_id = await _consume(conn, user, spec, token, canonical, clean, stop_on_failure)
    async with conn.transaction():
        for target in canonical:
            await conn.execute(
                "insert into action_results (confirmation_id, user_id, action, target, status) values ($1::uuid, $2::uuid, $3, $4::jsonb, 'queued')",
                confirmation_id, user.id, spec.key, json.dumps(target),
            )
        await write_audit(conn, action="action.queued", actor_user_id=user.id, resource_type="action", ip=ip,
                          metadata={"action": spec.key, "targets": canonical, "params": clean, "stop_on_failure": stop_on_failure,
                                    "confirmation_id": confirmation_id})
    return {"action": spec.key, "confirmation_id": confirmation_id,
            "results": [{"target": t, "status": "queued", "error": None} for t in canonical]}


async def fail_queued(conn: asyncpg.Connection, confirmation_id: str, reason: str) -> None:
    """Mark every still-queued result failed, for a job that could not be published."""
    await conn.execute(
        "update action_results set status = 'failed', error = $2, finished_at = now() where confirmation_id = $1::uuid and status = 'queued'",
        confirmation_id, reason,
    )


async def load_actor(conn: asyncpg.Connection, user_id: str) -> CurrentUser | None:
    """The requester as they are now, with their role's current permissions and scope; None when the account is gone or
    disabled. A worker has no session, and a change since the request must count."""
    row = await conn.fetchrow(
        "select u.id, u.username, u.display_name, u.email, u.is_active, r.id as role_id, r.name as role_name, r.scope_mode "
        "from users u join roles r on r.id = u.role_id where u.id = $1::uuid",
        user_id,
    )
    if row is None or not row["is_active"]:
        return None
    permissions = frozenset(await fetch_role_permissions(conn, str(row["role_id"])))
    return CurrentUser(str(row["id"]), row["username"], row["display_name"], row["email"], row["role_name"], str(row["role_id"]),
                       row["scope_mode"], permissions, auth_kind="worker")


async def _finish(conn: asyncpg.Connection, result_id: str, status: str, error: str | None, before: dict[str, Any] | None,
                  after: dict[str, Any] | None) -> None:
    await conn.execute(
        "update action_results set status = $2, error = $3, before = $4::jsonb, after = $5::jsonb, finished_at = now() where id = $1::uuid",
        result_id, status, error, json.dumps(redact(before)) if before is not None else None,
        json.dumps(redact(after)) if after is not None else None,
    )


async def run_confirmation(conn: asyncpg.Connection, transport: Any, enc: EncryptionService, confirmation_id: str) -> dict[str, int]:
    """Worker side: run every queued result of one confirmation. Each result is claimed atomically, so a redelivered
    job never runs a target twice. Returns counts by final status.

    A request confirmed with stop_on_failure stops at the first target that does not succeed (failed or refused): the
    targets still queued are marked `skipped` with the reason, and nothing more is sent."""
    confirmation = await conn.fetchrow("select user_id, action, summary, stop_on_failure from action_confirmations where id = $1::uuid",
                                       confirmation_id)
    counts = {"succeeded": 0, "failed": 0, "refused": 0, "skipped": 0}
    if confirmation is None:
        return counts
    summary = json.loads(confirmation["summary"]) if isinstance(confirmation["summary"], str) else confirmation["summary"]
    params, spec = summary["params"], ACTIONS.get(confirmation["action"])
    actor = await load_actor(conn, str(confirmation["user_id"]))
    factory = drivers().get(confirmation["action"])
    executor = factory(transport, enc) if factory is not None else None
    while True:
        claimed = await conn.fetchrow(
            """
            update action_results set status = 'running', started_at = now()
            where id = (select id from action_results where confirmation_id = $1::uuid and status = 'queued' order by target::text limit 1
                        for update skip locked)
            returning id, target
            """,
            confirmation_id,
        )
        if claimed is None:
            return counts
        target = json.loads(claimed["target"]) if isinstance(claimed["target"], str) else claimed["target"]
        before = after = None
        if spec is None or executor is None:
            status, error = "refused", "this action is no longer available"
        elif not settings.device_actions_enabled:
            status, error = "refused", "device actions were switched off before this ran"
        elif actor is None:
            status, error = "refused", "the requester's account is no longer active"
        elif not (actor.has_permission(spec.permission) and actor.has_permission(GATE)):
            status, error = "refused", "the requester no longer has permission for this action"
        elif await describe_target(conn, actor, spec.target_kind, target) is None:
            status, error = "refused", "the target is no longer inside the requester's scope"
        else:
            try:
                outcome = await executor(conn, actor, target, params)
                status, error, before, after = "succeeded", None, outcome.before, outcome.after
            except ActionFailed as exc:
                status, error = "failed", str(exc) or "the device did not accept the action"
        await _finish(conn, str(claimed["id"]), status, error, before, after)
        await write_audit(conn, action="action.execute", actor_user_id=str(confirmation["user_id"]), resource_type="action",
                          before=before, after=after,
                          metadata={"action": confirmation["action"], "target": target, "params": params, "status": status,
                                    "error": error, "confirmation_id": confirmation_id})
        counts[status] += 1
        if status != "succeeded" and confirmation["stop_on_failure"]:
            counts["skipped"] += await _skip_rest(conn, confirmation, confirmation_id)
            return counts


async def _skip_rest(conn: asyncpg.Connection, confirmation: asyncpg.Record, confirmation_id: str) -> int:
    skipped = await conn.fetch(
        "update action_results set status = 'skipped', error = $2, finished_at = now() "
        "where confirmation_id = $1::uuid and status = 'queued' returning target",
        confirmation_id, SKIPPED_REASON,
    )
    if skipped:
        await write_audit(conn, action="action.skipped", actor_user_id=str(confirmation["user_id"]), resource_type="action",
                          metadata={"action": confirmation["action"], "count": len(skipped), "confirmation_id": confirmation_id})
    return len(skipped)


async def results(conn: asyncpg.Connection, user: CurrentUser, confirmation_id: str) -> dict[str, Any] | None:
    """A confirmation's results, for the user who requested it. None for anyone else (indistinguishable from absent)."""
    rows = await conn.fetch(
        "select r.target, r.status, r.error, r.started_at, r.finished_at, c.action from action_results r "
        "join action_confirmations c on c.id = r.confirmation_id where r.confirmation_id = $1::uuid and c.user_id = $2::uuid "
        "order by r.target::text",
        confirmation_id, user.id,
    )
    if not rows:
        return None
    return {"action": rows[0]["action"], "confirmation_id": confirmation_id,
            "results": [{"target": json.loads(r["target"]) if isinstance(r["target"], str) else r["target"], "status": r["status"],
                         "error": r["error"]} for r in rows]}


def available_actions(user: CurrentUser) -> list[dict[str, Any]]:
    """The actions this user may run, each with whether this build can run it yet."""
    return [{"key": s.key, "title": s.title, "target_kind": s.target_kind, "max_targets": s.max_targets,
             "params": {k: list(v) if v else None for k, v in s.params.items()},
             "available": settings.device_actions_enabled and s.key in drivers()}
            for s in ACTIONS.values() if user.has_permission(s.permission) and user.has_permission(GATE)]
