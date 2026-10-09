# Plan 38: Device Actions and Console

> **Phase:** 7 · **Depends on:** 4, 11, 12, 26, 36 · **Status:** Partial (template engine built; executors, transport writes, macros storage, diagnostics and console to do) · **Owns:** implementation behind Plan 26's safety layer

## Goal
Implement every device-changing or device-probing action, macro, registration template and console session in the new system, all through the shared safety layer.

## Current Source / Reference
Components `OltsControl` (9 routes), `SwitchesControl` (5), `Macros` (13), `OntsRegistration` (14), `Console` (3), `Diagnostic` (4), `SensorDevices` (8); legacy permission keys in [permission-mapping.md](permission-mapping.md) flagged Dangerous; the `wca-ttyd` container; `multi_console_command` execution; Twig template rendering in `MacrosGateway`.

## Target Design
Actions are **requests**, not calls. A request passes permission, scope, confirmation and rate checks, is queued as a job, executed by a worker with device access, and produces a result event and an audit record. The API process never talks to a device for an action.

A separate `console-gateway` service owns interactive shells (SSH/telnet) and the browser terminal: our own gateway with an xterm.js frontend, replacing the legacy ttyd container. It has its own access to encrypted device credentials; the API does not.

## Implementation Steps
1. **Action catalogue.** One table listing every action: code, target type, required permission, Dangerous flag, parameters schema, device capability required, dry-run supported, bulk allowed. Seeded from [permission-mapping.md](permission-mapping.md).
2. **Request flow (Plan 26):** `POST /actions` (validate, scope-check, return summary and a single-use confirmation token bound to user, target, action and parameters) → `POST /actions/{id}/confirm` → job on `actions.jobs` → worker executes → `action_results` and event.
3. **Drivers per vendor family** implementing `execute(action, target, params)` behind a `DeviceTransport` interface. Drivers are tested only with fake transports and recorded transcripts.
4. **Macros and registration templates.** Templates are code-like. Keep Twig-equivalent rendering in a sandboxed environment (Jinja2 sandbox, no attribute access to internals, no file or network functions). Parameters validated against the declared schema before rendering. Editing a template requires `macros.edit` or `onus.registration.configure`, is audited, and shows a rendered preview against sample data that never touches a device.
5. **Bulk actions:** hard cap on targets per request, dry-run summary showing every target, one confirmation for the set, per-target result rows, and stop-on-first-failure option.
6. **Diagnostics** (ARP ping, ICMP ping, traceroute): live probes, rate-limited per user and per device, results not persisted beyond the audit record.
7. **Console gateway:** session broker issuing short-lived single-use session tickets, full command transcript stored in `console_history` with the actor, redaction of secrets typed at password prompts, idle timeout, and per-device concurrent-session limit. The browser terminal is our own xterm.js component connected to the gateway, available only to users with `console.open`; auto-auth injection needs `console.open_auto_auth`.
8. **Sensor devices:** relay/mode switching is a Dangerous action under the same flow.
9. **Undo hints:** where an action has a defined inverse (set description, port admin state), record the previous value in the audit `before` so an operator can revert.

## Database/API Impact
`action_requests`, `action_confirmations`, `action_results`, `macros`, `macro_runs`, `onu_registration_templates`, `console_sessions`, `console_history`. Endpoints under `/api/v1/actions`, `/api/v1/macros`, `/api/v1/onus/registration`, `/api/v1/console`, `/api/v1/diagnostics`.

## Frontend Impact
One shared confirmation modal showing target list, parameters, dry-run summary and a typed acknowledgement for high-impact actions. Console opens in an embedded terminal with visible session banner.

## Security / Access Rules
- Dangerous actions need the fine-grained code, `dangerous_actions.execute`, in-scope targets and a valid confirmation token.
- Resellers act only inside assigned scope; bulk requests are scope-filtered server-side, not by the client.
- Credentials are never returned to the client or logged. The console gateway holds them, not the API.
- Every request, confirmation, execution and result is audited with before/after context.

## Acceptance Checks
- An action without a confirmation token is refused; a token cannot be reused, transferred to another target, or used after expiry.
- A reseller cannot act on an out-of-scope device, including through a bulk request that mixes targets.
- A bulk request above the cap is refused.
- Template rendering rejects sandbox escapes (test corpus of hostile templates).
- Every action has a driver test using a fake transport and a recorded transcript.
- Console transcript is stored with the actor, and a password typed at a prompt does not appear in it.

**Hardware sign-off (not automated):** for each vendor family, a named operator runs one low-impact action (for example set a port description on a lab device) and records the result. Until then the status is *not verified against a device*.

