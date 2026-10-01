# Plan 19: Router Support

> **Phase:** 5 · **Depends on:** 11, 12 · **Status:** Partial (no RouterOS API client or vendor MIBs yet, K-25, K-26)

## Goal
Implement MikroTik RouterOS and L3 router support.

## Current Source / Reference
Current RouterOS components and switcher-core modules support interfaces, resources, ARP, routes, BGP, leases, and queues.

## Target Design
Router support is profile-based and bounded.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Support interfaces, resources, ARP, routes, BGP sessions, DHCP leases, and simple queues where available.
- Add bounds for ARP and lease tables.
- Normalize BGP states.
- Bound ARP, route and DHCP-lease tables with `max_rows` and a page strategy; log truncation as a metric.
- Normalize BGP states through one table.
- Decide RouterOS API versus SNMP per metric and record it.
- Fixtures for RouterOS and one L3 vendor.

## Database/API Impact
Add router/BGP/ARP history as needed.

## Frontend Impact
Router pages show resources, interfaces, BGP, routes, and leases.

## Security / Access Rules
Router actions require router permission and assigned scope.

## Acceptance Checks
- RouterOS resources load.
- BGP state normalizes.
- ARP/lease tables are bounded.
- A router with more rows than `max_rows` is truncated and flagged, not fully walked.
- BGP states map to the common set.

## Risks
- Router tables can be large and must not be walked without bounds.

## Definition of Done
Fixtures parse; bounds verified.

## Rollback
Per-vendor kill switch.

## Implementation notes (2026-10-01)

Nothing here contacted a device. The source was legacy switcher-core (`Modules/RouterOS/*`, `Modules/Juniper/*`) and
`configs/oids/global.oids.yml`, read from `origin/main` without merging.

**Bounded tables, truncated and flagged.** Building this check found a real bug in the polling transport.
`BoundedTransport` flagged truncation only when a transport returned *more* rows than asked for. A well-behaved
transport stops at exactly `max_rows`, so a router table larger than its cap was cut silently and reported as
complete; an existing test even asserted that. Now:

- one probe row past the cap is asked for, and getting it back means the table was cut;
- a cut table is flagged in `polling_results`, logged, and counted in the `cybersathy_poll_truncations_total` metric
  (labelled by profile);
- getnext reads (one row by design) ask for no probe.

For RouterOS API replies, `take_bounded` applies the same rule to a reply stream. It reads one row past the cap and
nothing after it. Caps: ARP 4096, DHCP leases 4096, connected networks 1024, BGP peers 256, simple queues 2048.

**BGP states through one table** (`app/vendors/router.py`). The BGP state machine's words map to five common states:
established, connecting, idle, disabled and unknown. BGP4-MIB's `bgpPeerState` codes 1–6 and RouterOS 6's `state`
strings go through the same table.

- An administratively stopped SNMP peer, or a RouterOS peer with `disabled=true`, is `disabled` whatever its state.
- A RouterOS peer with no state is legacy's "down", which is idle.
- Anything unrecognised is `unknown`, never up.

**RouterOS API versus SNMP, per metric** (`METRIC_SOURCES`, each with its reason):

| Metric | RouterOS | SNMP-managed L3 router | Why |
|---|---|---|---|
| Interfaces | SNMP | SNMP | RFC 1213 `interface_basic`, already active |
| Resources | API (CPU, memory); SNMP (health) | none yet | MIKROTIK-MIB has health but no CPU load; HOST-RESOURCES-MIB not in the repository |
| Connected networks | SNMP | SNMP | RFC 1213 `ipAddrTable`, read-only (`router_addresses`) |
| ARP | API | none yet | RFC 1213 ARP table is read-write (D-30) |
| BGP sessions | API | SNMP | BGP4-MIB file not in the repository yet |
| DHCP leases | API | none | no standard MIB |
| Simple queues | SNMP | none | MIKROTIK-MIB queue table (`mikrotik_routeros`) |

**`router_addresses` profile** (`app/registry/router_standard_oids.py`). IPv4 address, interface and mask from
RFC 1213's `ipAddrTable`, re-derived from `RFC1213-MIB.my` and capped at 1024 rows. Seeded as a draft. This is how
legacy derives "routes" too: from addresses, never from the routing table, so a router carrying a full BGP table is
never walked for it.

Recorded, not guessed away:

- **Legacy RouterOS BGP is v6-only.** It reads `/routing/bgp/peer/print`, which RouterOS 7 replaced, so on v7 it
  shows no sessions (K-26).
- **Legacy ARP and lease reads are unbounded** `print` calls.
- **RFC 1213's ARP and route tables are read-write**, so they wait on D-30.

Tests: 29 in `tests/test_router.py` and 6 more for the truncation probe and metric (`tests/test_transport.py`,
`tests/test_polling_engine.py`). Five existing tests were updated for the probe row; one of them had asserted the bug.
22 mutations checked, all caught (one needed a new test, which was added).

Still to do:

- a RouterOS API client (the transport decision D-17 covers SNMP only) and a RouterOS 7 fixture before any v7 reader;
- the BGP4-MIB file added to the repository, then a bounded `bgp_peers` profile;
- router, BGP and ARP history storage, and the router pages;
- **fixtures for RouterOS and one L3 vendor**, then the hardware sign-off. Not verified against a device.

### MikroTik MIB added (2026-10-01)

The operator supplied MikroTik's MIKROTIK-MIB (revision 2026-07-07), now in `MIKROTIK_MIBS/MIKROTIK-MIB.mib`. It
imports only standard modules already in the repository, so its OIDs are checked offline like BDCOM's.

The `mikrotik_routeros` profile (`app/registry/mikrotik_oids.py`) has 32 read-only objects:

- serial, firmware, board name and license (GETs);
- voltage, temperatures, power, CPU frequency and power-supply state (GETs);
- DHCP lease count (GET);
- simple queues: name, bytes and drops, capped at 2048 rows;
- neighbours: IP, MAC, identity, platform and interface, capped at 256;
- SFP optics: name, receive loss, temperature, supply voltage, bias, transmit and receive power, capped at 128.

How it is checked and seeded:

- Each OID, its read-only ACCESS and its GET-or-walk shape are re-derived from the MIB.
- Each reading's divisor comes from the object's own textual convention: tenths for Voltage, Temperature and Power;
  thousandths for SFP voltage and power.
- The MIB's two writable actions (`mtxrSystemReboot`, `mtxrUSBPowerReset`) are tested to be absent.
- Seeded as a **draft**: the MIB says what each object means, not what a given RouterOS release answers. Activation
  waits for a fixture and the hardware sign-off.

What the MIB changes for the metric-source table above:

- RouterOS resources can now come from SNMP for health, though not CPU load: MIKROTIK-MIB has none, and that is in
  HOST-RESOURCES-MIB, which is not in the repository.
- DHCP gives only a lease count over SNMP. The lease list stays on the API.
- Simple queues are on SNMP.

Tests: 76 (`tests/test_mikrotik_profile.py`). 10 mutations checked, all caught. Not verified against a device.
