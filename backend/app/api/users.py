from __future__ import annotations

import secrets
import uuid
from datetime import datetime
from typing import Annotated

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, Field

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.passwords import SCHEME_ARGON2, check_strength, hash_password
from app.core.security import CurrentUser, fetch_role_permissions, require
from app.repositories import access as access_repo

router = APIRouter(prefix=settings.api_prefix, tags=["users"])

SUPER_ADMIN = "Super Admin"


class UserItem(BaseModel):
    id: str
    username: str
    display_name: str
    email: str | None
    role: str
    is_active: bool
    totp_enabled: bool
    strict_ip_enabled: bool
    allowed_ips: list[str]
    last_login_at: datetime | None
    reseller: str | None = None


class UserCreate(BaseModel):
    username: str = Field(min_length=1, max_length=80, pattern=r"^[A-Za-z0-9._@-]+$")
    display_name: str = Field(min_length=1, max_length=160)
    email: str | None = Field(default=None, max_length=255)
    role_id: str
    password: str | None = Field(default=None, max_length=1024)


class UserCreated(UserItem):
    generated_password: str | None = None


class UserUpdate(BaseModel):
    display_name: str | None = Field(default=None, min_length=1, max_length=160)
    email: str | None = Field(default=None, max_length=255)
    role_id: str | None = None
    is_active: bool | None = None
    strict_ip_enabled: bool | None = None
    allowed_ips: list[str] | None = None


class PasswordReset(BaseModel):
    password: str | None = Field(default=None, max_length=1024)


class ScopeEntry(BaseModel):
    id: str
    level: str = Field(default="viewer", pattern=r"^(viewer|operator|admin)$")


class ScopeAssignment(BaseModel):
    device_groups: list[ScopeEntry] = Field(default_factory=list, max_length=1000)
    devices: list[ScopeEntry] = Field(default_factory=list, max_length=5000)
    interfaces: list[ScopeEntry] = Field(default_factory=list, max_length=5000)


class ResellerCreate(BaseModel):
    name: str = Field(min_length=1, max_length=160)
    description: str | None = None


class ResellerAssign(BaseModel):
    user_ids: list[str] = Field(max_length=1000)


def _uuid(value: str, what: str = "id") -> str:
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND if what == "id" else status.HTTP_422_UNPROCESSABLE_ENTITY,
                            detail="Not found" if what == "id" else f"Invalid {what}") from exc


USER_SELECT = """
    select u.id, u.username, u.display_name, u.email, r.name as role, u.is_active, u.totp_enabled, u.strict_ip_enabled,
           coalesce((select array_agg(a::text) from unnest(u.allowed_ips) a), array[]::text[]) as allowed_ips,
           u.last_login_at, rs.name as reseller
    from users u join roles r on r.id = u.role_id
    left join reseller_users ru on ru.user_id = u.id left join resellers rs on rs.id = ru.reseller_id
"""


def _item(row: asyncpg.Record) -> dict:
    data = dict(row)
    data["id"] = str(data["id"])
    data["allowed_ips"] = list(data["allowed_ips"] or [])
    return data


async def _assert_can_grant_role(conn: asyncpg.Connection, actor: CurrentUser, role_id: str) -> asyncpg.Record:
    role = await conn.fetchrow("select id, name from roles where id = $1::uuid", role_id)
    if role is None:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Unknown role")
    granted = set(await fetch_role_permissions(conn, str(role["id"])))
    beyond = granted - set(actor.permissions)
    if beyond:
        # Nobody can hand out a role that is more powerful than their own.
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="You cannot assign a role with permissions you do not hold")
    return role


async def _other_active_super_admins(conn: asyncpg.Connection, excluding: str) -> int:
    return await conn.fetchval(
        "select count(*) from users u join roles r on r.id = u.role_id where r.name = $1 and u.is_active and u.id <> $2::uuid",
        SUPER_ADMIN, excluding,
    )


