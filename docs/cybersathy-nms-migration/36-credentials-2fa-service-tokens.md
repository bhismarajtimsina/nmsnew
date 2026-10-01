# Plan 36: Credentials, 2FA and Service Tokens

> **Phase:** 1 · **Depends on:** 2, 3 · **Status:** Partial · **Owns:** F-01 (seed), F-03, F-13 (with Plan 3)

## Goal
Replace the foundation's static-key login with the full credential model the legacy system has, and add machine tokens so integrations do not depend on user sessions.

## Current Source / Reference
- Legacy: `src/Api/Auth.php`, `Actions/Auth/UserAuthAction.php`, `Infrastructure/Security/Passwords.php` (verify, `needsRehash`, transparent upgrade of legacy unsalted sha1), `Services/GoogleAuthenticator.php`, `Actions/User/ConnectUser2faAction.php`, `GetUser2faAction.php`, `AuthStrictByIpMiddleware.php`, `Infrastructure/TrustedIps.php`, `Infrastructure/Security/Encryption.php`, `Console/Security/*`, `Actions/System/ExternalAppsAuth.php`, `Actions/Auth/CrossAuthAction.php`, `Actions/User/GenerateUserTokenAction.php`, migrations 053, 056, 061.
- New: `backend/app/api/auth.py` (login by `auth_key`, SHA-256, no lockout).

## Target Design
Three separate credential types, never mixed:
1. **Human login:** username + password (argon2id) + optional TOTP. Produces a **session** (24-hour TTL, revocable, sliding activity).
2. **API token:** for machines. Hashed at rest, permission-limited, optional expiry, revocable, last-used tracked. Does not use the session TTL. Replaces the legacy `generate-auth-key` for integrations.
3. **Device credentials:** SNMP communities/SNMPv3 secrets and console logins, encrypted at rest, write-only through the API.

