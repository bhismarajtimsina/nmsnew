"""Macros and ONU-registration templates (Plan 38): list, read, create, edit, delete and preview.

A preview renders against sample variables the caller supplies and never touches a device. Running a template
against a device goes through the dangerous-action flow (Plan 26), not through these routes. Edits are audited with
the template before and after, and a save carries the version it was based on, so one editor cannot silently
overwrite another's change.

No `from __future__ import annotations` here, on purpose: the routes are built inside `build_router`, and their
`Depends(require(view))` annotations must be evaluated where `view` and `edit` exist, not resolved later from strings.
"""

from typing import Annotated, Any

import asyncpg
from fastapi import APIRouter, Depends, HTTPException, Path, status

from app.actions import templates as engine
from app.api import schemas
from app.core.audit import write_audit
from app.core.config import settings
from app.core.database import get_conn
from app.core.security import CurrentUser, require
from app.repositories import macros as repo

MacroId = Annotated[str, Path(pattern=r"^[0-9a-fA-F-]{36}$")]


def _validated(body: schemas.MacroIn) -> dict[str, Any]:
    """The body checked by the template engine, as it is stored. Refused with 422 and a safe message."""
    try:
        engine.check_template(body.template)
        engine.parse_parameters([p.model_dump(exclude_none=True) for p in body.parameters])
    except engine.TemplateRejected as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    data = body.model_dump(exclude={"version"})
    data["parameters"] = [p.model_dump(exclude_none=True) for p in body.parameters]
    return data


def _preview(template: str, declared: list[dict[str, Any]], body: schemas.MacroPreviewIn) -> dict[str, Any]:
    try:
        parameters = engine.parse_parameters(declared)
        variables = engine.plain(body.variables)
        params = engine.validate_parameters(parameters, body.params, variables)
        return {"commands": engine.render(template, {**variables, "params": params}), "aborted": None}
    except engine.TemplateAbort as exc:
        return {"commands": [], "aborted": str(exc)}
    except engine.TemplateRejected as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


def build_router(path: str, kind: str, view: str, edit: str, tag: str) -> APIRouter:
    router = APIRouter(prefix=settings.api_prefix, tags=[tag])
    resource = f"{kind}_template" if kind != "macro" else "macro"

    async def found(conn: asyncpg.Connection, macro_id: str) -> dict[str, Any]:
        row = await repo.get_macro(conn, kind, macro_id)
        if row is None:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
        return row

    @router.get(path, response_model=schemas.MacroList, name=f"list_{kind}")
    async def list_all(_: Annotated[CurrentUser, Depends(require(view))],
                       conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
        return {"items": await repo.list_macros(conn, kind)}

    @router.post(f"{path}/preview", response_model=schemas.MacroPreviewOut, name=f"preview_draft_{kind}")
    async def preview_draft(body: schemas.MacroDraftPreviewIn, _: Annotated[CurrentUser, Depends(require(edit))]) -> dict[str, Any]:
        """Render an unsaved template, as its editor sees it before saving. Never touches a device."""
        return _preview(body.template, [p.model_dump(exclude_none=True) for p in body.parameters], body)

    @router.get(f"{path}/{{macro_id}}", response_model=schemas.MacroOut, name=f"get_{kind}")
    async def get_one(macro_id: MacroId, _: Annotated[CurrentUser, Depends(require(view))],
                      conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
        return await found(conn, macro_id)

    @router.post(f"{path}/{{macro_id}}/preview", response_model=schemas.MacroPreviewOut, name=f"preview_{kind}")
    async def preview_saved(macro_id: MacroId, body: schemas.MacroPreviewIn, _: Annotated[CurrentUser, Depends(require(view))],
                            conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
        """Render a saved template against sample variables. Never touches a device."""
        row = await found(conn, macro_id)
        return _preview(row["template"], row["parameters"], body)

    @router.post(path, response_model=schemas.MacroOut, status_code=201, name=f"create_{kind}")
    async def create(body: schemas.MacroIn, user: Annotated[CurrentUser, Depends(require(edit))],
                     conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
        data = _validated(body)
        async with conn.transaction():
            try:
                row = await repo.create_macro(conn, kind, user.id, data)
            except asyncpg.UniqueViolationError as exc:
                raise HTTPException(status_code=409, detail="a template with this name already exists") from exc
            await write_audit(conn, action=f"{resource}.create", actor_user_id=user.id, resource_type=resource,
                              resource_id=row["id"], ip=user.client_ip, after=data)
        return row

    @router.put(f"{path}/{{macro_id}}", response_model=schemas.MacroOut, name=f"update_{kind}")
    async def update(macro_id: MacroId, body: schemas.MacroUpdate, user: Annotated[CurrentUser, Depends(require(edit))],
                     conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> dict[str, Any]:
        data = _validated(body)
        async with conn.transaction():
            before = await found(conn, macro_id)
            try:
                row = await repo.update_macro(conn, kind, macro_id, user.id, body.version, data)
            except asyncpg.UniqueViolationError as exc:
                raise HTTPException(status_code=409, detail="a template with this name already exists") from exc
            if row is None:
                raise HTTPException(status_code=409, detail="the template was changed by someone else; reload it and try again")
            await write_audit(conn, action=f"{resource}.update", actor_user_id=user.id, resource_type=resource,
                              resource_id=macro_id, ip=user.client_ip,
                              before={k: before[k] for k in data}, after=data)
        return row

    @router.delete(f"{path}/{{macro_id}}", status_code=204, name=f"delete_{kind}")
    async def delete(macro_id: MacroId, user: Annotated[CurrentUser, Depends(require(edit))],
                     conn: Annotated[asyncpg.Connection, Depends(get_conn)]) -> None:
        async with conn.transaction():
            before = await found(conn, macro_id)
            await repo.delete_macro(conn, kind, macro_id)
            await write_audit(conn, action=f"{resource}.delete", actor_user_id=user.id, resource_type=resource,
                              resource_id=macro_id, ip=user.client_ip,
                              before={k: before[k] for k in ("name", "template", "parameters")})

    return router


macros_router = build_router("/macros", "macro", "macros.execute", "macros.edit", "macros")
registration_router = build_router("/onu-registration-templates", "onu_registration", "onus.registration.preview",
                                   "onus.registration.configure", "onu-registration")
