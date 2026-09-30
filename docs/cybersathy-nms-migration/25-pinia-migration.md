# Plan 25: Pinia Migration

> **Phase:** 3 (grows through 7) · **Depends on:** 24, 3 · **Status:** Not started

## Goal
Replace Vuex with Pinia while preserving auth behavior.

## Current Source / Reference
Current frontend state is in `frontend/src/vuex`.

## Target Design
CyberSathy-NMS uses Pinia stores.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add stores: auth, layout, permissions, devices, dashboards, realtime.
- Store the session only under `cs_auth_key` and `cs_user`; do not read the old keys (D-26).
- Move session validation and WebSocket reconnect to Pinia.
- Migrate the auth and permissions stores first (Phase 3); other stores follow their pages.
- The stores are the only code that touches `cs_auth_key` and `cs_user`.
- Move session validation and WebSocket reconnect into stores with tests.

## Database/API Impact
No schema impact.

## Frontend Impact
Menus, auth state, dashboard state, and realtime state use Pinia.

## Security / Access Rules
Do not store sensitive secrets beyond existing auth token behavior.

## Acceptance Checks
- Login state survives refresh.
- Menu uses permissions.
- WebSocket reconnects after refresh.
- Login state survives refresh.
- Route guards behave identically before and after.
- The menu is built from permissions.

## Risks
- Breaking auth guards during migration.

## Definition of Done
No Vuex import remains; guard tests pass.

## Rollback
Vuex and Pinia can coexist during the move.
