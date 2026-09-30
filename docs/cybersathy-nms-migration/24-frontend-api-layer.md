# Plan 24: Frontend API Layer

> **Phase:** 3 (grows through 7) · **Depends on:** 3 · **Status:** Not started

## Goal
Create typed API clients for CyberSathy-NMS frontend.

## Current Source / Reference
Current frontend uses ad hoc `DataService` calls against `/api/v1`.

## Target Design
Vue 3 frontend uses typed API clients.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add clients for auth, users, devices, interfaces, OLTs, ONUs, switches, routers, events, vendors, and OID profiles.
- Centralize auth header and error handling.
- Replace old calls gradually.
- Start in Phase 3, not at the end. Auth and device clients are needed as soon as Plans 3 and 9 exist.
- Generate types from the FastAPI OpenAPI schema so client and server cannot drift.
- Adapt the legacy envelope in one place (D-05).
- Centralize the auth header, error mapping and 401 handling (the logic in the current `dataService.ts` moves into the new client with the Bearer header and `cs_*` storage keys).

## Database/API Impact
No schema impact.

## Frontend Impact
Improves type safety and reduces duplicated request logic.

## Security / Access Rules
Always send `Authorization: Bearer <token>` when authenticated.

## Acceptance Checks
- Frontend type-check passes.
- Auth header always sent.
- API errors show useful messages.
- Old ad hoc calls are replaced gradually.
- Type-check passes on generated types.
- A 401 clears the stored key and redirects to login.
- No page calls the old `DataService` for an endpoint that has a typed client.

## Risks
- Mixing old and new clients can cause inconsistent error handling.

## Definition of Done
Typed clients cover each migrated endpoint family; old calls removed as pages move.

## Rollback
Old `DataService` stays until the last page moves.
