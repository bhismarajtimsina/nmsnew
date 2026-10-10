# Plan 27: Topology and Links

> **Phase:** 7 · **Depends on:** 10 · **Status:** Partial (links, link state, scoped graph; paths, tree views and LLDP to do)

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

### Still to do

- Paths: segments, path state and group state as in legacy (the latency rule, kept), on top of link state, and the
  four path alarm rules.
- The upward tree and direction tree views.
- LLDP neighbours through a bounded profile (BDCOM's LLDP MIB is in the repository) and external neighbour names.
- Link utilisation (legacy computes it every two minutes for the `high_link_utilization` alarm).
- The topology frontend.
