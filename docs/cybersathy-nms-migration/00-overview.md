# CyberSathy-NMS Migration Overview

## Goal
Move the production NMS from PHP/Slim on RoadRunner with MySQL to a staged architecture that is safer to operate and easier to extend, without disrupting the subscribers the network carries and without a big-bang rewrite.

## Non-goals
- No change to how devices are reached before the new polling engine has fixtures, bounds and an operator sign-off.
- No redesign of the vendor knowledge itself. YAML models, OID profiles and MIBs are imported, not rewritten.
- The separate ISP-solution application is out of scope for this migration (decision D-10, D-14).
- No code, image, package or name from the legacy project goes into the new stack. Where legacy had a component, we build our own ([own-components.md](own-components.md)).
- No new features on the legacy API. New capability is native-only.

## Current state (measured)

| Area | Legacy | Notes |
|---|---|---|
| API | PHP/Slim on RoadRunner | 109 core routes, 209 component routes ([api-compatibility.md](api-compatibility.md)) |
| Database | MySQL | 42 tables (10 core, 32 from component migrations), migrations 002–070 |
| Components | 30 | [parity-inventory.md](parity-inventory.md) |
| Pollers | 17 | switch, router, OLT, optical, FDB, LLDP, sensors |
| Permissions | 99 route-regex entries, 92 distinct keys | [permission-mapping.md](permission-mapping.md) |
| Config | 119 `.env` parameters | [env-parameter-mapping.md](env-parameter-mapping.md) |
| Runtime | 24 containers | RoadRunner, MySQL, Memcached, Redis, Swoole WebSocket, Go pinger, Go external-apps proxy, trap listener, ttyd, Oxidized, and the observability stack |
| Frontend | Vue 3 SPA in `frontend/` | Ad hoc `DataService`, Vuex |
| New foundation | `backend/` + `docker-compose.cybersathy.yml` | Partial, with known defects ([STATUS.md](STATUS.md)) |

## Target Architecture

The canonical description. Every plan refers here instead of repeating it.

**Nginx** (TLS, routing, rate limits, and the `auth_request` gate for proxied apps) → **Vue 3 + Vite + TypeScript + Pinia** frontend and **FastAPI** API → **PostgreSQL + TimescaleDB** (source of truth) and **Redis Streams** (jobs and events) → our own **Python workers** (SNMP poller, pinger, trap receiver, event, notification, report, config-backup) plus our own **scheduler** and **console-gateway**. Observability stays **Prometheus, Grafana, Loki and Alloy**, with Alertmanager as the metric-alarm rule engine (D-24), all from official upstream images. **RoadRunner and every legacy component are absent from the final runtime.** The PHP backend is a migration source and reference only.

## Design principles

1. **Never widen access to make migration easier.** Backend scope enforcement is mandatory; the frontend only filters for convenience.
2. **Safety before speed.** Every poll has bounds. Action OIDs never appear in polling profiles. See [safety-and-verification-policy.md](safety-and-verification-policy.md).
3. **Verify offline.** Configuration, code, fixtures and contract tests. A device is contacted only by an operator during a planned sign-off.
4. **One thing at a time.** Each phase changes one of: the runtime, the database, or the polling. Never two at once (K-18).
5. **Every step reversible.** Each plan has a rollback; cutover has a rehearsed one.
6. **Parity is a checklist, not a feeling.** [parity-inventory.md](parity-inventory.md) must be fully dispositioned before cutover.
7. **Credentials are radioactive.** Encrypted at rest, write-only, never logged, never in fixtures or docs.
8. **Registry over code.** Vendors, models, OIDs and traps are data with validation, not scattered constants.
9. **Build our own.** No legacy code, images or names in the new stack. Official upstream images are fine. See [own-components.md](own-components.md).

## Global rules

