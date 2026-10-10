# Plan 27: Topology and Links

> **Phase:** 7 · **Depends on:** 10 · **Status:** Partial (links, link state, scoped graph, paths with state, groups and metrics; tree views, LLDP neighbours and link suggestions, link utilisation, links page; graph and tree pages to do)

## Goal
Build topology and link management.

## Current Source / Reference
Current links and topology components exist under `components/Links`, `components/Paths`, and frontend topology views.

## Target Design
Topology uses device/interface/link data and safe LLDP discovery.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Add device graph API.
- Add link list API.
- Add tree view API.
- Use LLDP where safe.
- Update link state from interface status.
- Reach parity with `Links`, `Paths` (segments, states) and `AutoTopology` including link external names.
- Use LLDP only through bounded profiles.
- Compute link state from interface status and path state from segments; store thresholds in settings (`PATHS_DEGRADED_LATENCY_MS`).

## Database/API Impact
Create normalized link records and optional LLDP discovery history.

## Frontend Impact
Graph/tree pages use scoped topology APIs.

## Security / Access Rules
Reseller topology is limited to assigned scope and impacted context.

## Acceptance Checks
- Topology graph loads.
- Link status updates.
- Interface down affects link state.
- Reseller topology is scoped.
- Interface down changes link state within one cycle.
- Path state degrades at the configured latency.
- Reseller topology contains only assigned scope and impacted context.

## Risks
- LLDP gaps can produce incomplete topology.

## Definition of Done
Link and path parity verified; scope tests pass.

## Rollback
Legacy links stay authoritative until cutover.

## Implementation notes (2026-10-10)

### Legacy, read from `origin/main`

- **`c_links`:** source device and interface, destination device and interface, how the link was learned (`manual`,
  `fdb`, `lldp`) and free `params`.
- **`c_links_external_names`:** display names for LLDP neighbours that are not devices in the inventory, keyed
  `ext:<local device id>:<chassis MAC>`.
- **Paths** (`c_paths`, `c_path_segments`, `c_path_states`): ordered link hops between two endpoint devices, grouped by
  `group_key` for redundancy, with state computed every minute.
- **Path state:** computed from the pinger's latency at each hop's two devices. Down if either is unreachable, unknown
  if either is unmeasured, degraded above `PATHS_DEGRADED_LATENCY_MS` (150). Interface status is not used.
- **Permissions:** `links_view` / `links_edit` and `paths_view` / `paths_edit`. Legacy's `links_view` route list also
  allows `PUT /view/list`.

### Built: links

**Table `links`** (migration `0028`), plus `link_external_names` for the data migration. Two rules legacy does not
enforce, both refused by the database:
- a link never joins an interface to itself;
- the same pair of ends is stored once, whichever way round it was entered (a unique index on the ordered ends).

Deleting a device deletes its links; deleting an interface only unbinds it from the link.

**Link state** (`app/topology/state.py`), from what the NMS already measures. For each end:
- a device the pinger reports down is down, because an unreachable device's interface status is stale;
- an end with an interface takes that interface's status: administratively down counts as down, `lowerLayerDown` and
  `notPresent` as down, and `dormant`, `testing` and `unknown` as unknown;
- an end without an interface takes the device's ping status.

A link is down if either end is down, unknown if either is unmeasured, and up only when both ends are up. State is
computed on every read, so an interface going down changes its link on the next poll, with no separate job.

**API** (`app/api/links.py`).
- `GET /links` (optionally `?device_id=`), `GET /links/{id}` and `GET /topology/graph` need `links.view`.
- `POST /links`, `PUT /links/{id}` and `DELETE /links/{id}` need `links.edit`, and are audited before and after.
- Each interface must belong to its device. A duplicate pair of ends is a 409; a self-link is a 422.

**Scope** (`app/repositories/links.py`).
- A caller sees a link when at least one end is a device they may see.
- An end outside their scope is masked: no device, name, address, interface or end state. The link's own state is
  still given, so a reseller sees that their uplink is down but not what is at the other end.
- In the graph, each hidden end is its own placeholder node per link, so the graph does not even show that two links
  reach the same outside device.
