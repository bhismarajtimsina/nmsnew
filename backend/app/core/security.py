"""Authentication and permission dependencies.

Credentials are presented as `Authorization: Bearer <token>` or, for the Nginx auth gate in front of proxied apps, the
`cs_session` cookie. Two token kinds exist and are told apart by prefix:

  css_...   interactive session, issued by login
  cst_...   API token for machines, permission-limited, created by a user

Every route under the API prefix declares its protection through a dependency carrying `_cs_protection`
(`permission`, `authenticated` or `public`). tests/test_route_guard.py fails when a route has none.
"""
from __future__ import annotations

import hashlib
import secrets
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from typing import Annotated, Callable

import asyncpg
from fastapi import Depends, Header, HTTPException, Request, status

from app.core.config import settings
from app.core.database import get_conn
from app.core.netutil import client_ip, ip_allowed

SESSION_PREFIX = "css_"
API_TOKEN_PREFIX = "cst_"
SAFE_METHODS = {"GET", "HEAD", "OPTIONS"}
ACTIVITY_WRITE_INTERVAL = timedelta(seconds=60)


@dataclass(frozen=True)
class CurrentUser:
    id: str
    username: str
    display_name: str
    email: str | None
    role: str
    role_id: str
    scope_mode: str
    permissions: frozenset[str] = field(default_factory=frozenset)
    auth_kind: str = "session"
    credential_id: str | None = None
    credential_source: str = "header"
    must_change_password: bool = False
    client_ip: str | None = None

    @property
    def scope_all(self) -> bool:
        # Anything other than the explicit value "all" is treated as assigned-only: unknown means restricted.
        return self.scope_mode == "all"

    def has_permission(self, permission: str) -> bool:
        return permission in self.permissions


def new_session_token() -> str:
    return SESSION_PREFIX + secrets.token_urlsafe(48)


def new_api_token() -> str:
    return API_TOKEN_PREFIX + secrets.token_urlsafe(48)


def hash_token(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


async def fetch_role_permissions(conn: asyncpg.Connection, role_id: str) -> list[str]:
    rows = await conn.fetch(
        """
        select p.code
        from permissions p
        join role_permissions rp on rp.permission_id = p.id
        where rp.role_id = $1::uuid
        order by p.code
        """,
        role_id,
    )
    return [row["code"] for row in rows]


def _unauthorized(detail: str = "Invalid or expired credentials") -> HTTPException:
    return HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail=detail, headers={"WWW-Authenticate": "Bearer"})


def _extract_token(request: Request, authorization: str | None) -> tuple[str, str]:
    if authorization:
        scheme, _, value = authorization.partition(" ")
        if scheme.lower() == "bearer" and value.strip():
            return value.strip(), "header"
        raise _unauthorized("Malformed Authorization header")
    cookie = request.cookies.get(settings.session_cookie_name)
    if cookie:
        return cookie, "cookie"
    raise _unauthorized("Missing credentials")


def _networks(value) -> list[str]:
    return [str(item) for item in (value or [])]


async def authenticate(conn: asyncpg.Connection, request: Request, authorization: str | None) -> CurrentUser:
    token, source = _extract_token(request, authorization)
    if source == "cookie" and request.method not in SAFE_METHODS:
        # Cookies ride along on cross-site requests. State-changing calls must present a Bearer token instead.
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Cookie credentials are read-only")
    return await authenticate_token(conn, token, ip=client_ip(request), source=source)


async def authenticate_token(conn: asyncpg.Connection, token: str, *, ip: str | None, source: str) -> CurrentUser:
    """The credential-lookup core `authenticate()` uses once it has a bare token string."""
    if token.startswith(API_TOKEN_PREFIX):
        kind = "api_token"
    elif token.startswith(SESSION_PREFIX):
        kind = "session"
    else:
        raise _unauthorized()
    return await _authenticate(conn, kind, "token_hash", hash_token(token), ip=ip, source=source)


async def authenticate_credential(conn: asyncpg.Connection, kind: str, credential_id: str, *, ip: str | None, source: str) -> CurrentUser:
    """The same checks as `authenticate_token`, for a credential already identified by id rather than presented as a
    token - used by the WebSocket ticket (app/realtime/tickets.py), so a ticket is honored only while the session or
    API token that minted it is still valid, its user still active and allowed from this address."""
    if kind not in ("session", "api_token"):
        raise _unauthorized()
    return await _authenticate(conn, kind, "id", credential_id, ip=ip, source=source)


