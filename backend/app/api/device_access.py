from __future__ import annotations

import uuid
from typing import Annotated, Any, Literal

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, Field, SecretStr, model_validator

from app.core.audit import write_audit
from app.core.config import settings
from app.core.crypto import EncryptionNotConfigured, EncryptionService
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import access_profiles as repo

router = APIRouter(prefix=f"{settings.api_prefix}/device-access-profiles", tags=["device-access"])

AUTH_PROTOCOLS = Literal["MD5", "SHA", "SHA224", "SHA256", "SHA384", "SHA512"]
PRIV_PROTOCOLS = Literal["DES", "AES", "AES192", "AES256"]


def _enc() -> EncryptionService:
    try:
        return EncryptionService.from_settings()
    except EncryptionNotConfigured as exc:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail="Credentials cannot be stored: encryption is not configured") from exc


class ProfileCreate(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    snmp_version: Literal["v1", "v2c", "v3"]
    snmp_community: SecretStr | None = Field(default=None, max_length=128)
    snmp_v3_username: str | None = Field(default=None, min_length=1, max_length=120)
    snmp_v3_auth_protocol: AUTH_PROTOCOLS | None = None
    snmp_v3_auth_secret: SecretStr | None = Field(default=None, min_length=8, max_length=256)
    snmp_v3_priv_protocol: PRIV_PROTOCOLS | None = None
    snmp_v3_priv_secret: SecretStr | None = Field(default=None, min_length=8, max_length=256)
    timeout_ms: int = Field(default=2000, ge=200, le=10000)
    retries: int = Field(default=1, ge=0, le=3)

    @model_validator(mode="after")
    def complete_for_version(self) -> "ProfileCreate":
        if self.snmp_version in ("v1", "v2c"):
            if self.snmp_community is None or not self.snmp_community.get_secret_value().strip():
                raise ValueError("a community is required for SNMP v1 and v2c")
        else:
            missing = [f for f in ("snmp_v3_username", "snmp_v3_auth_protocol", "snmp_v3_auth_secret", "snmp_v3_priv_protocol", "snmp_v3_priv_secret")
                       if getattr(self, f) is None]
            if missing:
                raise ValueError(f"SNMP v3 needs: {', '.join(missing)}")
        return self


class ProfileUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=120)
    timeout_ms: int | None = Field(default=None, ge=200, le=10000)
    retries: int | None = Field(default=None, ge=0, le=3)
    snmp_community: SecretStr | None = Field(default=None, min_length=1, max_length=128)
    snmp_v3_username: str | None = Field(default=None, min_length=1, max_length=120)
    snmp_v3_auth_protocol: AUTH_PROTOCOLS | None = None
    snmp_v3_auth_secret: SecretStr | None = Field(default=None, min_length=8, max_length=256)
    snmp_v3_priv_protocol: PRIV_PROTOCOLS | None = None
    snmp_v3_priv_secret: SecretStr | None = Field(default=None, min_length=8, max_length=256)


def _plain(model: BaseModel, **kwargs: Any) -> dict[str, Any]:
    data = model.model_dump(**kwargs)
    for key, value in list(data.items()):
        if isinstance(value, SecretStr):
            data[key] = value.get_secret_value()
    return data


def _uuid(value: str) -> str:
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


def _safe_view(profile: dict[str, Any]) -> dict[str, Any]:
    """What may be written to the audit log: shape and settings, never a secret or even its length."""
    keys = ("name", "snmp_version", "timeout_ms", "retries", "snmp_v3_username", "snmp_v3_auth_protocol", "snmp_v3_priv_protocol",
            "has_community", "has_auth_secret", "has_priv_secret")
    return {k: profile.get(k) for k in keys}


@router.get("")
async def list_profiles(
    _: Annotated[CurrentUser, Depends(require("device_access.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await repo.list_profiles(conn)


@router.get("/{profile_id}")
async def get_profile(
    profile_id: str,
    _: Annotated[CurrentUser, Depends(require("device_access.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    profile = await repo.get_profile(conn, _uuid(profile_id))
    if profile is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return profile


@router.post("", status_code=status.HTTP_201_CREATED)
async def create_profile(
    payload: ProfileCreate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("device_access.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    enc = _enc()
    if await conn.fetchval("select exists(select 1 from device_access_profiles where name = $1)", payload.name):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="A profile with this name already exists")
    async with conn.transaction():
        profile_id = await repo.create_profile(conn, enc, _plain(payload))
        profile = await repo.get_profile(conn, profile_id)
        await write_audit(conn, action="access_profile.created", actor_user_id=user.id, resource_type="access_profile", resource_id=profile_id,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), after=_safe_view(profile))
    return profile


@router.patch("/{profile_id}")
async def update_profile(
    profile_id: str,
    payload: ProfileUpdate,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("device_access.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    pid = _uuid(profile_id)
    before = await repo.get_profile(conn, pid)
    if before is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    changes = _plain(payload, exclude_unset=True, exclude_none=True)
    if before["snmp_version"] != "v3" and any(k.startswith("snmp_v3_") for k in changes):
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="This profile is not SNMP v3")
    if before["snmp_version"] == "v3" and "snmp_community" in changes:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="An SNMP v3 profile has no community")
    if "name" in changes and changes["name"] != before["name"] and await conn.fetchval(
        "select exists(select 1 from device_access_profiles where name = $1)", changes["name"]
    ):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="A profile with this name already exists")
    enc = _enc() if any(k in repo.SECRET_FIELDS for k in changes) else None
    async with conn.transaction():
        await repo.update_profile(conn, enc, pid, changes)
        after = await repo.get_profile(conn, pid)
        await write_audit(conn, action="access_profile.updated", actor_user_id=user.id, resource_type="access_profile", resource_id=pid,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), before=_safe_view(before), after=_safe_view(after),
                          metadata={"secrets_rotated": sorted(k for k in changes if k in repo.SECRET_FIELDS)})
    return after


@router.delete("/{profile_id}")
async def delete_profile(
    profile_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("device_access.manage"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    pid = _uuid(profile_id)
    before = await repo.get_profile(conn, pid)
    if before is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    if before["devices_using"]:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail=f"{before['devices_using']} device(s) still use this profile")
    async with conn.transaction():
        await repo.delete_profile(conn, pid)
        await write_audit(conn, action="access_profile.deleted", actor_user_id=user.id, resource_type="access_profile", resource_id=pid,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), before=_safe_view(before))
    return {"status": "deleted"}