## Implementation Steps
- Add `users.password_hash`, `hash_scheme`, `totp_secret_enc`, `totp_enabled`, `strict_ip_enabled`, `allowed_ips`; add `api_tokens`, `login_attempts`, `totp_recovery_codes` (Alembic).
- Login `POST /api/v1/auth/login` with `{login, password, twofa_pin?}`; if 2FA is required and no PIN is supplied return `need_2fa`. The shim maps the legacy `POST /auth` onto the same handler.
- Import legacy password hashes with their scheme; verify with the scheme; on success rehash to argon2id.
- Constant-time comparison for every secret comparison (`hmac.compare_digest`).
- **Rate limiting and lockout:** per-account and per-IP counters in Redis with exponential backoff, configurable via `RATE_LIMITER_*` settings; every failure written to `login_attempts` and `audit_logs` without the submitted password.
- **IP-strict and trusted IPs:** enforce `allowed_ips` per user and a global trusted-network list. Client IP comes from validated proxy headers only (depends on defect F-06 fix in Plan 1).
- TOTP enrolment (`GET/PUT /users/{id}/2fa`, connect), recovery codes, and an admin reset that is audited.
- **API tokens:** `POST /api/v1/api-tokens` returns the token once; store only the hash; token carries a permission subset that can never exceed the creator's permissions. Presented as `Authorization: Bearer <token>`; the shim also accepts `X-Auth-Key` for callers that have not moved yet.
- **Auth gate for proxied apps (D-21):** `GET /api/v1/auth/verify?app=<name>` checks the `cs_session` cookie or a Bearer token plus the `external_apps.<name>.view` permission and scope, and answers 200 with `X-Auth-User` and `X-Auth-Role` or 401/403. Nginx `auth_request` calls it for Grafana (auth-proxy mode), Prometheus, Alertmanager and the config-backup viewer. See [own-components.md](own-components.md#33-auth-gateway-for-proxied-apps-grafana-prometheus-alertmanager-config-backup-viewer).
- **Cookie attributes:** the `cs_session` cookie is `HttpOnly`, `Secure` outside development, `SameSite=Lax`, explicit `Path` and `Domain`; CSRF protection for cookie-authenticated state-changing calls on non-proxy routes.
- **WebSocket token:** short-lived, single-purpose token minted from a session, because `/ws?token=` ends up in logs.
- **Encryption:** an `EncryptionService` (AES-256-GCM, `key_id` stored per ciphertext) used for device credentials, TOTP secrets and integration secrets. Include a re-encrypt command and a key-rotation procedure. The legacy key is imported once for migration, then retired.
- **Seed fix (F-01):** the seed must set `password_hash` on create only, never on conflict; never print a credential it did not set; refuse to run with a weak or empty password in production.
- Session management: list own sessions, revoke a session, admin close-session (`user-session-close`), scheduled cleanup of expired sessions.

## Database/API Impact
New tables `api_tokens`, `login_attempts`, `totp_recovery_codes`; changes to `users` and `user_sessions` per [data-model.md](data-model.md).

## Frontend Impact
Login form gains password and 2FA step; account settings gain 2FA enrolment and session list; an admin screen lists API tokens (name, scope, last used, revoke). The Pinia auth store uses `cs_auth_key` and `cs_user`.

## Security / Access Rules
- Never return password hashes, TOTP secrets, API token values (after creation) or device credentials.
- Redact secrets from logs, audit `before/after`, and error responses.
- Token creation, revocation, 2FA reset and failed logins are audited.
- Password policy honors `SECURE_CHECK_PASSWORD_STRENGTH`.

## Acceptance Checks
- Login with password works; wrong password, unknown user and disabled user return the same generic error and same response time class.
- 2FA required flow returns `need_2fa`, then succeeds with a valid PIN and fails with an invalid or reused one.
- Lockout triggers after the configured attempts and lifts after the backoff.
- IP-strict user is refused from a non-allowed IP and accepted from an allowed one, using forwarded headers through Nginx.
- Legacy hash imported, verified, and silently upgraded to argon2id.
- An API token with a read-only subset can read but not write; revoked token is rejected within seconds.
- Re-encrypt command converts fixtures without exposing plaintext in logs.
- A seed re-run changes nothing about existing credentials.

## Risks
- A proxied app reachable without a valid session. Mitigation: gate contract tests with a stub upstream, and no proxied app is published on a host port.
- Losing the legacy encryption key makes device credentials unrecoverable. Back it up before touching it.
- Token permission escalation. Mitigation: token scope is intersected with the owner's live permissions on each request.

## Definition of Done
All checks pass in CI with fixtures only; F-01, F-03, F-13 closed; `STATUS.md` updated.

## Rollback
Login endpoints keep the legacy alias, so the frontend can be repointed. Schema changes are reversible; imported hashes are copies, so legacy remains authoritative until cutover.

## Implementation notes (2026-09-28)
Built and tested: argon2id with transparent upgrade of legacy bcrypt and sha1 hashes; constant-time-equivalent failures (unknown, disabled and wrong all answer identically, with a dummy verification for timing); per-account and per-address lockout in Redis that also blocks the correct password and fails closed when Redis is down; per-user IP restriction using the forwarded address only from a trusted proxy; TOTP (own RFC 6238 implementation, tested against the RFC vectors), replay refusal, single-use recovery codes; API tokens (hashed, permission-limited to the intersection with the owner's live permissions, cannot create tokens); sessions list and revoke; password change revoking other sessions; the Nginx auth gate; AES-256-GCM encryption service with key ids and rotation; audit redaction of every sensitive field.

Not done: a command that re-encrypts stored values onto the active key, the key-rotation runbook, the short-lived WebSocket token (belongs with Plan 22), a global trusted-network list, Device credentials are now stored encrypted (Plan 9).

## Implementation notes (2026-10-01): key rotation

Built: `app/core/rotation.py` and two CLI commands. `python -m app.cli crypto status` counts stored values per key
id without decrypting anything, and exits 2 while any value is not on the active key.
`python -m app.cli crypto reencrypt [--apply]` (dry run by default) moves every stored secret onto the active key.
The procedure is in the [key-rotation runbook](key-rotation-runbook.md).

- `ENCRYPTED_COLUMNS` lists every ciphertext column with the exact AAD its writer uses: the access-profile secrets
  (`device_access_profile:<id>:<field>`) and the TOTP secret (the bare user id). A test compares it against every
  `*_enc` column in the live schema, so a new encrypted column cannot be silently skipped by a rotation and then
  become unreadable when the old key is removed.
- A value that cannot be decrypted (key missing, copied from another row, damaged) is left unchanged and reported
  by row id; the run continues and exits 1. Every write is conditional on the stored value being unchanged since it
  was read, so a credential edited during the run is never overwritten with an older one.
- Nothing prints, logs or returns a secret; a test checks the CLI output.

Tests: 11 in `test_key_rotation.py`. Secrets are created through the real repository and the real 2FA enrollment
API, and a rotated TOTP secret is proven by a real 2FA login with the old key removed. 10 mutations checked, all
caught (wrong AAD per column, a column dropped from the registry, dry run writing, no concurrent-edit guard,
failures not reported, already-active values rewritten, CLI exit codes, key-id parsing).

Still missing from this plan: the global trusted-network list, and the short-lived WebSocket token (Plan 22).

## Finding (2026-10-01): the legacy trusted-network list is an authentication bypass

Read before building the "global trusted-network list" this plan asks for. In legacy
(`src/Api/Middleware/AuthCheckMiddleware.php`, `src/Infrastructure/TrustedIps.php`), the list does not restrict who
may log in. It **replaces** logging in: a request with no `X-Auth-Key` from a listed address runs as the system
user, or as whichever user `?USER_ID=` names. `LOCAL_SUBNET` grants system-user access the same way. Production sets
the list to a `/0` network (R-08 in STATUS.md).

Not ported (D-29). Every request to the new API carries a credential, and services use scoped API tokens. Whether to
add a global network *restriction* on top is an open question in D-29. Nothing was built for it in this round.

## Note (2026-10-01): WebSocket ticket

The short-lived WebSocket credential is built; see [22-realtime-websocket.md](22-realtime-websocket.md). This plan's
remaining item is D-29's open question (a global network restriction), which waits for the owner.
