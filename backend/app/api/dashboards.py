"""Role dashboards (Plan 23): the dashboards a user may open, their default, and the live widgets' data. Which widget
is on which dashboard, and who may see it, is decided in app/services/dashboards.py; the data comes from the scoped
repository app/repositories/dashboards.py."""
from __future__ import annotations

from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Query, status

from app.api import schemas
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import dashboards as repo
from app.services import dashboards as service

router = APIRouter(prefix=settings.api_prefix, tags=["dashboards"])

_BASE = f"{settings.api_prefix}/dashboards/widgets"
# Where each live widget's data comes from. The events table reuses the events list.
ENDPOINTS = {
    "device_status": f"{_BASE}/device-status",
    "events_by_severity": f"{_BASE}/events-by-severity",
    "events_by_name": f"{_BASE}/events-by-name",
    "events_table": f"{settings.api_prefix}/events?open_only=true&limit=8",
    "ports_down": f"{_BASE}/ports-down",
    "poller_health": f"{_BASE}/poller-health",
    "error_calling_by_device": f"{_BASE}/error-calling-by-device",
    "system_stat": f"{_BASE}/system-stat",
    "latest_system_actions": f"{_BASE}/latest-system-actions",
    "last_user_activity": f"{_BASE}/last-user-activity",
}


def _widget(user: CurrentUser, key: str) -> CurrentUser:
    """403 unless this user may see the widget: its permission, and a role that sees everything for a NOC-wide one."""
    if not service.widget_allowed(user, key):
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Not allowed to view this widget")
    return user


def _for(key: str):
    """The widget's permission (as a route mark the route guard can see), then the NOC-wide rule. A default rather
    than an annotation: this module postpones annotations, and a closure name cannot be resolved from a string."""
    async def dependency(user: CurrentUser = Depends(require(service.WIDGETS[key].permission))) -> CurrentUser:  # noqa: B008
        return _widget(user, key)
    return dependency


@router.get("/dashboards", response_model=schemas.DashboardList)
async def list_dashboards(user: Annotated[CurrentUser, Depends(require("portal.view"))]) -> dict[str, Any]:
    """The dashboards this user may open, with the widgets they may see on each, and the one their role opens on."""
    dashboards = []
    for key in service.available_dashboards(user):
        board = service.DASHBOARDS[key]
        dashboards.append({
            "key": key, "title": board.title, "noc_wide": board.noc_wide,
            "widgets": [{"key": w.key, "title": w.title, "state": "pending" if w.pending else "live",
                         "endpoint": None if w.pending else ENDPOINTS[w.key], "pending_reason": w.pending}
                        for w in service.dashboard_widgets(user, key)],
        })
    return {"default": service.default_dashboard(user), "dashboards": dashboards}


@router.get("/dashboards/widgets/device-status", response_model=schemas.DeviceStatusWidget)
async def device_status(user: Annotated[CurrentUser, Depends(_for("device_status"))],
                        conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
    return await repo.device_status(conn, user)


@router.get("/dashboards/widgets/events-by-severity", response_model=schemas.EventCountsWidget)
async def events_by_severity(user: Annotated[CurrentUser, Depends(_for("events_by_severity"))],
                             conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
    return {"items": await repo.events_by_severity(conn, user)}


@router.get("/dashboards/widgets/events-by-name", response_model=schemas.EventNamesWidget)
async def events_by_name(user: Annotated[CurrentUser, Depends(_for("events_by_name"))],
                         conn: Annotated[asyncpg.Connection, Depends(get_conn)],
                         limit: Annotated[int, Query(ge=1, le=50)] = 10) -> dict[str, Any]:
    return {"items": await repo.events_by_name(conn, user, limit)}


@router.get("/dashboards/widgets/ports-down", response_model=schemas.PortsDownWidget)
async def ports_down(user: Annotated[CurrentUser, Depends(_for("ports_down"))],
                     conn: Annotated[asyncpg.Connection, Depends(get_conn)],
                     limit: Annotated[int, Query(ge=1, le=500)] = 50) -> dict[str, Any]:
    items, total = await repo.ports_down(conn, user, limit)
    return {"items": items, "total": total, "limit": limit}


@router.get("/dashboards/widgets/poller-health", response_model=schemas.PollerHealthWidget)
async def poller_health(user: Annotated[CurrentUser, Depends(_for("poller_health"))],
                        conn: Annotated[asyncpg.Connection, Depends(get_conn)],
                        hours: Annotated[int, Query(ge=1, le=720)] = 24,
                        limit: Annotated[int, Query(ge=1, le=100)] = 30) -> dict[str, Any]:
    return await repo.poller_health(conn, user, hours, limit)


@router.get("/dashboards/widgets/error-calling-by-device", response_model=schemas.ErrorCallingWidget)
async def error_calling_by_device(user: Annotated[CurrentUser, Depends(_for("error_calling_by_device"))],
                                  conn: Annotated[asyncpg.Connection, Depends(get_conn)],
                                  limit: Annotated[int, Query(ge=1, le=100)] = 30) -> dict[str, Any]:
    return await repo.error_calling_by_device(conn, user, limit)


@router.get("/dashboards/widgets/system-stat", response_model=schemas.SystemStatWidget)
async def system_stat(user: Annotated[CurrentUser, Depends(_for("system_stat"))],
                      conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
    return await repo.system_stat(conn, user)


@router.get("/dashboards/widgets/latest-system-actions", response_model=schemas.SystemActionsWidget)
async def latest_system_actions(_: Annotated[CurrentUser, Depends(_for("latest_system_actions"))],
                                conn: Annotated[asyncpg.Connection, Depends(get_conn)],
                                limit: Annotated[int, Query(ge=1, le=50)] = 10) -> dict[str, Any]:
    return {"items": await repo.latest_system_actions(conn, limit)}


@router.get("/dashboards/widgets/last-user-activity", response_model=schemas.UserActivityWidget)
async def last_user_activity(_: Annotated[CurrentUser, Depends(_for("last_user_activity"))],
                             conn: Annotated[asyncpg.Connection, Depends(get_conn)],
                             limit: Annotated[int, Query(ge=1, le=50)] = 10) -> dict[str, Any]:
    return {"items": await repo.last_user_activity(conn, limit)}
