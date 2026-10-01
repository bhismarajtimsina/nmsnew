# Plan 25: Pinia Migration

> **Phase:** 3 (grows through 7) · **Depends on:** 24, 3 · **Status:** Partial

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

## Implementation notes (2026-10-01): auth store, behind a switch

**Production behavior is unchanged by default.** The frontend in this repository is what users of the live legacy
system sign in to, so the new sign-in path sits behind a build-time switch, `VITE_AUTH_BACKEND`:
- `legacy` (the default, and what any build without the setting gets): the existing Vuex auth module and
  `DataService`, exactly as before;
- `cybersathy`: the new Pinia store and the typed client against the new API.

Flip it when the new API is the one serving this frontend (Plan 34), not before.

Built:
- `src/stores/auth.ts` (Pinia): sign-in against `POST /api/v1/auth/login`, including the 2FA step (`need_2fa` asks for
  the code without storing anything), session validation at start-up (`GET /auth/session`; any failure signs the shell
  out, the same as the legacy `checkSession`), sign-out that always clears locally, and `can(permission)` for building
  menus later (convenience only: the API enforces every permission). It is the only code that writes `cs_auth_key`
  and `cs_user`, and it never reads or writes the legacy `wca_*` keys (D-26).
- `src/auth/session.ts`: the switch. The router guard, start-up (`main.ts`) and the sign-in page call only this module.
  The sign-in page shows an authenticator-code field when the new API asks for one.
- `src/router/guard.ts`: the guard's rule as a pure function, tested against a verbatim copy of the old guard for all
  four cases (Plan 25: "route guards behave identically before and after").

Tests: 15 new vitest tests (guard 5, store 8, switch 2), 23 frontend tests in total. 8 mutations checked, all caught,
including flipping the switch's default and the store writing a legacy key. The whole-project `vue-tsc` shows exactly
the 8 errors it had before this change (R-09); the store and guard are also checked strictly.

Not done: the permissions-driven menu, the realtime store (WebSocket reconnect using the ticket), the remaining Vuex
modules (`themeLayout`), and moving pages to the typed client. `vite build` cannot be run from the repository until
R-09's missing files are added.

## Implementation notes (2026-10-01): realtime store

`src/realtime/client.ts` (`RealtimeClient`) and `src/stores/realtime.ts`, used with `VITE_AUTH_BACKEND=cybersathy`.
The legacy build keeps `services/wsClient.ts`. Same shape as the legacy client: one shared socket, channels
resubscribed after every reconnect, exponential backoff capped at 30 seconds and reset once connected, and a full stop
on sign-out that also cancels a pending reconnect. Changes for the new API:
- every connection, first or reconnect, mints a fresh single-use ticket (`POST /realtime/ticket`, `/ws?ticket=`), and
  a used ticket is never reused;
- three ticket refusals in a row (close code 4401) stop the client instead of looping;
- a 401 while minting means the login is gone, so the client stops (the API client has already signed the user out);
  any other minting failure is retried with backoff.

The auth store starts the connection after sign-in and after a successful session check, so it comes back after a
page refresh (Plan 25's acceptance check), and stops it on sign-out. The socket, the ticket call and the timers are
injected, so the tests drive reconnects without a browser or a server. 8 new tests (7 client, 1 store); 31 frontend
tests in total. 9 mutations checked, all caught.

### Permission-driven menu (2026-10-01)

`src/auth/menu.ts` maps every sidebar entry to the permissions that open its page; holding any one of them shows it.
A submenu, and each section title, shows only when something under it does. `src/layout/Aside.vue` guards every
entry with `v-if="show(key)"`.

- With `VITE_AUTH_BACKEND=cybersathy` the menu follows the Pinia store's permissions, re-rendering when the session
  check replaces them. In the legacy build (the default) nothing is filtered and the menu is exactly as before.
- Fails closed: a key without a rule is hidden. phpMyAdmin is hidden from everyone with the new login, because it
  leaves with MySQL (permission-mapping.md, D-12).
- Hiding is convenience only. The API enforces every permission itself.

The rules use the permissions the API actually checks for the matching data (for example `traps.view` for SNMP
traps, `device_models.view` for models, `roles.view` for roles). Where the new API has no page yet (map, nearby, the
topology views), the rule uses the closest legacy-mapped code (`devices.view`, `links.view`). Revisit these when Plans
27 and 28 add `maps.view` and `topology.view`.

Tests:

- 9 vitest tests. One reads `Aside.vue` and fails if an entry has no rule, a rule has no entry, a submenu's children
  differ, or any entry is left unguarded.
- 1 backend test (`tests/test_menu_permissions.py`) fails if the menu names a permission the catalogue does not
  define, which would hide that page from everyone.
- 40 frontend tests in total. 8 mutations checked, all caught.

Not checked in a browser: the frontend still cannot be built from git (R-09, 8 missing log views), so the rendered
menu was verified by tests and type-checks only.

### Theme and layout store (2026-10-01)

`src/stores/layout.ts` replaces the `themeLayout` Vuex module, which is deleted. It keeps the same fields (dark mode,
text direction, top or side menu, main template) and the same starting values from `src/config/config.ts`. App.vue,
AdminLayout.vue, Aside.vue, Drawer.vue and autoComplete.vue read it instead of Vuex.

One deliberate change: the old actions set `loading` and applied the change 10 ms later. While `loading` was set,
App.vue replaced the whole application with a spinner, so every toggle unmounted every page. The new setters apply
the change at once and there is no `loading` state. No visible control calls these toggles today, so users see no
difference.

The Vuex store now holds only the legacy auth module. It goes when the auth switch flips (Plan 34).

Tests:

- 4 vitest tests, 44 frontend tests in total. One fails if any source file still reads `themeLayout`. Vuex state is
  untyped, so a stale read would otherwise only show up at run time.
- 6 mutations checked, all caught.
- Not checked in a browser (R-09: the frontend cannot be built from git).

Still not done in this plan: moving pages to the typed client.
