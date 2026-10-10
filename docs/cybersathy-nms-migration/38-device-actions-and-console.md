# Plan 38: Device Actions and Console

> **Phase:** 7 · **Depends on:** 4, 11, 12, 26, 36 · **Status:** Partial (template engine, macro storage, safety flow, two MIB-checked drivers, ICMP diagnostics and the console gateway backend built; SSH/telnet transport, CLI credentials, browser terminal, more drivers and sensor devices to do) · **Owns:** implementation behind Plan 26's safety layer

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

### Second driver: `switch.save_config` on BDCOM (2026-10-09)

**What it writes.** `operation` in NMS-CONFIG-MGMT (`1.3.6.1.4.1.3320.20.15.1.1.0`): read-write, and its
DESCRIPTION reads "1 means to save the command configuration". This is the same OID and value legacy writes for
`olt.save` on BDCOM switches and OLTs.
- The test re-derives the object from NMS-CONFIG-MGMT.my by file, not by name. `operation` is defined with three
  different OIDs across BDCOM's MIBs.
- The test also checks ACCESS and the "1 means" sentence, and that BDCOM-CONFIG-MGMT.my uses the same
  sub-identifiers.

**How it runs.** One SET with the write community, using a 15-second timeout because writing flash is slow (access
profiles stop at 10 s). Then one read of the sibling `result`, recorded raw because the MIB gives it no description.
- A save cannot be read back or undone. Success means the device accepted the write.
- A refused or timed-out write fails with "the device may still have saved its configuration".
- An unanswered follow-up read does not turn an accepted save into a failure.
- Any vendor other than BDCOM is refused before anything is sent: no other vendor's save object is in a repository
  MIB (K-25).
- The device query now also returns the registry vendor.

**Device page.** A "Save configuration" button opens the shared dialog for this one device. It shows whenever the
action can run, and the driver's refusal for other vendors appears in the dialog's results.

**OID-map tool fix.** `tools/bdcom-mib-oid-map.py` kept only the first definition of a repeated name. If that first
definition could not be resolved, the name came out unresolved even when another definition could be.
- That hid one of BDCOM's two `onuReset` objects, which have opposite values; see Plan 14 and D-31.
- The tool now lists every definition in `byoid`, falls back to the first definition that resolves, and reports
  repeated names under `duplicates`. There are 68 such names in the NMS set, `operation` and `onuReset` among them.

**Not built: ONU reboot, reset, deregister and disable on BDCOM.** Which `onuReset` object to write, and whether it
reboots the ONU or restores factory settings, needs the owner or a hardware test (D-31).

Tests: 11 driver tests (including the MIB check and three refused vendors), and one end-to-end test through prepare,
execute and the worker with the real driver on two BDCOM devices and one Huawei device.

Mutation checks: 13, all caught. On the first run three survived; the cause was the mutation run's own test filter,
not missing tests.

Not verified against a device.

### Diagnostics: on-demand ICMP ping (2026-10-09)

**Legacy, for comparison.** The Diagnostic component's ARP ping asks a RouterOS router, through switcher-core, to
ARP-ping an arbitrary address. Its traceroute route throws "Not realized". Its `diag_icmp_ping` rule points at the
`/arp-ping` route, so that key actually grants ARP ping.

**Built: ICMP ping of a device's management address.**
- **Request:** `POST /api/v1/diagnostics/ping` with `{device_id, count}`, `count` 1 to 5. It needs
  `diagnostics.icmp_ping` and a device inside the caller's scope.
  - The request names a device, never an address. Extra fields are refused, and the job carries only a request id.
- **Limits:** 10 a minute per user, and 6 a minute per device across all users, so several operators cannot together
  flood one device.
- **Worker:** a new worker kind, `diagnostics`. It re-checks the account, the permission and the scope with fresh data.
  - It reads the address from the database when it runs, so a changed address is the one pinged.
  - At most 5 echo requests, 1-second timeout, 0.5 s apart. The limits are enforced in the prober as well, so a
    forged job cannot ask for more.
  - A redelivered job never probes twice.
- **Results:** kept only in Redis for ten minutes, readable by the requester alone
  (`GET /api/v1/diagnostics/{request_id}`; 404 for anyone else). The audit log keeps the summary:
  `diagnostics.ping.requested` and `diagnostics.ping`.
- **Notice:** `diagnostics.finished` carries only the request id, and only `diagnostics.icmp_ping` holders may
  subscribe.
- **Off by default.** `DIAGNOSTICS_ENABLED` is off (the compose file says so explicitly).
  - With it off, the API answers 503 and the worker uses a prober that refuses everything.
  - The worker consumes the diagnostics stream only with a real prober, independent of the SNMP transport.
- **Packets:** sent through icmplib, the pinger's library, with the same unprivileged datagram sockets, so the worker
  needs no added capability.
- **Device page:** a Ping button for holders of the permission opens a small dialog that follows the result, woken
  early by the notice.