@router.get("/users", response_model=list[UserItem])
async def list_users(
    _: Annotated[CurrentUser, Depends(require("users.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict]:
    return [_item(r) for r in await conn.fetch(USER_SELECT + " order by u.username")]


@router.get("/users/{user_id}", response_model=UserItem)
async def get_user(
    user_id: str,
    _: Annotated[CurrentUser, Depends(require("users.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict:
    row = await conn.fetchrow(USER_SELECT + " where u.id = $1::uuid", _uuid(user_id))
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return _item(row)


@router.post("/users", response_model=UserCreated, status_code=status.HTTP_201_CREATED)
async def create_user(
    payload: UserCreate,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("users.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict:
    role = await _assert_can_grant_role(conn, actor, _uuid(payload.role_id, "role_id"))
    generated = payload.password is None
    password = payload.password or secrets.token_urlsafe(18)
    problems = check_strength(password, settings.password_min_length)
    if problems:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail={"password_problems": problems})
    if await conn.fetchval("select exists(select 1 from users where username = $1)", payload.username):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Username already exists")
    async with conn.transaction():
        new_id = await conn.fetchval(
            """
            insert into users (role_id, username, email, display_name, password_hash, hash_scheme, password_changed_at,
                               must_change_password, is_active)
            values ($1, $2, $3, $4, $5, $6, now(), true, true) returning id
            """,
            role["id"], payload.username, payload.email, payload.display_name, hash_password(password), SCHEME_ARGON2,
        )
        await write_audit(conn, action="user.created", actor_user_id=actor.id, resource_type="user", resource_id=str(new_id),
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"),
                          after={"username": payload.username, "role": role["name"], "email": payload.email})
    data = _item(await conn.fetchrow(USER_SELECT + " where u.id = $1", new_id))
    data["generated_password"] = password if generated else None
    return data


@router.patch("/users/{user_id}", response_model=UserItem)
async def update_user(
    user_id: str,
    payload: UserUpdate,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("users.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict:
    uid = _uuid(user_id)
    before = await conn.fetchrow(USER_SELECT + " where u.id = $1::uuid", uid)
    if before is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    changes = payload.model_dump(exclude_unset=True)
    new_role = None
    if "role_id" in changes and changes["role_id"]:
        new_role = await _assert_can_grant_role(conn, actor, _uuid(changes["role_id"], "role_id"))
    demoting = before["role"] == SUPER_ADMIN and (
        (new_role is not None and new_role["name"] != SUPER_ADMIN) or changes.get("is_active") is False
    )
    if demoting and not await _other_active_super_admins(conn, uid):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="The last active Super Admin cannot be demoted or disabled")
    if uid == actor.id and changes.get("is_active") is False:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="You cannot disable your own account")
    if changes.get("allowed_ips"):
        import ipaddress
        for item in changes["allowed_ips"]:
            try:
                ipaddress.ip_network(item, strict=False)
            except ValueError as exc:
                raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=f"Invalid address or network: {item}") from exc
    if changes.get("strict_ip_enabled") and not (changes.get("allowed_ips") or before["allowed_ips"]):
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Strict IP access needs at least one allowed address")

    async with conn.transaction():
        if "display_name" in changes and changes["display_name"]:
            await conn.execute("update users set display_name = $2, updated_at = now() where id = $1::uuid", uid, changes["display_name"])
        if "email" in changes:
            await conn.execute("update users set email = $2, updated_at = now() where id = $1::uuid", uid, changes["email"])
        if new_role is not None:
            await conn.execute("update users set role_id = $2, updated_at = now() where id = $1::uuid", uid, new_role["id"])
        if "allowed_ips" in changes:
            await conn.execute("update users set allowed_ips = $2::inet[], updated_at = now() where id = $1::uuid", uid, changes["allowed_ips"] or None)
        if "strict_ip_enabled" in changes and changes["strict_ip_enabled"] is not None:
            await conn.execute("update users set strict_ip_enabled = $2, updated_at = now() where id = $1::uuid", uid, changes["strict_ip_enabled"])
        if changes.get("is_active") is not None:
            await conn.execute("update users set is_active = $2, updated_at = now() where id = $1::uuid", uid, changes["is_active"])
            if changes["is_active"] is False:
                await conn.execute("update user_sessions set revoked_at = now() where user_id = $1::uuid and revoked_at is null", uid)
                await conn.execute("update api_tokens set revoked_at = now() where user_id = $1::uuid and revoked_at is null", uid)
        after = await conn.fetchrow(USER_SELECT + " where u.id = $1::uuid", uid)
        await write_audit(conn, action="user.updated", actor_user_id=actor.id, resource_type="user", resource_id=uid,
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"),
                          before=_item(before), after=_item(after))
    return _item(after)


@router.post("/users/{user_id}/password", response_model=schemas.PasswordReset)
async def reset_password(
    user_id: str,
    payload: PasswordReset,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("users.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str | None]:
    uid = _uuid(user_id)
    if not await conn.fetchval("select exists(select 1 from users where id = $1::uuid)", uid):
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    generated = payload.password is None
    password = payload.password or secrets.token_urlsafe(18)
    problems = check_strength(password, settings.password_min_length)
    if problems:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail={"password_problems": problems})
    async with conn.transaction():
        await conn.execute(
            "update users set password_hash = $2, hash_scheme = $3, password_changed_at = now(), must_change_password = true, updated_at = now() where id = $1::uuid",
            uid, hash_password(password), SCHEME_ARGON2,
        )
        await conn.execute("update user_sessions set revoked_at = now() where user_id = $1::uuid and revoked_at is null", uid)
        await write_audit(conn, action="user.password_reset", actor_user_id=actor.id, resource_type="user", resource_id=uid,
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"))
    return {"status": "password_reset", "generated_password": password if generated else None}


@router.post("/users/{user_id}/2fa/reset", response_model=schemas.StatusOut)
async def reset_2fa(
    user_id: str,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("users.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    uid = _uuid(user_id)
    async with conn.transaction():
        status_text = await conn.execute("update users set totp_enabled = false, totp_secret_enc = null, totp_last_counter = null where id = $1::uuid", uid)
        if status_text.endswith(" 0"):
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
        await conn.execute("delete from totp_recovery_codes where user_id = $1::uuid", uid)
        await write_audit(conn, action="user.2fa_reset", actor_user_id=actor.id, resource_type="user", resource_id=uid,
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"))
    return {"status": "2fa_reset"}


@router.get("/access/users/{user_id}/scopes", response_model=ScopeAssignment)
async def get_scopes(
    user_id: str,
    _: Annotated[CurrentUser, Depends(require("scope.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> ScopeAssignment:
    uid = _uuid(user_id)
    groups = await conn.fetch("select device_group_id as id, scope_level from user_device_group_scopes where user_id = $1::uuid", uid)
    devices = await conn.fetch("select device_id as id, scope_level from user_device_scopes where user_id = $1::uuid", uid)
    interfaces = await conn.fetch("select interface_id as id, scope_level from user_interface_scopes where user_id = $1::uuid", uid)
    to = lambda rows: [ScopeEntry(id=str(r["id"]), level=r["scope_level"]) for r in rows]  # noqa: E731
    return ScopeAssignment(device_groups=to(groups), devices=to(devices), interfaces=to(interfaces))


@router.put("/access/users/{user_id}/scopes", response_model=ScopeAssignment)
async def set_scopes(
    user_id: str,
    payload: ScopeAssignment,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("scope.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> ScopeAssignment:
    uid = _uuid(user_id)
    if not await conn.fetchval("select exists(select 1 from users where id = $1::uuid)", uid):
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    plan = [
        ("user_device_group_scopes", "device_group_id", "device_groups", payload.device_groups),
        ("user_device_scopes", "device_id", "devices", payload.devices),
        ("user_interface_scopes", "interface_id", "interfaces", payload.interfaces),
    ]
    async with conn.transaction():
        for table, column, target, entries in plan:
            ids = [_uuid(e.id, f"{target} id") for e in entries]
            found = await access_repo.count_existing(conn, target, ids)
            if found != len(set(ids)):
                raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=f"Unknown {target} in scope list")
            await conn.execute(f"delete from {table} where user_id = $1::uuid", uid)
            for entry, entry_id in zip(entries, ids):
                await conn.execute(
                    f"insert into {table} (user_id, {column}, scope_level) values ($1::uuid, $2::uuid, $3) on conflict ({'user_id'}, {column}) do update set scope_level = excluded.scope_level",
                    uid, entry_id, entry.level,
                )
        await write_audit(conn, action="scope.assigned", actor_user_id=actor.id, resource_type="user", resource_id=uid,
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"),
                          after={"device_groups": len(payload.device_groups), "devices": len(payload.devices), "interfaces": len(payload.interfaces)})
    return payload


@router.post("/resellers", status_code=status.HTTP_201_CREATED, response_model=schemas.ResellerCreated)
async def create_reseller(
    payload: ResellerCreate,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("resellers.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    if await conn.fetchval("select exists(select 1 from resellers where name = $1)", payload.name):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Reseller already exists")
    async with conn.transaction():
        rid = await conn.fetchval("insert into resellers (name, description) values ($1, $2) returning id", payload.name, payload.description)
        await write_audit(conn, action="reseller.created", actor_user_id=actor.id, resource_type="reseller", resource_id=str(rid),
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"), after={"name": payload.name})
    return {"id": str(rid), "name": payload.name}


@router.get("/resellers", response_model=list[schemas.ResellerOut])
async def list_resellers(
    _: Annotated[CurrentUser, Depends(require("resellers.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict]:
    rows = await conn.fetch(
        """
        select r.id, r.name, r.description, r.is_active, count(ru.user_id) as users
        from resellers r left join reseller_users ru on ru.reseller_id = r.id group by r.id order by r.name
        """
    )
    return [{**dict(r), "id": str(r["id"])} for r in rows]


@router.put("/resellers/{reseller_id}/users", response_model=schemas.ResellerUsers)
async def assign_reseller_users(
    reseller_id: str,
    payload: ResellerAssign,
    request: Request,
    actor: Annotated[CurrentUser, Depends(require("resellers.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, int]:
    rid = _uuid(reseller_id)
    if not await conn.fetchval("select exists(select 1 from resellers where id = $1::uuid)", rid):
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    ids = [_uuid(i, "user id") for i in payload.user_ids]
    async with conn.transaction():
        await conn.execute("delete from reseller_users where reseller_id = $1::uuid", rid)
        for user_id in ids:
            await conn.execute(
                "insert into reseller_users (user_id, reseller_id) values ($1::uuid, $2::uuid) on conflict (user_id) do update set reseller_id = excluded.reseller_id",
                user_id, rid,
            )
        await write_audit(conn, action="reseller.users_assigned", actor_user_id=actor.id, resource_type="reseller", resource_id=rid,
                          ip=actor.client_ip, user_agent=request.headers.get("user-agent"), after={"users": len(ids)})
    return {"users": len(ids)}
