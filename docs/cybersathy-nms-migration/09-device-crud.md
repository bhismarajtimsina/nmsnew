# Plan 9: Device CRUD

> **Phase:** 3 · **Depends on:** 4, 8 · **Status:** Partial

## Goal
Implement device management APIs for CyberSathy-NMS.

## Current Source / Reference
Current device CRUD is in PHP API routes under `/api/v1/device` and storage classes under `src/Storage`.

## Target Design
FastAPI owns device CRUD and queues safe discovery after add.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add `GET /api/v1/devices`.
- Add `GET /api/v1/devices/{id}`.
- Add `POST /api/v1/devices`.
- Add `PUT /api/v1/devices/{id}`.
- Add `DELETE /api/v1/devices/{id}`.
- Queue only safe discovery after add.
- Device add queues **only** the safe-discovery profile: `sysDescr`, `sysObjectID`, `sysName`, `sysUpTime`. Assert this with a fake transport in tests.
- Credentials are write-only: accepted on input, never returned, never in logs or audit payloads (Plan 36).
- Port `AutoDiscovery` (networks table, schedule) with bounded, rate-limited discovery.
- Provide an admin CLI replacing the `Devices\*` console commands (list, import, access add/update/delete).

## Database/API Impact
Create audit entries for create/update/delete and discovery queue.

## Frontend Impact
Device list/form pages move to typed API clients.

## Security / Access Rules
Apply ISP/reseller scope in backend. Device add/delete requires permission.

## Acceptance Checks
- Device add is fast.
- No BDCOM switch overload/reboot.
- Model detection works.
- Audit log is written.
- Adding a device issues at most the safe-discovery request set (fake transport).
- Credentials never appear in API responses, logs or audit rows (test with a canary value).
- Bulk import of 1,000 devices completes without queuing any non-discovery profile.

## Hardware sign-off
Adding a BDCOM switch does not overload or reboot it. **Not verified against a device.** An operator runs this once, with the lowest rate budget, and records the result. See [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).

## Risks
- Triggering full FDB, full ONU optical, or large private enterprise walks during add.

## Definition of Done
Device CRUD with scope, audit and safe discovery passes; hardware sign-off recorded or listed as pending.

## Rollback
Device management stays on the legacy system until cutover; the new tables hold copies.

## Implementation notes (2026-09-28)
Built and tested (migration 0008, 232 tests in the suite, 30 deliberate breakages caught in total by then):
- **Devices:** `POST/PATCH/DELETE /devices`, plus the scoped reads. A new device starts with **polling off** and owned by this system; turning polling on is a separate, checked step that needs a model family, an access profile, a vendor and family that are not switched off, and a device not still owned by the legacy system. Every reason is returned at once.
- **Registry links:** a device can name its vendor and model family; the device type must match the family (a BDCOM switch cannot be registered as an OLT).
- **Management address:** loopback, unspecified, multicast, link-local, reserved and broadcast are refused, and `MANAGEMENT_NETWORKS` optionally restricts it to your own ranges. Responses show a plain address.
- **Safe discovery:** creating a device queues one discovery job that can name only sysDescr, sysObjectID, sysUpTime and sysName. The database rejects any other OID, so it holds even for code that bypasses the API. The job row is the source of truth and is published to the `discovery.jobs` stream afterwards; if Redis is down the job stays `queued` for the dispatcher. A job is recorded as `skipped`, with the reason, when there is no access profile, the vendor or family is switched off, or the legacy system owns the device. A repeat request is refused for ten minutes.
- **Access profiles:** `/device-access-profiles`. Communities and SNMPv3 secrets are encrypted (AES-256-GCM, bound to the profile and field), accepted on input, and never returned, logged or audited: responses say only `has_community`, `has_auth_secret`, `has_priv_secret`. Incomplete profiles are refused by the API and by the database. A profile in use cannot be deleted.
- **Groups:** `/device-groups`: a tree with unique names per parent, no cycles, no deleting a group that has subgroups or devices.
- **Scope on writes:** the repositories check scope themselves, so a restricted user cannot create in, move to, change or delete anything outside their groups even if an endpoint forgot to check first (tests call the repositories directly to prove it).
- **Delete:** needs `devices.delete` (dangerous, so also the global gate) and the device's exact name as `confirm`. This is the interim for Plan 26's confirmation token.
- **Bulk import:** `python -m app.cli devices import file.csv [--apply]`. Dry run by default; all or nothing; the same rules as the API; 1,000 devices import with polling off and nothing but discovery queued.
- Audited: create, update (only what changed), delete (full record), discovery requests, and every access profile change.

Not done: **model auto-detection from sysObjectID (Plan 8)**, so for now the vendor and model family are chosen by hand; hardware sign-off (adding a BDCOM switch does not overload it) is **not verified against a device**; the discovery worker exists (Plan 12) but runs with the SNMP transport disabled, so no discovery has run against a device; legacy `device_accesses` re-encryption is Plan 32.