Two "no management address" checks were removed as dead code: the column is NOT NULL. The full suite caught a real bug in this
round: the new idle message was longer than `worker_heartbeats.status` (varchar(60)), so an idle worker's every
heartbeat would have failed. It is shorter now, and a test checks every status fits.

**Not built:**
- ARP ping and pinging arbitrary addresses through a router. These need the RouterOS API transport, which does not
  exist yet. Pinging addresses other than a device's own also needs its own scope rule.
- Traceroute. Legacy never had it.

Tests: 22 backend tests (`tests/test_diagnostics.py`) and 8 frontend tests. Mutation checks: 18 backend and 8
frontend, all caught on the first run.

No packet was sent while building this; the tests use a scripted prober. Not verified against a device.

### Console gateway, backend half (2026-10-10)

**Legacy, for comparison.** `BaseOpenConsole` runs under ttyd.
- It logs in automatically with the device's stored CLI login and password, or prompts for them in the terminal.
- It kills a session after 1800 s.
- It records history with the user and device.

**Request** (API, `app/console/broker.py`). `POST /api/v1/console/sessions` with `{device_id, auto_auth}` needs
`console.open` and a device inside the caller's scope.
- **Switch:** `CONSOLE_ENABLED` is off by default; while it is off, the API issues nothing (503).
- **Limits:** 2 live sessions per device and 3 per user. Requests for one device are serialised with an advisory
  lock, so two at once cannot both slip under the limit.
  - Expired tickets do not count, and are marked `expired`.
  - An `open` session older than the time limit plus a minute does not count either: its gateway died without
    closing it, and it must not lock the device forever.
- **Ticket:** single use, valid for 30 seconds, shown once and stored hashed. It is kept out of the audit log and out
  of nginx's access log.
- **`auto_auth`:** needs `console.open_auto_auth`, and even then answers 501. Device CLI credentials are not stored
  in this build; access profiles hold SNMP secrets only.

**Gateway** (`app/console/gateway.py`, `python -m app.console`, compose service `cybersathy-console`). A separate
process, so the API never opens a device shell. nginx proxies the exact path `/console/ws` to it.
- **On connect:** the ticket is spent in one statement outside any transaction. The requester is loaded again with
  fresh data and `console.open` and the device's scope are re-checked; a refusal closes the session with the reason.
- **Then:** the shell is opened at the device's address from the database. A banner names the device, the session
  and the user, and says the session is recorded.
- **Default shell factory:** refuses every connection. No interactive (SSH or telnet) transport is part of this build,
  so a session today ends with "not opened" before anything reaches a device.
- **Audit:** `console.requested`, `console.opened`, `console.closed` (with the reason).

