from __future__ import annotations

from typing import Annotated

import asyncpg
from fastapi import APIRouter, Depends
from pydantic import BaseModel

from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, get_current_user, require
from app.repositories import access as access_repo

router = APIRouter(prefix=f"{settings.api_prefix}/access", tags=["access"])


class PermissionItem(BaseModel):
    id: str
    code: str
    group_name: str
    description: str | None
    is_dangerous: bool


class RoleItem(BaseModel):
    id: str
    name: str
    description: str | None
    is_system: bool
    scope_mode: str
    permissions: list[str]


class ScopeSummary(BaseModel):
    scope_mode: str
    device_groups: list[dict[str, str | None]]
    devices: list[dict[str, str | None]]
    interfaces: list[dict[str, str | None]]


@router.get("/permissions", response_model=list[PermissionItem])
async def list_permissions(
    _: Annotated[CurrentUser, Depends(require("permissions.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[PermissionItem]:
    rows = await conn.fetch("select id, code, group_name, description, is_dangerous from permissions order by group_name, code")
    return [PermissionItem(id=str(r["id"]), code=r["code"], group_name=r["group_name"], description=r["description"], is_dangerous=r["is_dangerous"]) for r in rows]


@router.get("/roles", response_model=list[RoleItem])
async def list_roles(
    _: Annotated[CurrentUser, Depends(require("roles.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[RoleItem]:
    rows = await conn.fetch(
        """
        select r.id, r.name, r.description, r.is_system, r.scope_mode,
               coalesce(array_agg(p.code order by p.code) filter (where p.code is not null), array[]::text[]) as permissions
        from roles r
        left join role_permissions rp on rp.role_id = r.id
        left join permissions p on p.id = rp.permission_id
        group by r.id order by r.name
        """
    )
    return [RoleItem(id=str(r["id"]), name=r["name"], description=r["description"], is_system=r["is_system"],
                     scope_mode=r["scope_mode"], permissions=list(r["permissions"])) for r in rows]


@router.get("/me/scope", response_model=ScopeSummary)
async def my_scope(
    user: Annotated[CurrentUser, Depends(get_current_user)],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> ScopeSummary:
    assigned = await access_repo.assigned_scope(conn, user.id)
    return ScopeSummary(scope_mode=user.scope_mode, **assigned)
