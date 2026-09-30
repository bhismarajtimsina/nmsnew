from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Annotated

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, Field

from app.access.catalogue import SERVICE_ONLY_PERMISSIONS
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, hash_token, new_api_token, require

router = APIRouter(prefix=f"{settings.api_prefix}/api-tokens", tags=["api-tokens"])


class TokenCreate(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    permissions: list[str] = Field(min_length=1, max_length=200)
    expires_in_days: int | None = Field(default=None, ge=1, le=3650)


class TokenItem(BaseModel):
    id: str
    name: str
    permissions: list[str]
    expires_at: datetime | None
    last_used_at: datetime | None
    revoked_at: datetime | None
    created_at: datetime
    owner: str | None = None


class TokenCreated(TokenItem):
    token: str


@router.post("", response_model=TokenCreated, status_code=status.HTTP_201_CREATED)
async def create_token(
    payload: TokenCreate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("api_tokens.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> TokenCreated:
    if user.auth_kind == "api_token":
        # A token that can mint tokens turns one leaked credential into a permanent foothold.
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="API tokens cannot create API tokens")
    wanted = sorted(set(payload.permissions))
    if "api_tokens.manage" in wanted:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="A token cannot hold api_tokens.manage")
    unknown = [c for c in wanted if not await conn.fetchval("select exists(select 1 from permissions where code = $1)", c)]
    if unknown:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=f"Unknown permissions: {', '.join(unknown)}")
    beyond = [c for c in wanted if not user.has_permission(c) and c not in SERVICE_ONLY_PERMISSIONS]
    if beyond:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail=f"You cannot grant permissions you do not hold: {', '.join(beyond)}")
    if await conn.fetchval("select exists(select 1 from api_tokens where user_id = $1::uuid and name = $2)", user.id, payload.name):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="A token with this name already exists")

    token = new_api_token()
    expires_at = datetime.now(timezone.utc) + timedelta(days=payload.expires_in_days) if payload.expires_in_days else None
    async with conn.transaction():
        row = await conn.fetchrow(
            """
            insert into api_tokens (user_id, name, token_hash, permissions, expires_at)
            values ($1::uuid, $2, $3, $4, $5)
            returning id, name, permissions, expires_at, last_used_at, revoked_at, created_at
            """,
            user.id, payload.name, hash_token(token), wanted, expires_at,
        )
        await write_audit(conn, action="api_token.created", actor_user_id=user.id, resource_type="api_token", resource_id=str(row["id"]),
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                          after={"name": payload.name, "permissions": wanted, "expires_at": str(expires_at) if expires_at else None})
    return TokenCreated(id=str(row["id"]), name=row["name"], permissions=list(row["permissions"]), expires_at=row["expires_at"],
                        last_used_at=row["last_used_at"], revoked_at=row["revoked_at"], created_at=row["created_at"], token=token)


@router.get("", response_model=list[TokenItem])
async def list_tokens(
    user: Annotated[CurrentUser, Depends(require("api_tokens.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    all_users: bool = False,
) -> list[TokenItem]:
    if all_users and not user.has_permission("users.manage"):
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Missing permission: users.manage")
    rows = await conn.fetch(
        """
        select t.id, t.name, t.permissions, t.expires_at, t.last_used_at, t.revoked_at, t.created_at, u.username
        from api_tokens t join users u on u.id = t.user_id
        where ($2::boolean or t.user_id = $1::uuid)
        order by t.created_at desc
        """,
        user.id, all_users,
    )
    return [TokenItem(id=str(r["id"]), name=r["name"], permissions=list(r["permissions"]), expires_at=r["expires_at"],
                      last_used_at=r["last_used_at"], revoked_at=r["revoked_at"], created_at=r["created_at"], owner=r["username"])
            for r in rows]


@router.delete("/{token_id}")
async def revoke_token(
    token_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("api_tokens.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    try:
        row = await conn.fetchrow("select id, user_id, name from api_tokens where id = $1::uuid", token_id)
    except asyncpg.DataError:
        row = None
    if row is None or (str(row["user_id"]) != user.id and not user.has_permission("users.manage")):
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Token not found")
    async with conn.transaction():
        await conn.execute("update api_tokens set revoked_at = now() where id = $1 and revoked_at is null", row["id"])
        await write_audit(conn, action="api_token.revoked", actor_user_id=user.id, resource_type="api_token", resource_id=str(row["id"]),
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), metadata={"name": row["name"]})
    return {"status": "revoked"}