**Relay** (`app/console/session.py`). Pure asyncio over two small interfaces, so it is tested without a socket.
- Every chunk is recorded in order in `console_history`. Output longer than the 64 KiB column limit is split.
- A session ends on the first of: the user leaving, the device closing, 10 minutes without input, or 30 minutes in
  total (legacy's limit).
- The gateway records the end before it tells the user. A test found the end record being lost when the user's side
  left first.

**Redaction** (`app/console/redact.py`). The redactor watches the output.
- When the current line is a password-style prompt, every keystroke is withheld from the transcript until Enter,
  and one `[input hidden]` marker is written instead. The keystrokes still reach the device.
- Prompts recognised: password, passwd, passphrase, passcode, secret, PIN, "пароль", "user@host's password", and
  "password for ...". A prompt split across chunks still counts.
- A device that echoes `*` keeps the input hidden. Hiding ends as soon as the device prints anything else on the line.

**Reading transcripts.** `GET /api/v1/console/sessions` and `GET /api/v1/console/sessions/{id}/history` (paged by
`after_seq`) need `console.logs.view`, scoped by the session's device.

**Database** (migration `0026`). Check constraints keep the session states consistent: `open` exactly when opened and
not closed, `closed` exactly when closed. The ticket lifetime is at most 2 minutes, and a transcript chunk at most
64 KiB.

Tests: 32 in `tests/test_console.py` (redaction, relay, tickets, limits, scope, transcripts) and 6 in
`tests/test_console_gateway.py` (real WebSocket handshakes through Starlette's TestClient, with a scripted shell). The
gateway tests were run five times in a row to rule out the timing race above.

Mutation checks: 29, all caught. The first run let three through; each was closed with a new test:
- the idle timer resetting on input;
- the per-user limit on its own;
- an expired ticket on another device.

**Not built yet:**
- an interactive SSH or telnet transport;
- encrypted CLI credentials for automatic login;
- the xterm.js terminal in the browser (the package is not yet a dependency);
- a sweep that closes sessions a crashed gateway left `open` (they already stop counting against the limits).

Each open session holds one database connection for its lifetime.

Nothing here has been run against a device.

### CLI credentials for the console's automatic login (2026-10-10)

**Legacy, for comparison.** `device_access` holds `login` and `password`, both encrypted, next to the communities.
Console settings sit in a free-form `params` field.

**Storage** (access profiles, migration `0027`). Five new fields: `cli_protocol` (ssh or telnet), `cli_port`,
`cli_username`, `cli_password` and `cli_enable_password`.
- **The two passwords:** encrypted with the profile id and field name as context, like the SNMP secrets. They are
  never returned (the API reports `has_cli_password` and `has_cli_enable_password`), never written to the audit log
  (only the names of rotated fields), and covered by key rotation.
- **The username:** stored in plain text, unlike legacy, so operators can see which login a profile uses. D-32 records
  this so the owner can overrule it.
- **Rules:** a password needs a username, and an enable password needs the login password. The API refuses violations
  in words, and the database refuses them as well.
- **Updates:** the same as SNMP secrets: a blank secret keeps the stored one. `clear_cli` removes every CLI setting and
  secret at once, and cannot be combined with new values.

**Console.**
- **Request:** an automatic-login request now needs a CLI username and password on the device's profile, otherwise 409.
  The API process checks only that they exist; it never decrypts them.
- **Gateway:** decrypts the login only for an automatic-login session, and only if the requester still holds
  `console.open_auto_auth` when the ticket is redeemed. Otherwise the session ends with the reason.
- **Manual sessions:** get the profile's protocol and port (SSH on 22 when none is set) but never the stored login. The
  user types it, and the transcript hides it.
- **Stored login:** goes to the shell factory only. A test confirms it appears in neither the transcript nor the audit
  log.

**Access-profile page.** A "Console login (optional)" section with protocol, port, username, and masked password
fields that are never autofilled and are cleared after saving. Editing offers "Remove the CLI login". The list shows
the CLI protocol, port and username, and whether a password is stored.

**Data migration (Plan 32).** Legacy `device_access.login` and `password` map to `cli_username` and `cli_password`.
Protocol and port come from `params` where set.

Tests:
- backend: 4 access-profile tests, the console request test extended, and 3 new gateway tests;
- frontend: 4 new tests and the masked-input test extended.

Mutation checks: 17 backend and 8 frontend. This round's first backend run was cut off by a time limit mid-mutant, and
left one mutant in `app/console/gateway.py` (the auto-login permission check replaced with `if False:`).
- A check that every mutated line was back to its original found it, and the line was restored before anything was
  committed.
- The harness now restores files when it is interrupted, not only when a mutant finishes.

Not verified against a device.

### Browser terminal (2026-10-10)

**Dependencies.** `@xterm/xterm` 6 and `@xterm/addon-fit` 0.11 were added to the frontend, and the lockfile was
updated by npm. `npm audit` reports the same 44 findings before and after; none involve xterm.

**Terminal.** On the device page, a "Console" button (for holders of `console.open`) opens `ConsoleTerminal.vue`.
- A banner says the session is recorded with the user's name and that input at password prompts is hidden.
- Nothing connects until the user presses Connect. Holders of `console.open_auto_auth` can tick "log in with the
  stored login".
- Connect asks the API for a ticket, opens xterm.js, and connects to the gateway at the same origin.
- Closing the dialog ends the session.

**Logic** (`frontend/src/components/console/console.ts`), kept free of xterm and the real socket so it is tested in
Node:
- the gateway address, built from the page's own scheme and host with the ticket escaped; it refuses a path that is not
  on this origin;
- a connection wrapper that sends keystrokes only while the socket reports open, and writes only text frames to the
  terminal. Keystrokes before or after a session are dropped, never queued and replayed into a later session;
- a close reason in words for each gateway close code;
- a small adapter from the browser `WebSocket` to the wrapper's interface.

Tests: 12. Mutation checks: 10, then 9. One survivor showed that the wrapper's own open-state check was redundant with
the socket's `readyState`; it was removed rather than kept.

Not tried in a browser against a running gateway.

### Reading recorded sessions (2026-10-10)

**Device page.** A "Console sessions" tab for holders of `console.logs.view` lists the device's sessions: start time,
user, length, and how each ended. It loads when the tab is first opened. A session that never opened (expired ticket,
refused) has nothing to view.

**Viewer** (`TranscriptViewer.vue`).
- **Device output:** replayed in a read-only xterm.js terminal (`disableStdin`), so colours and cursor moves render as
  they did.
- **Typed input:** shown as lines, with control characters stripped and `[input hidden]` wherever a password was
  typed. The secret was never stored.
- **Header:** device, user, length, how it ended, and whether the stored login was used.

**Logic** (`console.ts`).
- `loadTranscript` pages by sequence number until a short page. It stops after 200 pages (100,000 chunks) and says
  the transcript was cut.
- `splitTranscript` separates output from input.
- `sessionLength` describes a session ("12 min", "2 min so far", "ticket expired unused").

Tests: 6 new. Mutation checks: 11, all caught.

Not tried in a browser against stored sessions.

Still to do: more drivers (OIDs from MIBs only; the BDCOM ONU actions wait on D-31), the console's SSH or telnet
transport (the owner's call, like D-17), and sensor devices.