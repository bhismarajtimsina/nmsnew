# Plan 41: Legacy API Compatibility

> **Phase:** 7 · **Depends on:** 3, 9, 10, 36 · **Status:** Not started · **Owns:** F-13 (with Plan 3)

## Goal
Keep every existing caller of the legacy `/api/v1` working through cutover, then retire the old surface deliberately, based on measured use.

## Current Source / Reference
[api-compatibility.md](api-compatibility.md): about 318 legacy endpoints (109 core, 209 component), the legacy `{statusCode, data}` envelope, integer IDs, singular resource names, and a known read-only external consumer that uses a long-lived token and the `from=cache` parameter.

## Target Design
A compatibility router in the FastAPI app serves legacy paths by calling the same service layer as the native endpoints. It translates path, IDs, envelope and errors, and adds nothing to permissions or scope. Native endpoints are the only place new features appear.

## Implementation Steps
1. Generate the route skeleton from [api-compatibility.md](api-compatibility.md) so the table and the code cannot drift.
2. **Scope:** the shim serves only callers that cannot move before cutover. Native endpoints never accept `X-Auth-Key` or the `Token` cookie; only the shim does (D-26). Every other caller moves to the native API.
2. **ID translation:** integer IDs resolve through `legacy_id`; responses return integer IDs on legacy paths and UUIDs on native paths (D-02).
3. **Envelope and errors:** legacy paths return `{statusCode, data, ...}` and legacy-shaped errors (D-05).
4. **Contract tests:** record real legacy responses for every read endpoint (from the legacy system's own responses, not by polling devices), replay against the shim, compare field by field, including field order-insensitive JSON equality and type checks.
5. **Cache-first reads:** preserve the `from` parameter semantics. `from=cache` never triggers a device call. `from=live` is permission-gated, rate-limited and audited, and is disabled by default in the shim.
6. **Usage tracking:** `legacy_route_usage` counts each legacy route by caller (user or token) per day. A dashboard shows routes still in use.
7. **Service token continuity (Plan 36):** existing long-lived tokens are imported as `api_tokens` (the legacy key value is hashed at import, never stored in plaintext) so integrations keep working without reconfiguration.
8. **Decide `/universal` (D-08)** and any other endpoint with no known caller: drop or implement based on evidence.
9. **Deprecation:** add `Deprecation` and `Sunset` headers to legacy routes once a replacement is documented; remove a route only after 30 days of zero recorded use and an announcement.

## Database/API Impact
`legacy_route_usage`. No other schema change.

## Frontend Impact
The frontend moves to native endpoints through typed clients (Plan 24) route by route. The shim lets both coexist during the move.

## Security / Access Rules
- The shim never widens a permission or bypasses scope; it calls the same dependencies.
- Legacy routes that reach devices keep their permission, rate limit and audit.
- The shim does not expose fields the native API hides, including any credentials.

## Acceptance Checks
- Contract tests pass for every listed read endpoint.
- A caller using an imported service token continues to work unchanged.
- A legacy route returns the same status and shape for not-found, forbidden and validation errors as the legacy system did (sampled).
- `from=live` requests are refused unless explicitly enabled.
- `legacy_route_usage` records callers, and the report identifies zero-use routes.

## Risks
- Two contracts to maintain. Mitigation: generated from one table, native-only features, deprecation schedule.
- Subtle shape differences break a consumer silently. Mitigation: contract tests from recorded responses.

## Definition of Done
Every route in the table is `Shimmed`, `Replaced by native`, or `Dropped` with evidence; contract tests run in CI.

## Rollback
The shim can be disabled route by route; the legacy PHP API remains available until cutover, so callers can be pointed back.
