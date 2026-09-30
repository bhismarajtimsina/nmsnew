# Plan 3: Authentication

> **Phase:** 1 · **Depends on:** 2 · **Status:** Done · **Owns:** F-13, F-15 (with Plans 36 and 41)

## Goal
Implement CyberSathy-NMS authentication while preserving current frontend and proxied-app behavior.

## Current Source / Reference
Current frontend uses `wca_auth_key`, `wca_user`, `X-Auth-Key`, and a `Token` cookie for proxied apps.

## Target Design
FastAPI owns auth under `/api/v1`, with Nginx routing.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add `POST /api/v1/auth`.
- Add `GET /api/v1/auth/session`.
- Add `DELETE /api/v1/logout`.
- Native endpoints use `Authorization: Bearer <token>`. `X-Auth-Key` is accepted only by the compatibility shim (Plan 41).
- Browser storage uses our own keys `cs_auth_key` and `cs_user`. There is no bridge from the old keys; users sign in again at cutover (D-26).
- Set the `cs_session` cookie for the Nginx auth gate in front of Grafana, Prometheus, Alertmanager and the config-backup viewer (see [own-components.md](own-components.md#33-auth-gateway-for-proxied-apps-grafana-prometheus-alertmanager-config-backup-viewer)).
- Define the native auth contract in OpenAPI (F-13): `POST /api/v1/auth/login` with `{login, password, twofa_pin?}` returning `{token, user}` or `need_2fa`, `GET /api/v1/auth/session`, `POST /api/v1/auth/logout`. The legacy paths and envelope (`POST /auth`, `DELETE /logout`, `data.key`) exist only in the shim (Plan 41).
- Rename everything that carries legacy names in `backend/` (F-15): the `X-Auth-Key` header, the `Token` cookie and the `wca_*` storage keys returned by the login response.
- This plan lands first; the full credential model (password, 2FA, tokens) is added by Plan 36 on top of it. This plan owns the session lifecycle: sliding activity, cleanup of expired sessions, own-session list, revoke, admin close.
- Cookie attributes and the short-lived WebSocket token follow Plan 36.

## Database/API Impact
Use `users` and `user_sessions`. Tokens must expire and be revocable.

## Frontend Impact
Login/logout flow stays familiar and later moves into Pinia.

## Security / Access Rules
Reject expired tokens. Audit login/logout. Never expose password hashes.

## Acceptance Checks
- Login works.
- Logout clears session.
- Expired token is rejected.
- `cs_session` cookie is created.
- Auth survives page refresh.
- The migrated frontend logs in through the typed client using `cs_auth_key` and `cs_user`. The shim answers the legacy `POST /auth` shape for callers that have not moved yet.
- A revoked or closed session is rejected within seconds.
- Expired sessions are removed by the scheduled cleanup.

## Risks
- Breaking proxied apps by changing cookie behavior.

## Definition of Done
Contract matches the legacy behavior for login, session and logout; F-13 closed.

## Rollback
Repoint the frontend base URL at the legacy API. Sessions are independent per system.

## Implementation notes (2026-09-28)
Native contract (D-26): `POST /api/v1/auth/login` `{login, password, twofa_pin?, recovery_code?}` returns `{token, expires_at, user}` or `{need_2fa: true}`; `GET /auth/session`; `POST /auth/logout`; `GET/DELETE /auth/sessions`; `POST /auth/password`. Credentials are `Authorization: Bearer` or, read-only, the `cs_session` cookie. Closes F-13 and F-15 in `backend/`.
- A cookie can read but never change state (a state-changing request with only a cookie is refused).
- A forced password change blocks every endpoint except session, logout and the change itself.
- Tokens are stored only as SHA-256 hashes.
- Not done here: idle-timeout enforcement (the last-activity time is recorded; the absolute TTL applies) and the scheduled clean-up of old sessions, which is `python -m app.cli sessions cleanup` until Plan 37 schedules it.
- The Vue frontend still uses the legacy names and API. Plans 24 and 25 move it.
