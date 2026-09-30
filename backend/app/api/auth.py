from __future__ import annotations

import hashlib
import secrets
from datetime import datetime, timedelta, timezone
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Request, Response, status
from pydantic import BaseModel, Field
from redis.asyncio import Redis
from redis.exceptions import RedisError

from app.core import totp
from app.core.audit import write_audit
from app.core.config import settings
from app.core.crypto import DecryptionError, EncryptionNotConfigured, EncryptionService
from app.core.database import get_conn
from app.core.netutil import client_ip, ip_allowed
from app.core.passwords import (
    SCHEME_ARGON2,
    check_strength,
    dummy_verify,
    hash_password,
    verify_password,
)
from app.core.ratelimit import LoginGuard
from app.core.redis import get_redis
from app.core.security import (
    CurrentUser,
    authenticate,
    fetch_role_permissions,
    get_current_user,
    get_current_user_lenient,
    hash_token,
    new_session_token,
    public,
    require,
)

router = APIRouter(prefix=f"{settings.api_prefix}/auth", tags=["auth"])

GENERIC_LOGIN_ERROR = "Invalid login or password"
GATE_APPS = {
    "grafana": "external_apps.grafana.view",
    "prometheus": "external_apps.prometheus.view",
    "alertmanager": "external_apps.alertmanager.view",
    "config-backups": "config_backups.view",
}


class LoginRequest(BaseModel):
    login: str = Field(min_length=1, max_length=160)
    password: str = Field(min_length=1, max_length=1024)
    twofa_pin: str | None = Field(default=None, max_length=16)
    recovery_code: str | None = Field(default=None, max_length=32)


class AuthUser(BaseModel):
    id: str
    username: str
    display_name: str
    email: str | None
    role: str
    scope_mode: str
    permissions: list[str]
    must_change_password: bool = False
    totp_enabled: bool = False
    auth_kind: str = "session"


class LoginResponse(BaseModel):
    token: str | None = None
    token_type: str = "Bearer"
    expires_at: datetime | None = None
    need_2fa: bool = False
    must_change_password: bool = False
    user: AuthUser | None = None


class SessionItem(BaseModel):
    id: str
    created_at: datetime
    last_activity_at: datetime
    expires_at: datetime
    ip_address: str | None
    user_agent: str | None
    current: bool


class PasswordChange(BaseModel):
    current_password: str = Field(min_length=1, max_length=1024)
    new_password: str = Field(min_length=1, max_length=1024)


class TotpEnroll(BaseModel):
    secret: str
    otpauth_uri: str


class TotpEnable(BaseModel):
    pin: str = Field(min_length=6, max_length=16)


class TotpDisable(BaseModel):
    password: str = Field(min_length=1, max_length=1024)
    twofa_pin: str | None = Field(default=None, max_length=16)
    recovery_code: str | None = Field(default=None, max_length=32)


def _encryption() -> EncryptionService:
    try:
        return EncryptionService.from_settings()
    except EncryptionNotConfigured as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail="Two-factor authentication is not available: encryption is not configured") from exc


def _hash_recovery_code(code: str) -> str:
    return hashlib.sha256(code.strip().lower().replace(" ", "").encode("utf-8")).hexdigest()


async def build_auth_user(conn: asyncpg.Connection, user: CurrentUser) -> AuthUser:
    row = await conn.fetchrow("select totp_enabled from users where id = $1::uuid", user.id)
    return AuthUser(
        id=user.id,
        username=user.username,
        display_name=user.display_name,
        email=user.email,
        role=user.role,
        scope_mode=user.scope_mode,
        permissions=sorted(user.permissions),
        must_change_password=user.must_change_password,
        totp_enabled=bool(row["totp_enabled"]) if row else False,
        auth_kind=user.auth_kind,
    )


async def _check_second_factor(conn: asyncpg.Connection, user: asyncpg.Record, pin: str | None, recovery_code: str | None) -> bool:
    if recovery_code:
        status_text = await conn.execute(
            """
            update totp_recovery_codes set used_at = now()
            where id = (select id from totp_recovery_codes where user_id = $1 and code_hash = $2 and used_at is null limit 1)
            """,
            user["id"], _hash_recovery_code(recovery_code),
        )
        return status_text.endswith(" 1")
    if not pin:
        return False
    try:
        secret = _encryption().decrypt(user["totp_secret_enc"], aad=str(user["id"]))
    except DecryptionError:
        return False
    counter = totp.verify(secret, pin, last_counter=user["totp_last_counter"])
    if counter is None:
        return False
    await conn.execute("update users set totp_last_counter = $2 where id = $1", user["id"], counter)
    return True


