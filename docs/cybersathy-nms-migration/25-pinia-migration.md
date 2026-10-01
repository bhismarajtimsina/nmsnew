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

### First page on the typed client: the device list (2026-10-01)

**New endpoint.** The legacy page reads `/dev-dashboard/devices`. That route returns each device with its pinger
latency and interface counts. The new `GET /api/v1/devices` has neither, so `GET /api/v1/devices/overview` was added
(`devices.view`, scoped like every device read, database only). Each item carries:

- the device, its group, and its model;
- the pinger's last result (`device_ping_status`);
- interface counts.

Ported from `DeviceListAction.php`:

- An interface counts as up only when its operational status is `up`; every other status counts as down.
- Devices whose last ping failed come first; never-pinged devices sort with the rest.
- Sort is by name or address. There is no location field, so a location sort falls back to name.

**Frontend.** `src/views/devices/deviceList.ts` turns either API's answer into one card shape, and the page filters
and groups those cards. The legacy build makes the same calls as before. With `VITE_AUTH_BACKEND=cybersathy`:

- the page pages through the overview 1000 at a time, capped at 50 pages;
- group options come from `/api/v1/device-groups`, and model options from the models in use (no extra permission
  needed).

Known gaps with the new login, all deliberate for now:

- ~~No live updates.~~ Added the same day; see "Live device list" below.
- ~~Clicking a card opens the legacy device detail page.~~ Fixed the same day: see the next section.
- Disabled devices are shown with "(disabled)", read from `polling_enabled`, where legacy hid them. Which new flag
  stands for legacy's `enabled` is still the open Plan 9/20 question. Showing them hides nothing until that is
  decided.

Tests:

- 6 backend tests: fields, nulls, ordering, search and paging, scope, permission. 8 mutations checked, all caught.
- 11 vitest tests for the data module: both mappings, the WebSocket patch keeping poller fields, paging and its cap,
  errors, options. 9 mutations checked, all caught. One survivor showed a redundant check in the model-options code;
  the code was simplified instead.
- 55 frontend tests in total.
- Not checked in a browser (R-09).

### Device detail page for the new login (2026-10-01)

The legacy `DeviceDetailPage.vue` is built around live device reads: switcher-core system info and resources,
compare-model, console, macros, and OLT and switch tabs. None of those exist in the new API yet. So the new login gets
a separate page, `DeviceDetailNewPage.vue`, and the route picks it only when `VITE_AUTH_BACKEND=cybersathy`. The
legacy build loads the legacy page as before.

The new page is read-only and reads the database only:

- **Header:** from `GET /api/v1/devices/{id}/overview` (added: the list item for one device; 404 when missing or out
  of scope) and `GET /api/v1/devices/{id}`.
- **Interfaces tab** (`interfaces.view`): up to 200 rows. When there are more, the page says so.
- **Events tab** (`events.view`): the latest 50, open and resolved.
- **Polling tab** (`pollers.view`): the latest 20 polls, plus a note when the circuit breaker has paused polling.

A tab whose permission the user lacks is not requested and says why. A refused or failed tab shows its own message
and does not break the page.

Live reads, actions and the type-specific tabs come back as their plans land: Plans 13 to 19 for device reads,
Plan 38 for the console, Plan 27 for topology, and Plan 39 for config backup.

Tests:

- 1 backend test: the single-device overview matches the list item, and returns 404 for an out-of-scope, missing or
  malformed id. 1 mutation checked, caught.
- 10 vitest tests: permissions per tab, request shapes, error handling, ping wording, the route switch, and a check
  that the new page makes no legacy or device-reaching call. 8 mutations checked, all caught.
- 64 frontend tests in total.
- Not checked in a browser (R-09).

### Live device list (2026-10-01)

Creating, editing or deleting a device now publishes `devices.changed` on the realtime channel. Under the new login
the device list subscribes and reloads itself. Several changes within a second cause a single reload.

**The notice carries no device data, only `{"action": "created" | "updated" | "deleted"}`.** The realtime
dispatcher (`ConnectionManager.dispatch`) checks the channel permission (`devices.view`) but not device scope. A
device record in the notice would therefore reach every reseller holding `devices.view`, including those who must not
see that device. The reload goes through the scoped `GET /devices/overview`, so each user only ever sees their own
devices.

Other details:

- The notice is sent after the change commits.
- A refused change (wrong confirmation, invisible device, invalid input) sends nothing.
- A Redis outage never fails the change; the page just waits for its next reload.

