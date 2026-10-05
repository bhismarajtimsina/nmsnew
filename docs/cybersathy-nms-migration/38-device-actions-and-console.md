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

- SNMP SET support in the transport, still refused by the disabled transport;
- the queued request flow (`actions.jobs`, worker executes);
- the first drivers, each with a fake transport and a recorded transcript;
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
