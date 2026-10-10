"""Topology links (Plan 27): list, read, create, change and delete links, and the topology graph. Viewing needs
`links.view` and is scoped by the links' ends (app/repositories/links.py); changing needs `links.edit` and both ends
inside the caller's scope. Every change is audited."""
from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path, Query, Request, status

from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import links as repo
from app.topology import links as topology

router = APIRouter(prefix=settings.api_prefix, tags=["topology"])
LinkId = Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")]
View = Annotated[CurrentUser, Depends(require("links.view"))]
Edit = Annotated[CurrentUser, Depends(require("links.edit"))]
Conn = Annotated[asyncpg.Connection, Depends(get_conn)]


def _snapshot(link: dict[str, Any]) -> dict[str, Any]:
    return {"src": [link["src"]["device_id"], link["src"]["interface_id"]], "dest": [link["dest"]["device_id"], link["dest"]["interface_id"]],
            "description": link["description"]}


@router.get("/links", response_model=schemas.LinkList)
async def list_links(user: View, conn: Conn,
                     device_id: Annotated[str | None, Query(pattern=r"^[0-9a-fA-F-]{36}$")] = None) -> dict[str, Any]:
    rows = await repo.list_links(conn, user, device_id=device_id)
    return {"items": [topology.present(r) for r in rows], "truncated": len(rows) >= repo.MAX_LINKS}


@router.get("/links/{link_id}", response_model=schemas.LinkOut)
async def get_link(link_id: LinkId, user: View, conn: Conn) -> dict[str, Any]:
    row = await repo.get_link(conn, user, link_id)
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return topology.present(row)


@router.post("/links", response_model=schemas.LinkOut, status_code=status.HTTP_201_CREATED)
async def create_link(body: schemas.LinkCreate, request: Request, user: Edit, conn: Conn) -> dict[str, Any]:
    try:
        async with conn.transaction():
            link_id = await repo.create_link(conn, user, body.model_dump())
            link = topology.present(await repo.get_link(conn, user, link_id))
            await write_audit(conn, action="link.created", actor_user_id=user.id, resource_type="link", resource_id=link_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), after=_snapshot(link))
    except LookupError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc
    except repo.LinkRejected as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    except asyncpg.UniqueViolationError as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="These two ends are already linked") from exc
    return link


@router.put("/links/{link_id}", response_model=schemas.LinkOut)
async def update_link(link_id: LinkId, body: schemas.LinkUpdate, request: Request, user: Edit, conn: Conn) -> dict[str, Any]:
    changes = body.model_dump(exclude_unset=True)
    try:
        async with conn.transaction():
            before = await repo.editable_link(conn, user, link_id)
            if before is None:
                raise LookupError("Not found")
            await repo.update_link(conn, user, link_id, changes)
            after = topology.present(await repo.get_link(conn, user, link_id))
            await write_audit(conn, action="link.updated", actor_user_id=user.id, resource_type="link", resource_id=link_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                              before=_snapshot(topology.present(before)), after=_snapshot(after))
    except LookupError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc
    except repo.LinkRejected as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    except asyncpg.UniqueViolationError as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="These two ends are already linked") from exc
    return after


@router.delete("/links/{link_id}", response_model=schemas.StatusOut)
async def delete_link(link_id: LinkId, request: Request, user: Edit, conn: Conn) -> dict[str, str]:
    async with conn.transaction():
        before = await repo.editable_link(conn, user, link_id)
        if before is None or not await repo.delete_link(conn, user, link_id):
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
        await write_audit(conn, action="link.deleted", actor_user_id=user.id, resource_type="link", resource_id=link_id,
                          ip=user.client_ip, user_agent=request.headers.get("user-agent"), before=_snapshot(topology.present(before)))
    return {"status": "deleted"}


@router.get("/topology/graph", response_model=schemas.TopologyGraph, response_model_by_alias=True)
async def topology_graph(user: View, conn: Conn) -> dict[str, Any]:
    """Every link the caller may see, as nodes and edges with their state."""
    rows = await repo.list_links(conn, user)
    return {**topology.graph([topology.present(r) for r in rows]), "truncated": len(rows) >= repo.MAX_LINKS}
