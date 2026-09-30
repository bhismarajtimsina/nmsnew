from __future__ import annotations

import time
import uuid
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Body, Depends, HTTPException, Query, Request, status

from app.alerting.ingest import process_webhook
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import events as repo

router = APIRouter(prefix=settings.api_prefix, tags=["events"])

# When this process started, for the Alertmanager ingestion grace period (see app/alerting/ingest.py).
_STARTED_AT = time.monotonic()


def _uuid_or_404(value: str) -> str:
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc


@router.post("/webhooks/alertmanager", include_in_schema=False)
async def alertmanager_webhook(
    payload: Annotated[dict[str, Any], Body()],
    _: Annotated[CurrentUser, Depends(require("events.ingest"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    """Alertmanager's own webhook_config points here. Meant for a service API token scoped to events.ingest only —
    never a user session — so a leaked credential here can create and resolve alarms and nothing else."""
    summary = await process_webhook(conn, payload, worker_started_at=_STARTED_AT)
    return {
        "created": summary.created, "duplicate": summary.duplicate, "resolved": summary.resolved,
        "resolved_skipped_grace": summary.resolved_skipped_grace, "resolved_not_found": summary.resolved_not_found,
        "errors": summary.errors,
    }


@router.get("/events")
async def list_events(
    user: Annotated[CurrentUser, Depends(require("events.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    open_only: Annotated[bool, Query()] = True,
    device_id: Annotated[str | None, Query()] = None,
    name: Annotated[str | None, Query(max_length=100)] = None,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> dict[str, Any]:
    did = _uuid_or_404(device_id) if device_id else None
    rows, total = await repo.list_events(conn, user, open_only=open_only, device_id=did, name=name, limit=limit, offset=offset)
    return {"items": [repo.as_dict(r) for r in rows], "total": total, "limit": limit, "offset": offset}


@router.get("/events/{event_id}")
async def get_event(
    event_id: str,
    user: Annotated[CurrentUser, Depends(require("events.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, Any]:
    row = await repo.get_event(conn, user, _uuid_or_404(event_id))
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return repo.as_dict(row)


@router.put("/events/{event_id}/resolve")
async def resolve_event(
    event_id: str,
    request: Request,
    user: Annotated[CurrentUser, Depends(require("events.resolve"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> dict[str, str]:
    eid = _uuid_or_404(event_id)
    try:
        async with conn.transaction():
            changed = await repo.resolve_event(conn, user, eid, user.id)
            if not changed:
                raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Already resolved")
            await write_audit(conn, action="event.resolved", actor_user_id=user.id, resource_type="event", resource_id=eid,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"))
    except repo.NotVisible as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found") from exc
    return {"status": "resolved"}


@router.get("/incidents")
async def list_incidents(
    user: Annotated[CurrentUser, Depends(require("events.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
    device_id: Annotated[str | None, Query()] = None,
) -> dict[str, Any]:
    from app.alerting.incidents import group

    did = _uuid_or_404(device_id) if device_id else None
    rows = await repo.open_events_for_grouping(conn, user, did)
    incidents = group(rows)
    return {
        "incidents": [
            {"key": i.key, "kind": i.kind, "device_id": i.device_id, "device_ip": i.device_ip, "where": i.where,
             "severity": i.severity, "events": i.events, "alarms": i.alarms, "started_at": i.started_at.isoformat(),
             "latest_at": i.latest_at.isoformat(), "event_ids": i.event_ids}
            for i in incidents
        ],
        "open_events": len(rows),
    }


@router.get("/alarm-rules")
async def list_alarm_rules(
    _: Annotated[CurrentUser, Depends(require("events.view"))],
    conn: Annotated[asyncpg.Connection, Depends(get_conn)],
) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        "select id, group_name, alert_name, expression, for_duration, severity, audience, isp_focus, reseller_focus, "
        "annotation_summary, annotation_description, enabled, internal from alarm_rules order by group_name, alert_name"
    )
    return [{**dict(r), "id": str(r["id"])} for r in rows]
