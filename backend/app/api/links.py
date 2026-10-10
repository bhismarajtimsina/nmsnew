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
from app.repositories import devices as device_repo
from app.topology import links as topology
from app.topology import tree as trees

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


@router.get("/topology/links/utilization", response_model=schemas.LinkUtilizationList)
async def link_utilization(user: View, conn: Conn,
                           device_id: Annotated[str | None, Query(pattern=r"^[0-9a-fA-F-]{36}$")] = None) -> dict[str, Any]:
    """How busy each link the caller may see is, over the configured period: the busiest direction at an end inside
    the caller's scope. Links with nothing measured are left out."""
    rows = await repo.list_links(conn, user, device_id=device_id)
    figures = await repo.utilization_of(conn, rows, settings.links_utilization_minutes, visible_only=True)
    return {"minutes": settings.links_utilization_minutes,
            "items": [{"link_id": link_id, **f} for link_id, f in figures.items() if f is not None]}


async def _visible_links(conn: asyncpg.Connection, user: CurrentUser, device_id: str) -> list[dict[str, Any]]:
    if await device_repo.get_device(conn, user, device_id) is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return [topology.present(r) for r in await repo.list_links(conn, user)]


@router.get("/topology/tree/{device_id}", response_model=schemas.TopologyTree)
async def topology_tree(device_id: LinkId, user: View, conn: Conn,
                        direction: Annotated[str, Query(pattern="^(down|up)$")] = "down") -> dict[str, Any]:
    """The tree below a device (`down`), or below the highest device above it that the caller may see (`up`; legacy's
    "core" device). A device with nothing above it is its own top."""
    links = await _visible_links(conn, user, device_id)
    start = trees.top_of(device_id, links) if direction == "up" else device_id
    tree, truncated = trees.down_tree(start, links)
    return {"direction": direction, "searched_device": device_id, "build_from": start,
            "is_top": not trees.upward_chain(start, links), "truncated": truncated, "tree": tree}


@router.get("/topology/upward/{device_id}", response_model=schemas.UplinkChain)
async def topology_upward(device_id: LinkId, user: View, conn: Conn) -> dict[str, Any]:
    """The chain of upstream devices from a device to its top, nearest first."""
    links = await _visible_links(conn, user, device_id)
    return {"device_id": device_id, "steps": trees.upward_chain(device_id, links)}


async def _suggestions(conn: asyncpg.Connection, user: CurrentUser, device_id: str | None = None) -> list[dict[str, Any]]:
    from app.repositories import lldp as lldp_repo
    from app.topology.lldp import suggest_links

    inputs = await lldp_repo.suggestion_inputs(conn, user, device_id)
    # A masked end has no device id, so it can never equal a suggestion's end: every visible link can be compared.
    existing = [topology.present(r) for r in await repo.list_links(conn, user)]
    return suggest_links(inputs["matched"], inputs["interfaces"], existing)


@router.get("/topology/lldp/suggestions", response_model=schemas.LldpSuggestionList)
async def lldp_suggestions(user: View, conn: Conn,
                           device_id: Annotated[str | None, Query(pattern=r"^[0-9a-fA-F-]{36}$")] = None) -> dict[str, Any]:
    """Links the stored LLDP data supports and the inventory lacks, between devices the caller may see."""
    return {"items": await _suggestions(conn, user, device_id)}


@router.post("/topology/lldp/suggestions/accept", response_model=schemas.LinkOut, status_code=status.HTTP_201_CREATED)
async def accept_lldp_suggestion(body: schemas.LinkCreate, request: Request, user: Edit, conn: Conn) -> dict[str, Any]:
    """Create a link from a current suggestion, in either direction. Anything the stored LLDP data does not support right
    now is refused: this route cannot be used to create an arbitrary link marked as learned by LLDP."""
    from app.topology.lldp import same_link

    data = body.model_dump()
    if not any(same_link(s, data) for s in await _suggestions(conn, user)):
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="No current LLDP suggestion joins these two ends")
    try:
        async with conn.transaction():
            link_id = await repo.create_link(conn, user, {**data, "source": "lldp"})
            link = topology.present(await repo.get_link(conn, user, link_id))
            await write_audit(conn, action="link.created", actor_user_id=user.id, resource_type="link", resource_id=link_id,
                              ip=user.client_ip, user_agent=request.headers.get("user-agent"), after={**_snapshot(link), "source": "lldp"})
    except LookupError as exc:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=str(exc)) from exc
    except repo.LinkRejected as exc:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(exc)) from exc
    except asyncpg.UniqueViolationError as exc:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="These two ends are already linked") from exc
    return link


@router.put("/topology/lldp/external-names/{ext_id}", response_model=schemas.StatusOut)
async def set_external_name(
    ext_id: Annotated[str, Path(pattern=r"^ext:[0-9a-f-]{36}:([0-9a-f]{2}:){5}[0-9a-f]{2}$")],
    body: schemas.ExternalNameIn, request: Request, user: Edit, conn: Conn,
) -> dict[str, str]:
    """A display name for an LLDP neighbour that is not in the inventory (legacy's external neighbour names). The id
    names the reporting device, which must be inside the caller's scope. A null name removes it."""
    from app.repositories import lldp as lldp_repo

    async with conn.transaction():
        if not await lldp_repo.set_external_name(conn, user, ext_id, body.name):
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
        await write_audit(conn, action="link.external_name_set", actor_user_id=user.id, resource_type="device",
                          resource_id=ext_id.split(":")[1], ip=user.client_ip, user_agent=request.headers.get("user-agent"),
                          metadata={"external_id": ext_id, "name": body.name})
    return {"status": "saved"}


@router.get("/topology/lldp/{device_id}", response_model=schemas.LldpNeighbourList)
async def lldp_neighbours(device_id: LinkId, user: View, conn: Conn) -> dict[str, Any]:
    """The device's LLDP neighbours as last polled, each matched to a device the caller may see where possible."""
    from app.repositories import lldp as lldp_repo

    items = await lldp_repo.device_neighbours(conn, user, device_id)
    if items is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    return {"device_id": device_id, "items": items}