- Changing or deleting a link needs both ends in scope; for anyone else it reads as 404.
- The repository is now covered by the scope guard, and so is last round's console repository. Its transcript query
  had relied on its caller to check scope; it now applies `DEVICE_VISIBLE` itself.

Tests: 21 in `tests/test_links.py`. Mutation checks: 15, then 14. The first run found two gaps:
- the device filter was untested, so a test was added;
- a second, redundant masking of hidden names in the graph, which was removed.

### Built: transport paths (2026-10-10)

**Tables** (migration `0029`): `paths`, `path_segments` (ordered, up to 64, a link at most once per path) and
`path_states`, with legacy's columns.
- The database refuses a path whose two endpoints are the same device.
- Deleting an endpoint device deletes the path; deleting a link deletes the segment.

**State** (`app/topology/paths.py`), ported from legacy's `StateCalculator`, with each hop also taking its link's state.
- **Down:** the link is down, or either device is unreachable.
- **Unknown:** the link is unknown, or either device is unmeasured.
- **Degraded:** either device answers slower than `PATHS_DEGRADED_LATENCY_MS` (150; 0 turns it off).
- **Path:** its worst hop, in the order down, unknown, degraded, up. A path with no hops is unknown.
- **Groups:** an outage when no path is usable (up or degraded); unprotected when there is more than one path and not
  all are usable; protected when all are; up for a single path.
- So a hop whose interface is down is down even while both ends still answer pings; legacy would call it up.

**Route check, new.** Setting a path's segments requires one unbroken route from endpoint A to endpoint B, walking each
link either way round. Legacy never checked this. A broken route is refused and leaves the old segments in place.

**Scheduler.** The `paths_state` job runs every minute, on by default, as legacy's `paths:calc-state` does.
- It stores each enabled path's state.
- `last_change` moves only when the state changes.
- A disabled path loses its stored state, so it exports nothing and raises nothing.

**Metrics.** The API's `/metrics` adds legacy's gauges, with the same names, labels and values, so the four path alarm
rules already ported from legacy work unchanged:
- `path_state` (1, 0.5, 0, -1) and `path_segments_down` for each path;
- `path_group_protected`, `path_group_up_count` and `path_group_total` for each redundant group.

A state older than `PATHS_STATE_METRIC_TTL_SEC` (300) is not exported, as with legacy's metric TTL, so a stopped job
silences these alarms instead of freezing them. A database failure there is logged and the process metrics are still
served.

**API** (`app/api/paths.py`).
- **Viewing** (`paths.view`): `GET /paths` (stored state), `GET /paths/{id}` (state computed now, with each hop shown
  through the link masking), and `GET /paths/groups`.
- **Changing** (`paths.edit`): `POST`, `PUT` and `DELETE /paths`, and `PUT /paths/{id}/segments`. All audited.
- **Scope:** a path is visible when both endpoints are. A hop through a device outside the caller's scope shows its
  state, but not the device. Changing segments needs every link fully in scope.
- **Groups:** built from the paths the caller can see. Disabled paths neither protect a group nor count against it.

Tests: 21 in `tests/test_paths.py`. Mutation checks: 21, all caught. The first run left one survivor, a disabled path
still counted in its group, which is now tested.

### Built: tree views (2026-10-10)