@router.post("/login", response_model=LoginResponse)
async def login(
    payload: LoginRequest,
    request: Request,
    response: Response,
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    redis: Annotated[Redis, Depends(get_redis)],
    _: Annotated[None, Depends(public)],
) -> LoginResponse:
    ip = client_ip(request)
    agent = request.headers.get("user-agent")
    guard = LoginGuard(redis, settings)

    async def attempt(success: bool, reason: str) -> None:
        await conn.execute(
            "insert into login_attempts (username, ip_address, success, reason) values ($1, $2::inet, $3, $4)",
            payload.login[:160], ip, success, reason,
        )

    async def fail(reason: str, user_id: str | None = None) -> HTTPException:
        try:
            await guard.record_failure(payload.login, ip)
        except RedisError:
            pass
        await attempt(False, reason)
        await write_audit(conn, action="auth.login_failed", actor_user_id=user_id, resource_type="auth", ip=ip, user_agent=agent,
                          metadata={"username": payload.login[:160], "reason": reason})
        return HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail=GENERIC_LOGIN_ERROR)

    try:
        locked = await guard.locked_for(payload.login, ip)
    except RedisError as exc:
        # Cannot tell whether this caller is locked out, so do not let them try.
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail="Login is temporarily unavailable") from exc
    if locked:
        await attempt(False, "locked")
        raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail="Too many attempts, try again later",
                            headers={"Retry-After": str(locked)})

    user = await conn.fetchrow(
        """
        select u.id, u.username, u.email, u.display_name, u.role_id, u.password_hash, u.hash_scheme, u.is_active,
               u.must_change_password, u.totp_enabled, u.totp_secret_enc, u.totp_last_counter,
               u.strict_ip_enabled, u.allowed_ips
        from users u where u.username = $1
        """,
        payload.login,
    )
    if user is None or not user["is_active"] or not user["password_hash"]:
        dummy_verify(payload.password)
        raise await fail("unknown_or_disabled")

    matches, needs_rehash = verify_password(user["password_hash"], payload.password)
    if not matches:
        raise await fail("bad_password", str(user["id"]))

    if user["strict_ip_enabled"] and not ip_allowed(ip, [str(n) for n in (user["allowed_ips"] or [])]):
        await attempt(False, "ip_not_allowed")
        await write_audit(conn, action="auth.login_denied_ip", actor_user_id=str(user["id"]), resource_type="auth", ip=ip, user_agent=agent)
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Access from this address is not allowed for this user")

    if user["totp_enabled"]:
        if not payload.twofa_pin and not payload.recovery_code:
            return LoginResponse(need_2fa=True)
        if not await _check_second_factor(conn, user, payload.twofa_pin, payload.recovery_code):
            raise await fail("bad_second_factor", str(user["id"]))

    token = new_session_token()
    expires_at = datetime.now(timezone.utc) + timedelta(hours=settings.session_ttl_hours)
    async with conn.transaction():
        if needs_rehash:
            await conn.execute(
                "update users set password_hash = $2, hash_scheme = $3, password_changed_at = coalesce(password_changed_at, now()) where id = $1",
                user["id"], hash_password(payload.password), SCHEME_ARGON2,
            )
        await conn.execute(
            """
            insert into user_sessions (user_id, token_hash, ip_address, user_agent, expires_at, mfa_verified)
            values ($1, $2, $3::inet, $4, $5, $6)
            """,
            user["id"], hash_token(token), ip, agent, expires_at, bool(user["totp_enabled"]),
        )
        await conn.execute("update users set last_login_at = now(), updated_at = now() where id = $1", user["id"])
        await attempt(True, "ok")
        await write_audit(conn, action="auth.login", actor_user_id=str(user["id"]), resource_type="auth", ip=ip, user_agent=agent,
                          metadata={"upgraded_password_hash": bool(needs_rehash)})

    try:
        await guard.record_success(payload.login, ip)
    except RedisError:
        pass

    response.set_cookie(
        key=settings.session_cookie_name, value=token, httponly=True, samesite="lax",
        secure=settings.is_production, max_age=settings.session_ttl_hours * 3600, path="/",
    )
    current = CurrentUser(
        id=str(user["id"]), username=user["username"], display_name=user["display_name"], email=user["email"],
        role="", role_id=str(user["role_id"]), scope_mode="", must_change_password=bool(user["must_change_password"]),
    )
    role = await conn.fetchrow("select name, scope_mode from roles where id = $1", user["role_id"])
    permissions = await fetch_role_permissions(conn, str(user["role_id"]))
    auth_user = AuthUser(
        id=current.id, username=current.username, display_name=current.display_name, email=current.email,
        role=role["name"], scope_mode=role["scope_mode"], permissions=permissions,
        must_change_password=current.must_change_password, totp_enabled=bool(user["totp_enabled"]),
    )
    return LoginResponse(token=token, expires_at=expires_at, must_change_password=current.must_change_password, user=auth_user)