The legacy build keeps its own WebSocket merge, unchanged.

~~Not covered yet: the pinger's up/down changes.~~ Added the same day. A pinger pass in which any device goes up or
down ends with one `devices.changed` notice, `{"action": "status"}`. It is one notice per pass, not one per device,
so a site-wide outage is a single reload. The list's online state now follows the pinger without a manual Reload.

- 2 backend tests: a pass with three transitions notifies once and quiet passes not at all (fake ICMP only), and the
  running pinger is wired to the notice. 4 mutations checked, all caught.
- The existing loopback cycle test now also expects `changed: 1`. That test needs real ICMP, which this container
  does not allow, so the updated expectation is not verified here.

Tests:

- 4 backend tests, including one that ties the frontend's channel name to the backend's. That one exists because the
  first round of mutations showed a renamed channel went unnoticed. A wildcard would also be refused for scoped
  users. 7 mutations checked, all caught.
- 3 vitest tests: one reload per burst, unsubscribe and dropping a pending reload on leaving, and wiring only under
  the new login. 3 mutations checked, all caught.
- 67 frontend tests in total.
- Not checked in a browser (R-09).

### Device groups page on the typed client (2026-10-01)

`src/views/devices/deviceGroups.ts` gives the groups page one row shape and four operations (list, create, update,
delete) over either API.

**Legacy build: unchanged.** It sends exactly the same requests and bodies as before (untrimmed name, empty
description left out). Its built-in groups (negative ids) still cannot be edited or deleted, and pushed WebSocket
records are still merged in place.

**New login** (`/api/v1/device-groups`):

- Names are trimmed, and an empty description is sent as `null`.
- The table shows each group's device count instead of the internal id.
- A refused change shows the API's own reason, for example a group that still has devices or subgroups.
- Group create, update and delete now publish the same data-free `devices.changed` notice as device changes. Both
  the groups page and the device list (which shows group names) reload through the scoped API.

Tests:

- 1 backend test: three changes give three notices, and refusals (409 and 404) give none. 3 mutations checked, all
  caught.
- 9 vitest tests: both sources' requests, row mapping, built-in handling, error messages, and the page wiring. 7
  mutations checked, all caught.
- 76 frontend tests in total.
- Not checked in a browser (R-09).

### Device models page on the typed client (2026-10-01)

`src/views/devices/deviceModels.ts` gives the models page one row shape over either API.

**Legacy build: unchanged.** Same call, same default-pollers column, the same edit link, and pushed WebSocket records
are still merged in place.

**New login** (`/api/v1/device-models`, `device_models.view`):

- The catalogue is generated from the legacy model configuration and the vendor registry (Plans 5 to 8), and the API
  offers it read-only. So no row is editable and there is no edit link.
- The page shows each model's vendor and how discovery recognises it (sysObjectID matcher and/or sysDescr pattern)
  instead of legacy's default pollers.
- No live updates: the catalogue only changes when it is regenerated and re-seeded.

Tests: 6 vitest tests (both mappings, the detection wording, and the page wiring). 4 mutations checked, all caught.
82 frontend tests in total. Not checked in a browser (R-09).

### Access profiles page for the new login (2026-10-01)

The two systems' profiles are different things.

- **Legacy:** a community plus CLI login, password and console connection settings.
- **New:** SNMP only (v1, v2c or v3), with secrets the API never returns. It only reports whether each secret is set.

So the new login gets its own page, `DeviceAccessNewPage.vue`, chosen by the router like the device detail page. The
legacy build keeps its page unchanged. The rules live in `src/views/devices/accessProfiles.ts`:

- A stored secret is never shown. Editing starts with every secret field blank, and a blank secret means "keep the
  stored one". Only secrets actually typed are sent, and an edit that changes nothing sends nothing.
- Only the fields for the profile's own version are sent. A v3 profile is never sent a community, and a v2c profile
  is never sent v3 fields.
- The SNMP version cannot be changed after creation (the API does not accept it). The form says so.
- The form is checked before sending (required fields, the API's 8-character minimum for a typed v3 secret).
- The API's own reasons are shown: a duplicate name, a profile still used by N devices, or encryption not configured.
- Secret inputs are masked and marked `autocomplete="new-password"`, and the typed values are cleared from the form
  after every save attempt.

Tests: 13 vitest tests, including a check that the page masks and clears the secret inputs. 7 mutations checked, all
caught. 95 frontend tests in total. Not checked in a browser (R-09).

Still not done in this plan: the remaining pages on the typed client.
