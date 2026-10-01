# Plan 24: Frontend API Layer

> **Phase:** 3 (grows through 7) · **Depends on:** 3 · **Status:** Partial

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

## Implementation notes (2026-10-01): the typed client and its drift guard

Built:
- `python -m app.cli openapi --write` writes the API's OpenAPI schema to `frontend/src/api/openapi.json`
  (deterministic, so it diffs cleanly). The backend test `tests/test_openapi_snapshot.py` fails when that copy no
  longer matches the code. The service-only Alertmanager webhook stays out of it.
- `npm run gen:api` (openapi-typescript) generates `frontend/src/api/schema.d.ts` from it, and `npm run check:api`
  fails when the committed types are stale. Together the two checks mean a backend change that breaks a frontend
  call fails the type-check. This was demonstrated by renaming a request field in the schema: the type-check failed
  on the call still using the old name.
- `frontend/src/api/client.ts` (openapi-fetch): one client for every migrated page. It sends
  `Authorization: Bearer` from `cs_auth_key` on every request, read at request time so a new login takes effect
  immediately, and never sends the legacy `X-Auth-Key`. Errors become `ApiError` carrying the API's own `detail`,
  with FastAPI validation lists turned into one readable line. A **401** clears `cs_auth_key` and `cs_user` and goes
  to the login page. A **403** does not: it is a missing permission, not a lost login. (The legacy `DataService`
  logged users out on 403 as well.)
- `npm run type-check:api` checks this layer strictly on its own, and `npm test` (vitest) runs its 8 tests. CI:
  `.github/workflows/frontend.yml`.

5 mutations checked, all caught: no auth header, a 401 that keeps the credential, a 403 that logs out, no redirect
on 401, the API's error detail ignored.

**Not done yet:**
- Most responses are untyped. Of 89 operations, 21 have a typed response and 68 return `dict[str, Any]` from
  FastAPI, which reaches TypeScript as an untyped object. Request bodies and query parameters are typed. Next step:
  give those routes response models on the backend, which the snapshot test then carries to the frontend
  automatically.
- No page uses the client yet. The legacy `DataService` (X-Auth-Key, `wca_*` keys) still serves every page; pages
  move one at a time with Plan 25. The login page and the auth store come first.
- `npm run type-check` for the whole project fails on 8 Vue files that are not in git (R-09), which is why the API
  layer has its own check.
