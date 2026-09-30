# Safety and Verification Policy

This policy applies to every plan in this folder. It exists because the NMS monitors production OLTs and switches that carry real subscribers, and because the migration rewrites exactly the code that talks to them.

## 1. No live device access during development, review or CI

The repository rule in `CLAUDE.md` is absolute and this migration inherits it:

- No SNMP client of any kind (`snmpwalk`, `snmpget`, `snmpbulkwalk`, `wca test:snmpwalk`).
- No `wca switcher-core:call <device-ip> ...`.
- No call to an API endpoint that reaches a device instead of the database (see the list in [api-compatibility.md](api-compatibility.md#endpoints-that-reach-a-device)).
- No telnet, ssh or console session to a device.

It applies to people, scripts, CI runners and AI-assisted sessions equally. "Just one read to check" is not an exception.

## 2. What to use instead

| Need | Offline method |
|---|---|
| Does a model declare the OIDs its modules read? | `docker exec wca php /www/tools/bdcom-oid-coverage.php` (legacy container; Plan 33 ports this to Python) |
| Is a declared OID the one the MIB assigns? | `python3 tools/bdcom-mib-oid-map.py <out.json> [mib-dir]` |
| Are trap OIDs real NOTIFICATION-TYPEs? | `python3 tools/bdcom-trap-audit.py` |
| Do models, OIDs and traps resolve? | `yaml_parse_file()` plus `ModelCollector`, `OidCollector`, `TrapCollector` |
| Does a parser handle a device's response? | **Fixture tests**: recorded SNMP/console output stored in the repo, replayed into the parser |
| Does the new system match the old one? | Compare database rows and log files under `var/logs/` (both are safe to read) |
| Do API shapes match? | **Contract tests**: recorded legacy responses vs the shim |
| Does the polling engine respect bounds? | Unit tests against a fake SNMP transport that counts requested rows and refuses unbounded walks |

Reading the database (`docker exec wca-db mysql ...`) and log files is allowed because neither reaches a device.

## 3. Fixtures

- Fixtures are captured **by an operator with device authority, outside the development process**, using the existing production poller's own logs or a dedicated capture run they schedule. They are then committed under `backend/tests/fixtures/<vendor>/<model>/`.
- Each fixture records: device model, firmware, the exact OIDs or commands requested, the raw response, and the capture date.
- Fixtures contain **no communities, passwords or customer identifiers**. A CI check rejects fixtures that match credential patterns or contain real serial/MAC data outside a documented allow-pattern.
- A parser is not "done" until it has at least one fixture per supported model family, including one truncated or error response.

## 4. Hardware sign-off is a separate, explicit step

Some acceptance checks cannot be confirmed offline, for example "adding a BDCOM switch does not overload it". The rule for these:

1. The plan lists the check under **Hardware sign-off** and it is **not** part of the automated acceptance list.
2. The summary of the work states plainly: *"not verified against a device"*.
3. Only a named operator runs the check, in a maintenance window, on a device chosen for low blast radius, with the per-device rate budget from Plan 11 set to its lowest value and a stop condition agreed in advance.
4. The result (pass, fail, observations) is recorded in `STATUS.md` next to the plan.

An honest "unverified" is the correct outcome. It is never a reason to poll a device to close the gap.

## 5. Staging and observe-only polling

The cutover plan runs the new pollers alongside the old ones. This doubles SNMP load unless controlled, so:

- New pollers start **disabled** and are enabled per device group, non-critical groups first.
- A device is polled by **one system at a time** (`polling_owner` flag: `legacy` or `cybersathy`). Observe-only never means "both poll at full rate".
- A global per-device request budget is shared by both systems during overlap.
- The enable step is performed by an operator, not by a deploy.

## 6. Safety defaults that must hold in code

These are testable and CI enforces them (Plan 33):

- No polling profile contains a writable or action OID.
- Every walk has `max_rows` and `timeout`. A profile without both fails import.
- Full FDB walks, console FDB, and full private-enterprise walks are disabled by default on BDCOM switches.
- Device add queues only the safe-discovery profile (sysDescr, sysObjectID, sysName, sysUpTime).
- Retries are bounded, with backoff and a per-device circuit breaker.
- User-triggered polling and probes are scoped, rate-limited and audited.

## 7. Data safety

- Credentials (SNMP communities, SNMPv3 secrets, device logins, integration keys) are encrypted at rest, write-only through the API, and never appear in logs, audit `before/after` payloads, metrics labels, fixtures or exports.
- Production data exports used for staging have credential columns removed or re-encrypted under the staging key.
- Nothing in this folder's plans loosens a permission to make a migration step easier.