@router.get("/session", response_model=AuthUser)
async def session(
    user: Annotated[CurrentUser, Depends(get_current_user_lenient)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> AuthUser:
    return await build_auth_user(conn, user)


@router.post("/logout")
async def logout(
    request: Request,
    response: Response,
    user: Annotated[CurrentUser, Depends(get_current_user_lenient)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    if user.auth_kind != "session":
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="An API token cannot be logged out; revoke it instead")
    async with conn.transaction():
        await conn.execute("update user_sessions set revoked_at = now() where id = $1::uuid and revoked_at is null", user.credential_id)
        await write_audit(conn, action="auth.logout", actor_user_id=user.id, resource_type="auth", ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"))
    response.delete_cookie(key=settings.session_cookie_name, path="/")
    return {"status": "logged_out"}


@router.get("/sessions", response_model=list[SessionItem])
async def list_sessions(
    user: Annotated[CurrentUser, Depends(get_current_user)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[SessionItem]:
    rows = await conn.fetch(
        """
        select id, created_at, last_activity_at, expires_at, ip_address, user_agent
        from user_sessions
        where user_id = $1::uuid and revoked_at is null and expires_at > now()
        order by created_at desc
        """,
        user.id,
    )
    return [
        SessionItem(id=str(r["id"]), created_at=r["created_at"], last_activity_at=r["last_activity_at"], expires_at=r["expires_at"],
                    ip_address=str(r["ip_address"]) if r["ip_address"] else None, user_agent=r["user_agent"],
                    current=str(r["id"]) == user.credential_id)
        for r in rows
    ]


@router.delete("/sessions/{session_id}")
async def revoke_session(
    session_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(get_current_user)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    try:
        owner = await conn.fetchval("select user_id from user_sessions where id = $1::uuid", session_id)
    except asyncpg.DataError:
        owner = None
    if owner is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Session not found")
    if str(owner) != user.id and not user.has_permission("users.manage"):
        # Do not confirm that someone else's session exists.
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Session not found")
    async with conn.transaction():
        await conn.execute("update user_sessions set revoked_at = now() where id = $1::uuid and revoked_at is null", session_id)
        await write_audit(conn, action="auth.session_revoked", actor_user_id=user.id, resource_type="session", resource_id=session_id,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                          metadata={"owner": str(owner)})
    return {"status": "revoked"}


@router.post("/password")
async def change_password(
    payload: PasswordChange,
    request: Request,
    user: Annotated[CurrentUser, Depends(get_current_user_lenient)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    if user.auth_kind != "session":
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Passwords are changed from an interactive session")
    row = await conn.fetchrow("select password_hash from users where id = $1::uuid", user.id)
    matches, _ = verify_password(row["password_hash"], payload.current_password)
    if not matches:
        await write_audit(conn, action="auth.password_change_failed", actor_user_id=user.id, resource_type="auth", ip=user.client_ip)
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Current password is incorrect")
    problems = check_strength(payload.new_password, settings.password_min_length)
    if problems:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail={"password_problems": problems})
    if payload.new_password == payload.current_password:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail={"password_problems": ["SAME_AS_CURRENT"]})
    async with conn.transaction():
        await conn.execute(
            """
            update users set password_hash = $2, hash_scheme = $3, password_changed_at = now(),
                             must_change_password = false, updated_at = now()
            where id = $1::uuid
            """,
            user.id, hash_password(payload.new_password), SCHEME_ARGON2,
        )
        # Every other session was issued under the old password.
        await conn.execute(
            "update user_sessions set revoked_at = now() where user_id = $1::uuid and id <> $2::uuid and revoked_at is null",
            user.id, user.credential_id,
        )
        await write_audit(conn, action="auth.password_changed", actor_user_id=user.id, resource_type="auth", ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"))
    return {"status": "password_changed"}


@router.post("/2fa/enroll", response_model=TotpEnroll)
async def enroll_2fa(
    user: Annotated[CurrentUser, Depends(get_current_user)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> TotpEnroll:
    encryption = _encryption()
    if await conn.fetchval("select totp_enabled from users where id = $1::uuid", user.id):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Two-factor authentication is already enabled")
    secret = totp.generate_secret()
    await conn.execute(
        "update users set totp_secret_enc = $2, totp_last_counter = null where id = $1::uuid",
        user.id, encryption.encrypt(secret, aad=user.id),
    )
    return TotpEnroll(secret=secret, otpauth_uri=totp.otpauth_uri(secret, user.username, settings.app_name))


@router.post("/2fa/enable")
async def enable_2fa(
    payload: TotpEnable,
    request: Request,
    user: Annotated[CurrentUser, Depends(get_current_user)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    row = await conn.fetchrow("select totp_enabled, totp_secret_enc from users where id = $1::uuid", user.id)
    if row["totp_enabled"]:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Two-factor authentication is already enabled")
    if not row["totp_secret_enc"]:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Start enrolment first")
    try:
        secret = _encryption().decrypt(row["totp_secret_enc"], aad=user.id)
    except DecryptionError as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Enrolment is invalid, start again") from exc
    counter = totp.verify(secret, payload.pin)
    if counter is None:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid code")
    codes = [f"{secrets.token_hex(4)}-{secrets.token_hex(4)}" for _ in range(8)]
    async with conn.transaction():
        await conn.execute("update users set totp_enabled = true, totp_last_counter = $2 where id = $1::uuid", user.id, counter)
        await conn.execute("delete from totp_recovery_codes where user_id = $1::uuid", user.id)
        for code in codes:
            await conn.execute("insert into totp_recovery_codes (user_id, code_hash) values ($1::uuid, $2)", user.id, _hash_recovery_code(code))
        await write_audit(conn, action="auth.2fa_enabled", actor_user_id=user.id, resource_type="auth", ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"))
    return {"status": "enabled", "recovery_codes": codes}


@router.post("/2fa/disable")
async def disable_2fa(
    payload: TotpDisable,
    request: Request,
    user: Annotated[CurrentUser, Depends(get_current_user)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    row = await conn.fetchrow(
        "select id, password_hash, totp_enabled, totp_secret_enc, totp_last_counter from users where id = $1::uuid", user.id
    )
    if not row["totp_enabled"]:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Two-factor authentication is not enabled")
    matches, _ = verify_password(row["password_hash"], payload.password)
    if not matches or not await _check_second_factor(conn, row, payload.twofa_pin, payload.recovery_code):
        await write_audit(conn, action="auth.2fa_disable_failed", actor_user_id=user.id, resource_type="auth", ip=user.client_ip)
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Password or code is incorrect")
    async with conn.transaction():
        await conn.execute("update users set totp_enabled = false, totp_secret_enc = null, totp_last_counter = null where id = $1::uuid", user.id)
        await conn.execute("delete from totp_recovery_codes where user_id = $1::uuid", user.id)
        await write_audit(conn, action="auth.2fa_disabled", actor_user_id=user.id, resource_type="auth", ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"))
    return {"status": "disabled"}


@router.get("/verify", include_in_schema=False)
async def verify_gate(
    app: str,
    request: Request,
    response: Response,
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    _: Annotated[None, Depends(public)],
) -> Response:
    """Called by Nginx `auth_request` before it forwards a request to a proxied app (Grafana, Prometheus, ...).

    204 with identity headers when the caller has a valid credential and holds the app's permission, else 401 or 403.
    Marked public for the route guard because it authenticates by itself and never returns data.
    """
    required = GATE_APPS.get(app)
    if required is None:
        return Response(status_code=status.HTTP_403_FORBIDDEN)
    try:
        user = await authenticate(conn, request, request.headers.get("authorization"))
    except HTTPException as exc:
        return Response(status_code=exc.status_code)
    if user.must_change_password or not user.has_permission(required):
        return Response(status_code=status.HTTP_403_FORBIDDEN)
    headers = {"X-Auth-User": user.username, "X-Auth-Role": user.role}
    if app == "grafana":
        headers["X-Auth-Grafana-Role"] = "Admin" if user.has_permission("external_apps.grafana.admin") else "Viewer"
    return Response(status_code=status.HTTP_204_NO_CONTENT, headers=headers)