**Port.** `app/topology/tree.py` ports legacy's `buildDownArray`, `getUplinkTree` and `getCoreByDevice`, keeping its
convention that a link's source is upstream.
- `GET /topology/tree/{device_id}?direction=down`: the tree below a device.
- `?direction=up`: the tree below the highest device above it that the caller may see (legacy's "core"). A device with
  nothing above it is its own top (`is_top`).
- `GET /topology/upward/{device_id}`: the chain of upstream devices, nearest first.
- All of them need `links.view` and the device in scope. The depth limit is legacy's 15, and a cut tree says
  `truncated`.

**Legacy bugs not carried over.**
- **Rings.** Legacy has no visited set, so a ring repeats its devices down to depth 15. Here each device is expanded
  once; meeting it again gives a leaf marked `repeat`, and an upward walk round a loop stops with `loop`.
- **Unpinged devices.** Legacy's tree query inner-joins the pinger table, so a device never pinged disappears with
  everything below it. Here it stays.
- **Several parents.** With more than one upstream link, legacy follows whichever chain the database returns longest.
  Here the first in a fixed order is followed (upstream name, then link id), and `multiple_parents` says so.

**Scope.** A device outside the caller's scope is shown as a placeholder, never expanded, and never walked through, so
a reseller's "up" tree starts at their own highest device (`is_top: false`). Two guards do this: placeholders unique
per link, and an explicit visibility check. Either alone suffices; both are kept on purpose.

Tests: 7 in `tests/test_topology_tree.py`. Mutation checks: 14. Three are equivalent, by design: they remove one of the
two guards, or merge the placeholders the other guard already makes harmless.

### Built: LLDP neighbours (2026-10-10)

**Where BDCOM keeps LLDP.** BDCOM switches carry LLDP under their own enterprise tree (`nms 127`,
`1.3.6.1.4.1.3320.127`), which is the IEEE LLDP-MIB re-hosted. A legacy comment records that the standard
`1.0.8802.1.1.2` tree returned "No Such Object" on these switches.

**Profile.** The draft `bdcom_switch_basic` profile already read four neighbour columns. It now also reads:
- the neighbour's chassis-id and port-id subtypes;
- the local port table: port id, its subtype, and description.

Each new column is checked against `NMS-LLDP-MIB.MIB` by the existing profile test: same OID, read-only, bounded to 256
rows. The profile is still a draft, so nothing polls it. An installation that already seeded the draft keeps its old
entries until an operator rebuilds it.

**Decoding** (`app/topology/lldp.py`).
- **Row indexes:** the MIB's own (`<TimeMark>.<LocalPortNum>.<RemIndex>`, and `<LocPortNum>`). A reading whose OID
  is under another tree, or whose index does not fit, is dropped.
- **Ids:** decoded by subtype, using the MIB's enumerations (a test compares them with the MIB):
  - a MAC is six octets, even when its octets happen to be printable;
  - a network address is an IANA family octet followed by the address;
  - anything else is shown as text when printable, as hex when not.
- **Local port:** matched to an interface by exact name, or by a single case-insensitive match. Never a partial match,
  so `Gi0/1` cannot land on `Gi0/10`.

**Storage.** A polling sink, now wired into the worker, replaces a device's `lldp_neighbours` rows (migration `0030`)
on each poll that returns LLDP data. Polls of other profiles leave them alone.

**Reading.** `GET /topology/lldp/{device_id}` needs `links.view` and the device in scope. Each neighbour is matched to
one of our devices only among those the caller may see:
- **By MAC:** devices have no MAC of their own here, so the chassis MAC is looked for on interfaces. It counts only
  when it is a real MAC-type id and belongs to exactly one device.
- **By name:** otherwise by a unique, case-insensitive system name.
- **Resellers:** see what the switch reported, but never that a neighbour is a device outside their scope.

Tests: 11 in `tests/test_lldp.py`, and 5 more cases in the profile test. Mutation checks: 15, all caught. The first run
left six survivors, each closed with a new case:
- valid UTF-8 that is not printable;
- the right column name under the wrong tree;
- an exact name winning over a case-insensitive one;
- a MAC made of printable octets;
- a non-LLDP poll after a stored LLDP poll;
- a look-alike id beside a real MAC with the same digits.

Not verified against a device; the profile is a draft and the build's SNMP transport is the disabled one (D-17).

### Built: LLDP link suggestions and external neighbour names (2026-10-10)

**Suggestions** (`GET /topology/lldp/suggestions`, `links.view`, optional `device_id`). Each stored neighbour row that
matched one of the caller's devices (as above) becomes a candidate link:
- **Local end:** the reporting device and the interface named by the local port table.
- **Remote end:** the matched device and its interface. The interface is found by the reported port id when that id
  is a name (`interfaceName`, `interfaceAlias`, `local`), else by the port description. A MAC-type port id is never
  matched as a name.
- **One per adjacency.** The two sides of one cable become one suggestion, flagged `seen_from_both_sides`. A report
  that knows less (same pair of devices, no interface the other lacks) folds into the one that knows more. Reports
  that contradict each other, or that each know a different end, are all kept for the operator to judge.
- **Existing links are left out.** This covers a device-level link between the pair, or a link with matching
  interfaces. Every link the caller can see counts, hidden ends included.
- **Conflict:** a suggestion whose interface end is already linked to another device is still listed, but marked
  `conflict`.

**Accepting** (`POST /topology/lldp/suggestions/accept`, `links.edit`). The body must be the same link (either
direction) as a suggestion computed now from the stored data, or it is refused with 409. The endpoint therefore
cannot create arbitrary links marked as LLDP. The link is created with `source = lldp` through the same rules as
`POST /links`, so both ends must be in scope and interfaces must belong to their device. It is audited with
`source: lldp`.

**External names** (`PUT /topology/lldp/external-names/{ext_id}`, `links.edit`). A neighbour that matches no device
but reported a MAC chassis id gets the id `ext:<device>:<mac>`. A name can be given to it (legacy's
`c_links_external_names`), which needs the reporting device in scope; a null name removes it. The neighbour list
returns `external_id` and `external_name`. Changes are audited as `link.external_name_set`.

Tests: 15 in `tests/test_lldp_suggestions.py`. Mutation checks: 16, all caught. One more mutant (`>` to `>=` in the
fold-in rule) is equivalent: two different reports with the same number of known interfaces cannot cover each other.
Not verified against a device.

### Built: link utilisation (2026-10-10)

**Counters.** The polling sink now also stores a counter sample from each `interface_basic` poll:
- one row per interface the inventory knows, matched by ifIndex (migration `0031`, a hypertable kept 7 days);
- the in and out octet counters as read;
- ifSpeed where it is usable. A speed of 0, or the Gauge32 maximum (a port faster than ifSpeed can hold, whose real
  speed is in ifHighSpeed, not yet read), counts as unknown.

Rows for unknown ifIndexes are skipped; creating interfaces from a poll is still Plan 10's gap.

**Rates and utilisation** (`app/topology/utilization.py`):
- **Period:** legacy's `LINKS_UTILIZATION_CALCULATE_PERIOD`, with the same name and values (`10m` to `6h`, default
  `15m`). Anything else refuses to start.
