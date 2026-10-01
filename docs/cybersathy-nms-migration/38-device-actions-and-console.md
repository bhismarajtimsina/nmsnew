# Plan 38: Device Actions and Console

> **Phase:** 7 · **Depends on:** 4, 11, 12, 26, 36 · **Status:** Not started · **Owns:** implementation behind Plan 26's safety layer

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
- Accidental execution without confirmation can cause outages (K-07).
- Template injection giving code execution on the worker. Mitigation: sandbox, schema validation, review on edit.
- Interactive console is the widest blast radius; treat it as Dangerous by default.

## Definition of Done
All catalogue entries implemented with driver tests; safety-layer checks pass; hardware sign-offs recorded or explicitly listed as pending in `STATUS.md`.

## Rollback
Actions stay served by the legacy system until this plan reaches Done. After cutover, the action endpoints can be disabled by a single setting while reads continue.