## Risks
- **Legacy BDCOM action names do not say what they do (found 2026-10-01, Plan 14).** Checked against BDCOM's own
  NMS-GPON-MIB: legacy `ont.action.delete` (1.3.6.1.4.1.3320.10.3.2.1.2) is `gponOnuConfigActicate`, an activate
  control, and `ont.action.disable` (…10.3.2.1.3) is `gponOnuConfigEnable`. An action ported by its legacy name would do
  something other than its label says. Every action OID must be re-derived from the MIB object it targets, with the
  object's own name and description, before it is wired to a button; tests/test_bdcom_olt_profiles.py pins these two.
- **More legacy action mislabels (found 2026-10-01, Plan 17), from the legacy OID files alone (no MIB exists for
  these vendors in the repository, K-25):** C-Data EPON `ont.action.reboot` and `ont.action.resetOnu` are the same OID
  (…17409.2.3.4.1.1.17, value 1), so "reset" and "reboot" are one action; C-Data GPON's `ont.action.delete` sits in the
  EPON subtree (…17409.2.3.4.5.2.1.4); V-Solution V1600G `uni.control.set.adminStatus` is {0: Enabled, 1: Disabled},
  the reverse of V1600D, and its `ont.uni.adminStatus` map lists link speeds. None of these may be wired to a button on
  the strength of its legacy name. Legacy action names are kept out of polling by `app/vendors/legacy_actions.py`.
- Accidental execution without confirmation can cause outages (K-07).
- Template injection giving code execution on the worker. Mitigation: sandbox, schema validation, review on edit.
- Interactive console is the widest blast radius; treat it as Dangerous by default.

## Definition of Done
All catalogue entries implemented with driver tests; safety-layer checks pass; hardware sign-offs recorded or explicitly listed as pending in `STATUS.md`.

## Rollback
Actions stay served by the legacy system until this plan reaches Done. After cutover, the action endpoints can be disabled by a single setting while reads continue.

## Implementation notes (2026-10-05)

**Built: step 4's template engine** (`app/actions/templates.py`), the part every macro and ONU-registration template
goes through. Nothing in it reaches a device.

