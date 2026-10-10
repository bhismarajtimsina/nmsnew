"""Transport paths (Plan 27): routes between two endpoint devices made of ordered links, their state, and redundancy
groups. Viewing needs `paths.view` and both endpoints in scope; changing needs `paths.edit` (segments also need every
link fully in scope). The list shows the state the scheduler last stored; one path's detail computes it now."""
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path, Request, status

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import paths as repo
from app.topology import path_service
from app.topology.paths import chain_gaps, group_state

router = APIRouter(prefix=settings.api_prefix, tags=["topology"])
PathId = Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")]
View = Annotated[CurrentUser, Depends(require("paths.view"))]
Edit = Annotated[CurrentUser, Depends(require("paths.edit"))]
Conn = Annotated[asyncpg.Connection, Depends(get_conn)]


def _safe(path: dict[str, Any]) -> dict[str, Any]:
    keys = ("name", "group_key", "priority", "enabled", "description")
    return {**{k: path[k] for k in keys}, "endpoints": [path["endpoint_a"]["device_id"], path["endpoint_b"]["device_id"]]}


async def _detail(conn: asyncpg.Connection, user: CurrentUser, path_id: str) -> dict[str, Any]:
    row = await repo.get_path(conn, user, path_id)
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return path_service.present_detail(row, await repo.path_hops(conn, user, path_id), settings.paths_degraded_latency_ms)


@router.get("/paths", response_model=schemas.PathList)
async def list_paths(user: View, conn: Conn) -> dict[str, Any]:
    return {"items": [path_service.present(r) for r in await repo.list_paths(conn, user)]}


@router.get("/paths/groups", response_model=schemas.PathGroupList)
async def path_groups(user: View, conn: Conn) -> dict[str, Any]:
    """Redundancy groups from the paths the caller may see, with their stored states."""
    groups: dict[str, list[tuple[str, str, int, str]]] = {}
    for row in await repo.list_paths(conn, user):
        if row["group_key"] and row["enabled"]:
            groups.setdefault(row["group_key"], []).append((str(row["id"]), row["name"], row["priority"], row["stored_state"] or "unknown"))
    return {"items": [{"group_key": key, **group_state(members)} for key, members in sorted(groups.items())]}  # type: ignore[arg-type]


@router.get("/paths/{path_id}", response_model=schemas.PathDetail)
async def get_path(path_id: PathId, user: View, conn: Conn) -> dict[str, Any]:
    return await _detail(conn, user, path_id)


@router.post("/paths", response_model=schemas.PathDetail, status_code=status.HTTP_201_CREATED)
async def create_path(body: schemas.PathCreate, request: Request, user: Edit, conn: Conn) -> dict[str, Any]:
    try:
        async with conn.transaction():
            path_id = await repo.create_path(conn, user, body.model_dump())
            detail = await _detail(conn, user, path_id)
            await write_audit(conn, action="path.created", actor_user_id=user.id, resource_type="path", resource_id=path_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), after=_safe(detail))
    except LookupError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc
    except repo.PathRejected as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    return detail


@router.put("/paths/{path_id}", response_model=schemas.PathDetail)
async def update_path(path_id: PathId, body: schemas.PathUpdate, request: Request, user: Edit, conn: Conn) -> dict[str, Any]:
    async with conn.transaction():
        before = await _detail(conn, user, path_id)
        await repo.update_path(conn, user, path_id, body.model_dump(exclude_unset=True))
        after = await _detail(conn, user, path_id)
        await write_audit(conn, action="path.updated", actor_user_id=user.id, resource_type="path", resource_id=path_id,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), before=_safe(before), after=_safe(after))
    return after


@router.put("/paths/{path_id}/segments", response_model=schemas.PathDetail)
async def set_segments(path_id: PathId, body: schemas.PathSegmentsIn, request: Request, user: Edit, conn: Conn) -> dict[str, Any]:
    """Replace the path's hops with these links, in order. They must form one unbroken route from endpoint A to B."""
    try:
        async with conn.transaction():
            before = await _detail(conn, user, path_id)
            ends = await repo.set_segments(conn, user, path_id, body.link_ids)
            gaps = chain_gaps(before["endpoint_a"]["device_id"], before["endpoint_b"]["device_id"], ends)
            if gaps:
                raise repo.PathRejected("; ".join(gaps))
            after = await _detail(conn, user, path_id)
            await write_audit(conn, action="path.segments_set", actor_user_id=user.id, resource_type="path", resource_id=path_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                              before={"links": [h["link"]["id"] for h in before["hops"]]}, after={"links": body.link_ids})
    except LookupError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc
    except repo.PathRejected as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    return after


@router.delete("/paths/{path_id}", response_model=schemas.StatusOut)
async def delete_path(path_id: PathId, request: Request, user: Edit, conn: Conn) -> dict[str, str]:
    async with conn.transaction():
        before = await _detail(conn, user, path_id)
        await repo.delete_path(conn, user, path_id)
        await write_audit(conn, action="path.deleted", actor_user_id=user.id, resource_type="path", resource_id=path_id,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), before=_safe(before))
    return {"status": "deleted"}