- **Resets:** a counter that drops was reset (restart, cleared counters, or a 32-bit wrap) and counts from zero, as
  Prometheus' `rate()` does. A reset can only under-read, never raise a false alarm.
- **Percent:** bits per second over the interface speed. When the samples carry no speed, the inventory's speed is
  used. Legacy mixed binary megabits with a speed where 1G meant 1024, reading about 7% low on gigabit ports and 5%
  high on 100M ones.
- **Busiest direction:** a link's utilisation is its busiest direction at any end it measures. Legacy took the source
  end and fell back to the destination; both ends carry the same traffic, so the highest is the same figure when both
  are right, and the useful one when one end's counters are stale.

**Reading** (`GET /topology/links/utilization`, `links.view`, optional `device_id`). This gives the figure for each
visible link with something measured. An end outside the caller's scope is not measured: their own end carries the
same traffic, and nothing is read from a device they may not see.

**Metrics.** `/metrics` exports legacy's `link_utilization_prc`, `link_utilization_mbps`, `link_utilization_speed`
and `link_status`, with legacy's labels (minus the bind key, which has no equivalent here). The ported
`high_link_utilization` (> 85% for 15 minutes) and `link_down` rules now have their series. Two differences from
legacy:
- An idle link exports 0 rather than nothing.
- `link_status` follows the link's state, and an unknown state exports nothing. Legacy wrote 0 for any link without an
  Up interface at both ends, so a device-to-device link raised `link_down` forever.

Tests: 18 in `tests/test_link_utilization.py`. Mutation checks: 26, all caught. Three more were equivalent, and the
redundant code they pointed at was removed:
- a two-point check the elapsed-time check already covered;
- a speed condition that could never fail;
- an empty-input guard the insert did not need.

Not verified against a device: no counter has been read from real hardware, and the build's SNMP transport is the
disabled one (D-17).

### Still to do

- The topology frontend: the links page is on the new API (see Plan 25). The graph and tree pages still call
  legacy's endpoints.
