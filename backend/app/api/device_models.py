from __future__ import annotations

import uuid
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, Field

from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.registry.detection import detect
from app.repositories import device_models as repo

router = APIRouter(prefix=settings.api_prefix, tags=["device-models"])


class DetectRequest(BaseModel):
    sys_descr: str | None = Field(default=None, max_length=2000)
    sys_object_id: str | None = Field(default=None, max_length=255)


class DetectResponse(BaseModel):
    status: str
    model: dict[str, Any] | None = None
    candidates: list[dict[str, Any]] = Field(default_factory=list)


@router.get("/device-models")
async def list_models(
    _: Annotated[CurrentUser, Depends(require("device_models.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    return await repo.list_models(conn)


@router.get("/device-models/{model_id}")
async def get_model(
    model_id: str,
    _: Annotated[CurrentUser, Depends(require("device_models.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    try:
        uuid.UUID(model_id)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    model = await repo.get_model(conn, model_id)
    if model is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return model


@router.post("/device-models/detect", response_model=DetectResponse)
async def detect_model(
    payload: DetectRequest,
    _: Annotated[CurrentUser, Depends(require("device_models.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> DetectResponse:
    rules = await repo.load_rules(conn)
    result = detect(rules, sys_descr=payload.sys_descr, sys_object_id=payload.sys_object_id)
    to_dict = lambda r: {"id": r.id, "key": r.key, "name": r.name, "vendor_slug": r.vendor_slug, "family_slug": r.family_slug, "device_type": r.device_type}  # noqa: E731
    return DetectResponse(
        status=result.status, model=to_dict(result.matched) if result.matched else None,
        candidates=[to_dict(c) for c in result.candidates],
    )
