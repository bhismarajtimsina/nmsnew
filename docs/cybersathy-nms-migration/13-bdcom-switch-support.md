# Plan 13: BDCOM Switch Support

> **Phase:** 5 · **Depends on:** 11, 12, 6, 8 · **Status:** Partial (profile drafted and MIB-checked, bounded MAC lookup built; fixtures and hardware sign-off pending)

## Goal
Implement safe BDCOM switch support separate from BDCOM OLT support.

## Current Source / Reference
Current BDCOM switch OIDs and modules exist under switcher-core BDCOM configs and `BDcom/Switches`.

## Target Design
BDCOM switches have their own profile.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support system, resources, interfaces, interface status, counters, errors, private BDCOM LLDP tree, and SFP DDM if present.
- Disable full FDB walk, console FDB, and full private enterprise walk by default.
- Implement bounded MAC lookup only.
- Add a fixture set per BDCOM switch model listed in migrations 062 and 070, captured by an operator per [the policy](safety-and-verification-policy.md#3-fixtures).
- Bound the private BDCOM LLDP tree with `max_rows` and a timeout; keep full FDB, console FDB and full private-enterprise walks disabled.
- MAC lookup is a bounded query for one MAC, permission-gated (`search.mac`), rate-limited, and audited.
- Run the existing offline tools (`bdcom-oid-coverage.php`, `bdcom-mib-oid-map.py`, `bdcom-trap-audit.py`) in CI.

## Database/API Impact
Store switch interfaces, counters, errors, LLDP neighbors, and SFP readings.

## Frontend Impact
Switch detail pages show status, interfaces, errors, LLDP, and optical data.

## Security / Access Rules
FDB/MAC lookup is permission-gated and bounded.

## Acceptance Checks
- Adding BDCOM switch does not overload/reboot switch.
- OLT behavior untouched.
- MAC lookup works only as bounded lookup.
- Every fixture parses.
- The BDCOM switch profile contains no full-table walk.
- MAC lookup issues one bounded request with the fake transport.

## Hardware sign-off
Adding and polling a BDCOM switch does not overload or reboot it. **Not verified against a device.** See [the policy](safety-and-verification-policy.md#4-hardware-sign-off-is-a-separate-explicit-step).

## Risks
- Mixing BDCOM switch and OLT profiles can trigger unsafe OLT/Switch polling.

## Definition of Done
Fixtures parse, profile bounds verified, offline tools green; hardware sign-off recorded or pending.

## Rollback
Profile can be disabled per vendor with the Plan 5 kill switch.

## Implementation notes (2026-10-01)

Nothing here contacted a device. The source was the legacy switcher-core definitions
(`configs/oids/bdcom/switch-common.yml`, read from `origin/main`'s tree without merging it) checked against BDCOM's
own MIB files in `NMS_BDCOM_MIBS/`.

**Profile `bdcom_switch_basic`** (`app/registry/bdcom_switch_oids.py`, seeded by `_seed_bdcom_switch_profile`):

- 16 read-only entries, all bound to vendor `bdcom` and family `bdcom-switch`:
  - identity: serial number, hardware and software versions, MAC (scalar GETs);
  - per-card CPU and memory, and CPU over 5 s, 1 min and 5 min;
  - LLDP neighbours: remote chassis id, port id, port description and system name, from BDCOM's private copy of
    LLDP-MIB (`nms 127`);
  - SFP transmit and receive power, temperature and voltage.
- Every walk is bounded: 16 rows for cards, 256 for neighbours, 512 for ports, each with an 8 s timeout. Units are
  the MIB's own words (0.1 dBm, 1/256 °C, 0.1 mV, %).
- **Seeded as a draft, never active.** The engine only polls active profiles. Activation waits for a fixture per model
  family and an operator's hardware sign-off (policy sections 3 and 4).

What each test re-checks from the MIB text on every run:

- the OID is the one BDCOM's MIB assigns to that object, in the file named next to it;
- the object's `ACCESS` is `read-only`;
- a scalar is read with `get`, and a table column with a bounded `walk`;
- the profile passes the registry rules;
- no entry is under the BRIDGE or Q-BRIDGE FDB tables, and no walk is near the private enterprise root.

Left out on purpose:

- **Every writable object** the legacy file declares: config save, STP options, loop detection, QoS trust, PVID,
  thresholds and more.
- **The full FDB tables.**
- **`stp.port.*` and `stp.bridge.*`.** These are legacy names the MIB contradicts: they point at OIDs that
  NMS-IEEE8023-LAG-MIB defines as link-aggregation objects (`dot3adAgg*`). Whatever legacy shows as STP from these is
  LAG data. A test documents this.
- **36 legacy names with no MIB in the repository** (for example `sfp.ddm.present`, `sys.versionString`, `vlan.*`):
  they cannot be checked offline, so they wait for a fixture.

**Bounded MAC lookup** (`app/services/mac_lookup.py`):

- For one MAC and up to 32 VLANs, it builds the exact `dot1qTpFdbPort.<fdbId>.<MAC>` rows and fetches them in a
  single GET. It never walks the FDB.
- An empty, oversized or out-of-range VLAN list is refused rather than trimmed.
- The OID is re-derived from the real Q-BRIDGE-MIB module. The repository's `Q-BRIDGE-MIB.my` contains a second
  module, really P-BRIDGE content at `dot1dBridge 6`, that reuses the same names; the test resolves only the real one.
- On BDCOM the filtering-database id is assumed to equal the VLAN id. That assumption needs a fixture, so a miss
  means "not learned in these VLANs".

**Found on the way:** the polling engine sent `get` entries to bare object OIDs, which a real agent answers with "no
such instance". The active `system_basic` profile would have read nothing from a real device. It is fixed in the
engine (`scalar_instance`); see STATUS.md "Found and fixed".

Still to do:

- value transforms for the SFP readings: scale, plus the legacy "no reading" sentinel -65535 and the < -40 dBm
  cut-off, which need a fixture;
- parsers and storage for LLDP neighbours and SFP readings, and the switch detail page;
- running the three offline tools in CI;
- **fixtures, captured by an operator** under `backend/tests/fixtures/bdcom/<model>/`, then the hardware sign-off.
  Not verified against a device.

Tests: 39 for the profile, 18 for the MAC lookup. 13 mutations checked, all caught. One, seeding the profile as
active, is also refused by the database's own immutability trigger.

## Implementation notes (2026-10-17): the MAC lookup endpoint

`POST /search/mac` takes a device, a MAC and the VLANs to look in (1 to 32). `GET /search/mac/{request_id}` returns
the result to the requester only. It is built the same way as the on-demand ping (Plan 38).

- **API** (`app/api/mac_lookup.py`):
  - needs `search.mac` and the device inside the caller's scope;
  - checks the MAC and the VLANs as the worker will (range 1 to 4094, at most 32, duplicates folded);
  - rate-limited per user and per device, in windows of its own: running out of pings does not block lookups;
  - audited, and queues a signed job that names the request, never an address.
- **Transport off:** while `SNMP_TRANSPORT` is `disabled` (D-17), the API refuses with 503 and queues nothing, so no
  job waits for a transport that cannot send.
- **Worker** (`handle_mac_lookup`, consumed by `diagnostics` workers only when there is a transport):
  - checks the requester again with fresh data: account active, permission held, device still in scope;
  - applies polling's guards: owned by this system, an access profile, vendor not switched off, circuit breaker not
    open. "Polling switched off" alone does not stop a one-off lookup.
  - sends the one bounded GET, with the address and credentials from the database;
  - writes the result to the request record, scrubbing credentials from any device error, and audits it;
  - announces a finished request on its own notice channel, `search.mac_finished`, which only `search.mac` holders
    may subscribe to.
  - A redelivered job never asks the switch twice.
- **Reading a result:** a hit names a bridge port, not always an ifIndex. A miss means "not learned in these VLANs".

Tests: 22 in `tests/test_mac_lookup_api.py`, against the fake transport. 22 mutations checked, all caught. The first
run's three survivors were closed:
- the rate-limit test now fills the requester's own ping window;
- the worker-kind test now runs before the transport is disabled;
- one redundant scope condition was removed.

Not verified against a device.