- ISP/Admin users see all devices, OLT events, switch/router events, logs, topology and alarms.
- ISP/NOC default focus is switches, routers, links, interfaces, monitoring and events.
- Resellers have full control **only inside assigned scope**.
- BDCOM switch and BDCOM OLT profiles stay separate.
- No full SNMP table walks run during device add.
- Writable and action OIDs are never part of polling profiles.
- MIBs are documentation and validation, not a runtime polling source.

## Phases

See the [README](README.md#phases) for the ordered list and plan numbers. Summary:

| Phase | Purpose | Plans |
|---|---|---|
| 0 | Prepare: hygiene, decisions, inventories | 35, decisions, parity, policy |
| 1 | Foundation: runtime, schema, auth, roles, minimal CI | 1, 2, 3, 36, 4, 33 (start) |
| 2 | Registries: vendors, OIDs, MIBs, models | 5, 6, 7, 8 |
| 3 | Inventory and frontend plumbing | 9, 10, 24, 25 |
| 4 | Engine: polling, workers, scheduler, operations baseline | 11, 12, 37, 40 |
| 5 | Device families | 13–19 |
| 6 | Events and realtime | 20, 21, 22, 29 |
| 7 | Product surfaces, actions, integrations, compatibility | 23, 26, 27, 28, 30, 31, 38, 39, 41 |
| 8 | Migrate and cut over | 32, 33 (complete), 34, 40 (final) |

## Implementation steps
- Build the new foundation beside the existing app (already partly done).
- Fix foundation defects before building on them ([STATUS.md](STATUS.md)).
- Move data model and APIs in small phases.
- Import vendor, OID and MIB knowledge into structured registries.
- Migrate polling only after safe discovery, bounds and access control exist.
- Cut over only after every parity row is dispositioned and Gate A passes ([cutover-runbook.md](cutover-runbook.md)).

## Database/API impact
PostgreSQL becomes the source of truth at cutover. The API stays under `/api/v1`. A compatibility layer keeps legacy paths, IDs and envelope working ([Plan 41](41-legacy-api-compatibility.md)).

## Frontend impact
The Vue 3 frontend moves gradually to typed API clients and Pinia, route by route, while both APIs coexist.

## Security / access rules
Access is enforced in the backend through a mandatory scoped-repository layer (Plan 4). Credential handling, 2FA, IP-strict access and service tokens are specified in Plan 36.

## Acceptance checks
- Every plan in the README has status, dependencies, Definition of Done and Rollback.
- No document repeats the architecture sentence; all link here.
- [parity-inventory.md](parity-inventory.md), [permission-mapping.md](permission-mapping.md), [api-compatibility.md](api-compatibility.md), [env-parameter-mapping.md](env-parameter-mapping.md) and [data-model.md](data-model.md) are consistent with the plans.
- RoadRunner is clearly deprecated and absent from the final runtime.

## Documents in this folder

| Document | Purpose |
|---|---|
| [README](README.md) | Index, phases, dependency map |
| [STATUS](STATUS.md) | What exists, defects, sign-offs |
| [decisions](decisions.md) | Decision log |
| [safety-and-verification-policy](safety-and-verification-policy.md) | Rules for touching devices and proving behavior |
| [own-components](own-components.md) | Every component we build ourselves, naming conventions, third-party pieces we keep |
| [parity-inventory](parity-inventory.md) | Legacy → new checklist |
| [permission-mapping](permission-mapping.md) | Legacy permission keys → new codes |
| [api-compatibility](api-compatibility.md) | Legacy routes → new routes |
| [env-parameter-mapping](env-parameter-mapping.md) | `.env` → new homes |
| [data-model](data-model.md) | Target schema catalogue, retention |
| [risk-register](risk-register.md) | Ranked risks and mitigations |
| [cutover-runbook](cutover-runbook.md) | Step-by-step cutover and rollback |

## Risks
- Combining backend rewrite, database migration and polling rewrite at once would be too risky (K-18).
- See [risk-register.md](risk-register.md) for the full list.
