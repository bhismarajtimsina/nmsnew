# Plan 32: Data Migration

> **Phase:** 8 · **Depends on:** 2, 4, 9, 10 · **Status:** Not started

## Goal
Migrate useful current MySQL data to PostgreSQL.

## Current Source / Reference
Current data lives in MySQL tables managed by root and component migrations.

## Target Design
CyberSathy-NMS import scripts move stable data into PostgreSQL + TimescaleDB.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Import users/roles, devices, groups, models, interfaces, OLT/ONU records, events, and useful poller history.
- Preserve old IDs where practical.
- Validate counts and samples.
- Order: reference data, users and roles, device groups, models, access profiles, devices, interfaces, OLT/PON/ONU, events, links, history.
- Keep `legacy_id`; support **dry-run**, idempotent re-run and a **delta import** for the cutover window.
- Re-encrypt credentials: decrypt with the legacy key, encrypt with the new key, verify by count and a test decrypt, never log plaintext.
- Import users with their legacy password hash scheme; import long-lived keys as `api_tokens` hashed at import.
- Import history only for a bounded window (N months, set by the owner) and drop rows failing range checks.
- Carry over the **results** of migrations 062–070: roles, alarm rules, role dashboards, BDCOM safe pollers.
- Build the reverse export used for rollback ([cutover-runbook.md](cutover-runbook.md)).
- Run the data-quality checks listed in [data-model.md](data-model.md#data-quality-checks-plan-32).

## Database/API Impact
Import scripts write to PostgreSQL only after schema is ready.

## Frontend Impact
After import, users should see familiar devices, roles, and dashboards.

## Security / Access Rules
Protect credential fields and secrets during export/import.

## Acceptance Checks
- Counts match.
- Sample devices match.
- Sample OLT/ONU data matches.
- Login works after import.
- Counts match per table, after a documented exclusions list.
- A second run of the import changes nothing.
- A user with a legacy hash logs in and is upgraded.
- The role permission diff report is empty or signed.
- No plaintext credential appears in logs or the import report.

## Risks
- Migrating stale/bad history can pollute new dashboards.

## Definition of Done
Full staging import passes all checks twice; reverse export rehearsed.

## Rollback
Import writes only to the new database; the legacy database is read, never written.
