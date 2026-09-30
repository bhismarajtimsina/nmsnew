# Cutover Runbook

The step-by-step procedure behind [Plan 34](34-cutover.md). It is written to be read in the middle of a maintenance window, so each step has an owner role, a check, and a rollback.

Roles: **Operator** (runs commands, has device authority), **Reviewer** (second pair of eyes, signs gates), **Owner** (approves go/no-go).

Nothing in this runbook is performed by an automated or AI-assisted session against production. Steps that touch devices are Operator-run under [the safety policy](safety-and-verification-policy.md).

## Preconditions (all must be true)

- [ ] Every row in [parity-inventory.md](parity-inventory.md) is `Migrated`, `Kept as-is` or `Dropped` with a reason
- [ ] Permission import diff report reviewed and signed for every role ([permission-mapping.md](permission-mapping.md))
- [ ] Contract tests pass against the shim ([api-compatibility.md](api-compatibility.md))
- [ ] Backup and **restore** of PostgreSQL rehearsed (Plan 40)
- [ ] Rollback rehearsed on a production copy, with timings recorded
- [ ] Hardware sign-offs recorded in [STATUS.md](STATUS.md) for every vendor family in use
- [ ] Legacy `.env` secrets rotated; new secrets in place (Plan 35)
- [ ] Alerts and on-call in place for the window; communication sent to NOC and resellers
- [ ] Owner has signed the go/no-go table below with real numbers

## Phase A: Staging import and shadow (days to weeks)

1. Deploy the full new stack to staging. All new pollers **disabled**.
2. Import a production data copy with credentials re-encrypted under the staging key (Plan 32).
3. Run data-quality checks. Any failure blocks the phase.
4. Enable new pollers for the lowest-risk device group. Set `polling_owner = cybersathy` for those devices only; the legacy poller stops polling them.
5. Compare for at least 7 days: device count, interface count, OLT/PON/ONU counts, poll success rate, open alarm count, event types.
6. Widen group by group. After each widening, wait one full alarm cycle and re-compare.

**Gate A (go/no-go table).** All must hold for 7 consecutive days on the widest group:

| Metric | Threshold |
|---|---|
| Device, interface, OLT, ONU counts | exact match after known exclusions list |
| Poll success rate | not lower than legacy by more than 0.5 point |
| Open alarms | every difference explained and recorded |
| Optical values | within tolerance of legacy for matching ONUs |
| p95 API latency on dashboards | at or below legacy |
| SNMP request rate per device | at or below the agreed budget |
| Scope leak tests | 0 failures |
| Login, 2FA, IP-strict, service token | all pass |

## Phase B: Cutover window

Plan for a read-only window. Target duration is set from the rehearsal timing plus 100%.

| Step | Owner | Action | Check | Rollback |
|---|---|---|---|---|
| 1 | Operator | Announce freeze; put legacy UI in read-only mode | Writes rejected | Lift freeze |
| 2 | Operator | Stop legacy schedule executor and pollers | No new poll rows | Start them |
| 3 | Operator | Final delta import from MySQL | Counts match the frozen snapshot | Discard import |
| 4 | Operator | Set `polling_owner = cybersathy` for all remaining devices; enable new pollers | First poll cycle completes with success rate at Gate A level | Set owner back to `legacy` |
| 5 | Operator | Repoint Nginx `/api/v1` and `/ws` to `cybersathy-api` | Login works; dashboards load; realtime updates | Repoint Nginx back |
| 6 | Operator | Point the Nginx auth gate, Prometheus scrape and Grafana datasource at the new stack. Move the trap destination address and port to the new `trap-receiver`. Start `pinger` and `config-backup` per device group | Proxied apps open with `cs_session`; traps arrive; pinger reports transitions | Switch back; move the trap address back |
| 7 | Reviewer | Run smoke checklist below | All pass | Roll back if any critical item fails |
| 8 | Owner | Declare cutover complete; open writes | | |

**Smoke checklist**

- [ ] Admin login with password and 2FA; reseller login
- [ ] Reseller sees only assigned scope on devices, ONUs, events, map, topology
- [ ] Device list, device detail, interface list
- [ ] OLT detail, PON list, ONU list, unregistered ONUs
- [ ] Event appears when a test event is injected; notification delivered
- [ ] WebSocket connects and receives a scoped event
- [ ] A **non-dangerous** action works and is audited (for example set port description on a lab device)
- [ ] A dangerous action is refused without a confirmation token
- [ ] Grafana, Prometheus, Alertmanager and the config-backup viewer open through the auth gate, and a request without a session is refused
- [ ] Service-token integration reads devices through the compatibility shim

## Phase C: Stability window (at least 14 days)

- Legacy MySQL and PHP stack stay running but idle and **read-only**, ready for rollback
- Nightly reverse export (PostgreSQL → legacy-shaped dump) for the first 72 hours, so a rollback after new writes loses at most one day of data
- Daily comparison: alarm counts and poll success against pre-cutover baseline
- Legacy route usage report from `legacy_route_usage` (Plan 41); deprecate routes with 30 days of zero use

## Rollback

Trigger examples: Gate A metric regresses, login broken for a group of users, scope leak found, poll success below threshold for two cycles, data corruption.

1. Announce rollback and freeze new-system writes.
2. Repoint Nginx and monitoring back to legacy; move the trap destination back; stop the new `pinger`, `trap-receiver` and `config-backup` workers.
3. Set `polling_owner = legacy` for all devices; stop new pollers.
4. If the new system accepted writes: apply the reverse export to MySQL for the writes made since cutover; Reviewer verifies row counts.
5. Lift the legacy read-only mode.
6. Record cause and timings in [STATUS.md](STATUS.md); do not retry until the cause has a fix and a new rehearsal.

After the stability window, archive the legacy stack (images, MySQL dump, encryption key) for at least 12 months, then remove. RoadRunner is no longer part of any running service.
