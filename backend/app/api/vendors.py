from __future__ import annotations

from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, Field

from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import oids as oid_repo
from app.repositories import vendors as vendor_repo

router = APIRouter(prefix=settings.api_prefix, tags=["registries"])

_SLUG = r"^[a-z0-9]+(-[a-z0-9]+)*$"


class PollingToggle(BaseModel):
    enabled: bool
    reason: str | None = Field(default=None, max_length=500)


class VendorUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=120)
    notes: str | None = Field(default=None, max_length=2000)
    discovery_timeout_ms: int | None = Field(default=None, ge=200, le=10000)
    discovery_retries: int | None = Field(default=None, ge=0, le=3)


def _need_reason(payload: PollingToggle) -> str | None:
    reason = (payload.reason or "").strip() or None
    if not payload.enabled and reason is None:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Say why polling is being switched off")
    return reason


@router.get("/vendors")
async def list_vendors(
    _: Annotated[CurrentUser, Depends(require("vendors.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await vendor_repo.list_vendors(conn)


@router.get("/vendors/{slug}")
async def get_vendor(
    slug: str,
    _: Annotated[CurrentUser, Depends(require("vendors.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    vendor = await vendor_repo.get_vendor(conn, slug)
    if vendor is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Vendor not found")
    return vendor


@router.patch("/vendors/{slug}")
async def update_vendor(
    slug: str,
    payload: VendorUpdate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("vendors.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    before = await vendor_repo.get_vendor(conn, slug)
    if before is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Vendor not found")
    changes = payload.model_dump(exclude_unset=True, exclude_none=True)
    async with conn.transaction():
        await vendor_repo.update_vendor(conn, slug, changes)
        after = await vendor_repo.get_vendor(conn, slug)
        await write_audit(conn, action="vendor.updated", actor_user_id=user.id, resource_type="vendor", resource_id=before["id"],
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                          before={k: before[k] for k in changes}, after={k: after[k] for k in changes})
    return after


@router.put("/vendors/{slug}/polling")
async def toggle_vendor_polling(
    slug: str,
    payload: PollingToggle,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("vendors.polling.toggle"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    reason = _need_reason(payload)
    before = await vendor_repo.get_vendor(conn, slug)
    if before is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Vendor not found")
    async with conn.transaction():
        await vendor_repo.set_vendor_polling(conn, slug, payload.enabled, reason, user.id)
        await write_audit(conn, action="vendor.polling_enabled" if payload.enabled else "vendor.polling_disabled", actor_user_id=user.id,
                          resource_type="vendor", resource_id=before["id"], ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                          before={"polling_enabled": before["polling_enabled"]}, after={"polling_enabled": payload.enabled},
                          metadata={"reason": reason, "slug": slug})
    return await vendor_repo.get_vendor(conn, slug)


@router.put("/vendors/{vendor_slug}/families/{family_slug}/polling")
async def toggle_family_polling(
    vendor_slug: str,
    family_slug: str,
    payload: PollingToggle,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("vendors.polling.toggle"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    reason = _need_reason(payload)
    before = await vendor_repo.get_family(conn, vendor_slug, family_slug)
    if before is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Model family not found")
    async with conn.transaction():
        await vendor_repo.set_family_polling(conn, vendor_slug, family_slug, payload.enabled, reason, user.id)
        await write_audit(conn, action="family.polling_enabled" if payload.enabled else "family.polling_disabled", actor_user_id=user.id,
                          resource_type="vendor_model_family", resource_id=before["id"], ip=user.client_ip,
                          user_agent=request.headers.get("user-agent"), before={"polling_enabled": before["polling_enabled"]},
                          after={"polling_enabled": payload.enabled}, metadata={"reason": reason, "family": family_slug, "vendor": vendor_slug})
    return await vendor_repo.get_family(conn, vendor_slug, family_slug)


@router.get("/capabilities")
async def list_capabilities(
    _: Annotated[CurrentUser, Depends(require("vendors.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await vendor_repo.list_capabilities(conn)


@router.get("/vendors/{vendor_slug}/families/{family_slug}/capabilities")
async def family_capabilities(
    vendor_slug: str,
    family_slug: str,
    _: Annotated[CurrentUser, Depends(require("vendors.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    if await vendor_repo.get_family(conn, vendor_slug, family_slug) is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Model family not found")
    return await vendor_repo.family_capabilities(conn, vendor_slug, family_slug)


@router.get("/oid-profiles")
async def list_oid_profiles(
    _: Annotated[CurrentUser, Depends(require("oid_profiles.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await oid_repo.list_profiles(conn)


@router.get("/oid-profiles/{profile_id}")
async def get_oid_profile(
    profile_id: str,
    _: Annotated[CurrentUser, Depends(require("oid_profiles.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    import uuid

    try:
        uuid.UUID(profile_id)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Profile not found") from exc
    profile = await oid_repo.get_profile(conn, profile_id)
    if profile is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Profile not found")
    return profile
