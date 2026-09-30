# Plan 33: Testing and CI

> **Phase:** 1 (minimal) and 8 (complete) · **Depends on:** 1 · **Status:** Partial

## Goal
Add CI and automated tests for migration safety.

## Current Source / Reference
Current repo has PHP tests and frontend build/type-check scripts.

## Target Design
CyberSathy-NMS CI validates backend, frontend, imports, and polling parsers.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add backend unit/API/permission/polling parser/worker tests.
- Add frontend type-check/build/dashboard smoke tests.
- Add OID YAML import, MIB index import, and MySQL-to-PostgreSQL import tests.
- Start CI in Phase 1 with lint, unit tests, migration round-trip and the route-permission guard; grow with each phase.
- Add a **network guard** to the test runner: outbound sockets to non-loopback addresses fail the test. This enforces [the policy](safety-and-verification-policy.md) automatically.
- Port `tools/bdcom-oid-coverage.php` to Python so CI does not need the legacy container.
- Add the scope-leak matrix, the fixture credential scanner, the contract tests (Plan 41) and `tools/env-parity-check.py` (see [env-parameter-mapping.md](env-parameter-mapping.md)).
- Add the policy checks: no writable OID in a polling profile; every walk bounded; every hypertable has retention.
- Add a secret scanner for tracked files (Plan 35).

## Database/API Impact
CI uses disposable PostgreSQL/Redis services.

## Frontend Impact
Frontend build and type-check block regressions.

## Security / Access Rules
Add leakage tests for reseller scope.

## Acceptance Checks
- CI runs all tests.
- Build artifacts produced.
- Failing tests block merge.
- A test that opens a socket to a public or private non-loopback address fails.
- A planted secret in a fixture fails the scanner.
- A route without a permission fails CI.
- Failing tests block merge.

## Risks
- Missing integration tests can allow scope leaks.

## Definition of Done
CI enforces every rule above on every change.

## Rollback
CI configuration is code; revert.

## Implementation notes (2026-09-28)
`tools/test-backend.sh` starts a throwaway TimescaleDB and Redis on a private Docker network and runs the whole suite (172 tests).
- **Network guard** (`tests/conftest.py`): a test that connects to anything except loopback and the scratch services fails before a packet is sent. It is itself tested.
- Route guard, scope guard, schema drift check, permission catalogue invariants, and a table-driven parity test between the database rules and their Python mirror.
- Mutation checks were run by hand (see [STATUS.md](STATUS.md)): 49 deliberate breakages in total, each caught.
- Not done: fixture credential scanner, contract tests (Plan 41), `tools/env-parity-check.py`, porting `bdcom-oid-coverage.php` to Python, tracked-secret scanner (Plan 35). `.github/workflows/backend.yml` exists but has not run: GitHub only reads workflows at the repository root (R-05).