Legacy defects found while porting (R-10, read from origin/main's `MacrosGateway.php`):

- **Templates render unsandboxed.** Legacy uses a plain Twig 3.10.3 `Environment` with no sandbox extension, so a
  macro editor writes template code that runs inside the NMS server. How far that reaches was not tried.
- **Parameters can inject commands.** Text parameters are checked with an unanchored `preg_match("/{regex}/")`, and
  an empty pattern accepts anything. A value such as `10 ; reboot`, or one with a newline, passes; the newline becomes
  an extra console command once the template is sent line by line.

**Rendering:**

- Jinja2's immutable sandbox, with undefined variables as errors.
- No loader. `include`, `import`, `from` and `extends` are refused when the template is checked.
- No globals except a `range` capped at 4096. String, list and power operators are capped before anything large is
  built.
- Caps on template size (20,000 characters), output (64,000 characters) and commands (500).
- Variables are copied down to plain data first, and keys that look like secrets are dropped, so nothing callable
  and no credential reaches a template.
- Legacy's `<exception "message">` line is kept: it lets a template refuse to run.

**Parameters:**

- Declarations are checked when a template is saved: known types, valid keys, non-empty variant lists, patterns that
  compile.
- At run time every declared parameter must be given and nothing else.
- Text must match its pattern in full; a text parameter with no pattern gets a conservative default instead of
  "anything".
- Selections must come from their list, including a list taken from device data.
- No value may contain a control character, so neither a newline from device data nor one let through by a
  permissive pattern can add a command.

Tests: 58 (`tests/test_action_templates.py`), including 29 hostile templates. 24 mutations checked, all caught (two
needed stronger tests, which were added; one redundant step was removed).

Still to do:

- more drivers, each with a fake transport and a scripted exchange;
- diagnostics, the console gateway and sensor devices.

Not verified against a device.

### Macro and registration-template storage (2026-10-05)

Built: migration `20261005_0022` (table `macros`, one table for both kinds), `app/repositories/macros.py`, and
`app/api/macros.py`.

**Routes.** `/api/v1/macros` (view `macros.execute`, edit `macros.edit`) and `/api/v1/onu-registration-templates`
(view `onus.registration.preview`, edit `onus.registration.configure`). Each offers list, read, create, update,
delete, preview of a saved template, and preview of an unsaved draft.

**Saving.**

- Every template and parameter declaration goes through the template engine when saved. A template that doesn't
  parse, uses `include`, `import` or `extends`, or declares a pattern that doesn't compile is refused with the reason.
- Names are unique within a kind.
- A save carries the version it was based on. If someone else saved first, it is refused with 409, so no edit is lost
  silently.
- Create, update and delete are audited with the template before and after.

**Previews** render against sample variables the caller supplies and never touch a device.

- Parameters are validated exactly as for a real run, so `5 ; reboot` or a newline is refused in a preview too.
- A template's own `<exception>` line comes back as `aborted` with its message.
- Secrets in the sample data are dropped before rendering.

Running a template against a device is not here. It will go through Plan 26's confirmation flow once the queued
executor exists.

Tests: 18 (`tests/test_macros_api.py`). 16 mutations checked: 15 caught, and 1 was a redundant check, which was removed.

Found while testing previews: the shared secret filter (also used by the audit log) matched `token` but not `tokens`, `password` but not `passwords`, `community` but not `communities`, so a list of credentials under a plural key passed through. It now covers plurals (`tests/test_audit.py`). The access-profile audit key `secrets_rotated`, which holds field names only, became `rotated_fields` so the wider filter does not hide it.

### Writing to devices: transport, write credentials and the first driver (2026-10-05)

**A separate write community.** Legacy keeps two communities per access profile: `public_community` (read) and
`private_community` (write). The new schema had only the read one, so migration `20261005_0023` adds an encrypted,
optional `snmp_write_community`.

- It is accepted on create and update, never returned (`has_write_community` says whether one is set), and covered by
  key rotation.
- It is refused on SNMPv3 profiles, which write with their own user.
- The frontend's access-profile page has the field, masked and never autofilled.
- Polling never decrypts it; only the action driver does.
- The trap community check now accepts either community, as legacy's `handleTrap` does.

**Bounded SNMP SET** (`BoundedTransport.set`). Refused before anything is sent when:

- there are no values, or more than 4;
- an OID is not numeric;
- an integer isn't a 32-bit integer, or text is longer than 255 characters or has a control character;
- a v1/v2c target has no write community.

The request carries the write community only, never the read one, and a write is never retried automatically. The
production `DisabledTransport` refuses every SET.

**First driver: `switch.port.set_admin_state`** (`app/actions/drivers.py`), on RFC 1213 `ifAdminStatus`. Its test
re-derives the OID from `RFC1213-MIB.my` and checks it is read-write with up(1), down(2), testing(3). The driver:

1. resolves the interface and device through the scoped repositories;
2. refuses when the profile has no write community;
3. reads the current state, and if it is already as asked, writes nothing;
4. writes, reads back, and fails if the device reports anything else.

Device errors are scrubbed of credentials. The previous state is recorded in `before` as the undo hint. The reads use
the write credentials too, so this path never needs the read community.

**Not reachable at first, on purpose.** The driver was registered only once the queued flow and the protected-port
rule below existed (2026-10-08).

Tests: 20 (`tests/test_action_drivers.py`), plus write-community cases in `tests/test_access_profiles.py` and
`tests/test_trap_ingest.py`, and a frontend test. The exchanges are scripted, not recorded from a device. A
"no ifIndex" check was removed as unreachable: `interfaces.if_index` is `NOT NULL`. 20 mutations checked: 19 caught,
and 1 exposed a redundant guard in the trap check, which was removed.

Not verified against a device.

### The queued flow, protected ports and the kill switch (2026-10-08)

Built: migration `20261008_0024`, the `actions` worker kind (`app/workers/runner.py`, `handle_action`), and the
queued execution in `app/actions/safety.py`.

**Actions are requests, not calls.**

1. `POST /actions/{action}/execute` consumes the confirmation and records one `queued` result per target. The API
   then publishes a signed job on `actions.jobs` and returns at once. The API process never talks to a device. If the
   job can't be published, the queued results are marked failed, nothing is sent, and the confirmation stays spent.
2. A worker of kind `actions` claims each queued result atomically (`for update skip locked`), so a redelivered job
   never runs a target twice. A worker on the disabled transport consumes no jobs at all.
3. Before running each target, the worker rebuilds the requester from the database and checks again, with fresh
   data: that device actions are still switched on, that the account is still active, that its role still has the
   permission and the gate, and that the target is still in scope. Any failure records the target as `refused` with
   the reason.
4. Each result goes `queued` → `running` → `succeeded`, `failed` or `refused`, with before/after context (secrets
   redacted) and an audit entry. The database requires a finish time exactly when a result is final.
5. `GET /actions/results/{confirmation_id}` shows progress to the requester only; anyone else gets 404.

**Kill switch.** `DEVICE_ACTIONS_ENABLED` is off by default. While it is off, `prepare` answers 503, and a job
queued before it was turned off is refused when it runs.

**Protected interfaces.** `interfaces.protected`, set with `PUT /interfaces/{id}/protection` (`interfaces.manage`,
scoped, audited with the previous value), marks a port no action may shut down: typically the uplink a switch is
managed through. The port driver refuses to set a protected interface down before anything is sent; bringing it up is
allowed. The interface API now returns `protected`.

**Registered:** `switch.port.set_admin_state`, the only driver so far. Every other action answers 501. The driver can
reach a device only when all of these hold: DEVICE_ACTIONS_ENABLED is on; a real SNMP transport is configured
(decision D-17, still open: the build ships the disabled one); and a write community is set on the device's access
profile.

Tests: 13 in `tests/test_action_queue.py`, 1 new in `tests/test_action_drivers.py`, and the 23 Plan 26 tests now run
through the queue.

17 mutations checked, all caught (one needed a new test: only an `actions` worker may consume action jobs). The deployed worker and the CLI default now include the `actions` kind; the compose file sets `DEVICE_ACTIONS_ENABLED: "false"` explicitly.

### Frontend: confirmation dialog, results and port controls (2026-10-09)

`frontend/src/components/actions/`:

- `actions.ts` holds the typed calls (list, prepare, execute, results, protection) and the rules:
  - `canRun`: an action is offered only with the global gate and an entry `GET /actions` reports `available`.
  - `ackPhrase`: a typed acknowledgement is required for every bulk request (type "N targets"), for reboot, factory
    reset, deregister and disable, and for shutting a port down (type the port, ONU or device name the dry run
    resolved). A single low-impact request needs only the click.
  - `followResults`: polls `GET /actions/results/{id}` every 2 seconds until every target has finished. It stops
    after 150 polls or when the dialog closes; closing stops the following, not the work.
  - `actionError`: shows the API's own reason, with fallbacks for 409 (expired or used confirmation), 501 and 503.
- `ActionConfirmModal.vue` runs the dry run when it opens and lists the resolved targets and parameters. Send stays
  disabled until the acknowledgement matches. After sending, the dialog shows each target's status and error.

On the device page (`DeviceDetailNewPage.vue`), the interface table gains a Protected column: a switch for holders of
`interfaces.manage`, a tag for everyone else. It also gains Enable and Disable buttons, shown only when the port
action can run. Disable is greyed out on a protected port; the driver refuses it anyway. When an action finishes, the
interface list reloads.

Tests: 17 vitest tests in `actions.test.ts`. 14 mutations checked, all caught; the first run left three survivors.
Two were fixed with new tests: a bulk request of exactly two targets, and unprotecting sends `false`. The third was
an equivalent mutant (a target never has both an interface and an ONU) and was replaced with a meaningful one.

Not verified in a browser against a running API with a real worker, and not verified against a device; the
build's SNMP transport is still the disabled one (D-17).

### Stop on first failure and the realtime results notice (2026-10-09)

**Stop on first failure.** `stop_on_failure` (default false) can be sent with prepare and execute.
- It is part of what is confirmed: it is stored on the confirmation (migration `0025`), shown in the dry-run summary
  and audited. A token presented with the other value is burned and refused, like a changed target.
- The worker stops at the first target that does not succeed, whether it failed or was refused. Targets still queued
  become `skipped`, a new final status that the database requires to carry a reason. The skip is audited once
  (`action.skipped`, with the count).
- A target another worker is already running is left alone.
- The dialog offers the option on every bulk request, ticked by default. Changing it runs the dry run again, since
  the old confirmation no longer matches.

**Realtime notice.** After a worker has run a confirmation, it publishes `actions.finished` carrying only the
confirmation id.
- The channel needs `dangerous_actions.execute` to subscribe.
- Dispatch is not per user, so other gate holders can see that some action finished. The id gives them nothing:
  results answer 404 to anyone but the requester.
- A Redis failure is logged, never raised; the action has already run.
- The dialog still polls every 2 seconds. A notice for its own confirmation wakes the current wait at once, and a
  notice arriving between two polls is remembered.

Writing the tests found a bug in the wake helper: a timer left over from an earlier, woken wait could end the next
wait early. Each wait now ends only through its own timer, and a test covers it.

Tests: 10 new backend tests in `tests/test_action_queue.py` and 7 new frontend tests.

Mutation checks: 12 backend and 7 new frontend mutations, all caught. The first runs let two through:
- skipping targets already `running`, closed with the test above;
- matching a notice that carries no id, closed with a new test.

Not verified in a browser against a live worker, and not verified against a device.

Still to do: more drivers (OIDs from MIBs only), diagnostics and the console gateway.