async def _authenticate(conn: asyncpg.Connection, kind: str, column: str, value: str, *, ip: str | None, source: str) -> CurrentUser:
    assert column in ("token_hash", "id")
    now = datetime.now(timezone.utc)
    cast = "::uuid" if column == "id" else ""

    if kind == "api_token":
        row = await conn.fetchrow(
            f"""
            select t.id as credential_id, t.permissions as token_permissions, t.last_used_at,
                   u.id, u.username, u.email, u.display_name, u.role_id, u.strict_ip_enabled, u.allowed_ips,
                   u.must_change_password, r.name as role_name, r.scope_mode
            from api_tokens t
            join users u on u.id = t.user_id
            join roles r on r.id = u.role_id
            where t.{column} = $1{cast} and t.revoked_at is null
              and (t.expires_at is null or t.expires_at > now()) and u.is_active
            """,
            value,
        )
    else:
        row = await conn.fetchrow(
            f"""
            select s.id as credential_id, s.last_activity_at,
                   u.id, u.username, u.email, u.display_name, u.role_id, u.strict_ip_enabled, u.allowed_ips,
                   u.must_change_password, r.name as role_name, r.scope_mode
            from user_sessions s
            join users u on u.id = s.user_id
            join roles r on r.id = u.role_id
            where s.{column} = $1{cast} and s.revoked_at is null and s.expires_at > now() and u.is_active
            """,
            value,
        )

    if row is None:
        raise _unauthorized()
    if row["strict_ip_enabled"] and not ip_allowed(ip, _networks(row["allowed_ips"])):
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Access from this address is not allowed for this user")

    permissions = set(await fetch_role_permissions(conn, str(row["role_id"])))
    if kind == "api_token":
        # A token can only ever narrow its owner's permissions, and follows the owner if they lose some - except for
        # a service-only permission (no role ever grants one), which a token keeps for as long as it was minted with it.
        from app.access.catalogue import SERVICE_ONLY_PERMISSIONS

        permissions = (permissions | SERVICE_ONLY_PERMISSIONS) & set(row["token_permissions"])

    last = row["last_used_at"] if kind == "api_token" else row["last_activity_at"]
    if last is None or now - last > ACTIVITY_WRITE_INTERVAL:
        if kind == "api_token":
            await conn.execute("update api_tokens set last_used_at = now(), last_used_ip = $2::inet where id = $1", row["credential_id"], ip)
        else:
            await conn.execute("update user_sessions set last_activity_at = now() where id = $1", row["credential_id"])

    return CurrentUser(
        id=str(row["id"]),
        username=row["username"],
        display_name=row["display_name"],
        email=row["email"],
        role=row["role_name"],
        role_id=str(row["role_id"]),
        scope_mode=row["scope_mode"],
        permissions=frozenset(permissions),
        auth_kind=kind,
        credential_id=str(row["credential_id"]),
        credential_source=source,
        must_change_password=bool(row["must_change_password"]),
        client_ip=ip,
    )


def _mark(func: Callable, protection: str, **extra) -> Callable:
    func._cs_protection = protection  # type: ignore[attr-defined]
    for key, value in extra.items():
        setattr(func, key, value)
    return func


async def get_current_user_lenient(
    request: Request,
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    authorization: Annotated[str | None, Header()] = None,
) -> CurrentUser:
    """Authenticated, even when the account is flagged to change its password. Used only by the password-change flow."""
    return await authenticate(conn, request, authorization)


_mark(get_current_user_lenient, "authenticated")


async def get_current_user(user: Annotated[CurrentUser, Depends(get_current_user_lenient)]) -> CurrentUser:
    if user.must_change_password:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="password_change_required")
    return user


_mark(get_current_user, "authenticated")


def require(*codes: str, any_of: bool = False) -> Callable:
    """Dependency factory: the caller must hold every listed permission (or at least one with any_of=True)."""

    async def dependency(user: Annotated[CurrentUser, Depends(get_current_user)]) -> CurrentUser:
        held = [code for code in codes if user.has_permission(code)]
        if (any_of and not held) or (not any_of and len(held) != len(codes)):
            missing = [code for code in codes if code not in held]
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail=f"Missing permission: {', '.join(missing)}")
        return user

    return _mark(dependency, "permission", _required=tuple(codes), _any_of=any_of)


def require_dangerous(code: str) -> Callable:
    """A dangerous action needs its own fine-grained code and the global `dangerous_actions.execute` gate."""
    return require(code, "dangerous_actions.execute")


def public() -> None:
    """Marks a route as deliberately unauthenticated. The route guard test requires every route to say what it is."""
    return None


_mark(public, "public")
