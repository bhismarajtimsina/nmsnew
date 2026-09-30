# Plan 2: PostgreSQL Base Schema

> **Phase:** 1 · **Depends on:** 1 · **Status:** Done · **Owns:** F-01, F-02, F-10, F-11

## Goal
Create the first PostgreSQL schema and migration workflow for CyberSathy-NMS.

## Current Source / Reference
Current data is in MySQL with SQL migrations under `migrations/` and component migrations under `components/*/migrations`.

## Target Design
CyberSathy-NMS uses PostgreSQL + TimescaleDB with Alembic-managed schema.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Create Alembic setup.
- Create base tables: `users`, `roles`, `permissions`, `user_sessions`, `audit_logs`, `system_settings`.
- Create device foundation tables: `devices`, `device_groups`, `device_models`, `device_access_profiles`, `interfaces`.
- Enable TimescaleDB extension.
- Seed first Super Admin user.
- Fix F-10: the downgrade never drops shared extensions; make `user_sessions.auth_key_hash` unique; use `macaddr` and `inet`; add `CHECK` constraints on `scope_level` and status columns.
- Fix F-11: add a schema-drift test (migrated database compared with the expected schema) so a manual change or a forgotten migration is detected.
- Fix F-01 and F-02: the seed inserts what is missing and never overwrites credentials, user roles, or customized role permissions; it never prints a credential it did not set; it refuses weak or empty passwords in production.
- Add `legacy_id` (unique, nullable) and `polling_owner` where [data-model.md](data-model.md) says so; create hypertables from D-01 together with their retention and compression policies.
- Follow the conventions in [data-model.md](data-model.md): `timestamptz` UTC, `inet`, `macaddr`, `key_id` on every ciphertext column.

## Database/API Impact
No old data is imported yet. The schema must be clean, reversible, and ready for import scripts.

## Frontend Impact
No UI dependency except future auth and device APIs.

## Security / Access Rules
Store password hashes and auth tokens securely. Do not seed weak production credentials.

## Acceptance Checks
- Migrations run cleanly.
- Rollback works.
- Seed admin user exists.
- Fresh container can initialize schema.
- Upgrade, downgrade, upgrade on an empty database succeeds and leaves the extensions installed.
- Running the seed twice yields identical rows and prints no credential the second time.
- The drift test fails when a migration and the expected schema differ.

## Risks
- Recreating old MySQL quirks directly instead of designing clean PostgreSQL tables.

## Definition of Done
F-01, F-02, F-10, F-11 closed; migration round-trip and drift tests run in CI.

## Rollback
Each revision has a tested `downgrade()`. The database is new and holds no production data until Plan 32.

## Implementation notes (2026-09-28)
Migrations 0001 to 0007 apply on an empty database, downgrade step by step to base, and upgrade again to the identical schema.
- F-01, F-02: the seed inserts what is missing. It never touches a credential, a user's role, a role's scope mode, or a permission an administrator removed. A permission new to the catalogue is granted to the default roles that define it. `--reset-role-permissions` is the explicit way to restore defaults.
- F-10: the downgrade no longer drops extensions; session token hashes are unique; MACs are `macaddr`; status columns have `CHECK` constraints; `legacy_id` on users, groups, models, access profiles, devices and interfaces; `devices.polling_owner` defaults to `legacy`.
- F-11: `app/schema_snapshot.json` describes tables, columns, constraints, indexes, triggers, functions, hypertables and policies. Tests fail if a migration and the snapshot disagree; `python -m app.cli db check` compares a live database.
- `audit_logs` is a compressed hypertable with 2-year retention. The other hypertables in D-01 are created together with the tables they belong to.
