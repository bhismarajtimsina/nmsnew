# Plan 31: Monitoring Integration

> **Phase:** 7 · **Depends on:** 12 · **Status:** Not started

## Goal
Integrate CyberSathy-NMS with observability stack.

## Current Source / Reference
Current Docker stack includes Prometheus, Grafana, Loki, Alloy, SNMP Exporter, and Blackbox Exporter.

## Target Design
Keep observability tools and export CyberSathy-NMS API/worker metrics.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Export API latency, worker job count, poller duration, poller failures, device timeouts, and SNMP walk row counts.
- Configure Prometheus scrape.
- Add Grafana dashboards.
- Send structured logs to Loki through Alloy.
- Set a label cardinality budget: no per-ONU, per-MAC or per-URL labels.
- Route-template labels only (also closes F-05).
- Decide which goflow2 (NetFlow) dashboards carry over.
- Restrict metrics endpoints to the monitoring network.
- Use official upstream images for Alertmanager, node-exporter and cAdvisor instead of the `meklis/*` forks, with pinned versions (D-27).

## Database/API Impact
No business schema impact.

## Frontend Impact
Monitoring dashboard links to Grafana and local health summaries.

## Security / Access Rules
Monitoring views require ISP/system permissions unless scoped summaries are exposed.

## Acceptance Checks
- Prometheus scrapes API/workers.
- Grafana dashboard shows service health.
- Loki receives logs.
- No metric exceeds its cardinality budget in a load test.
- Grafana dashboards show API, worker and poller health.

## Risks
- High-cardinality labels can harm Prometheus performance.

## Definition of Done
Scrape targets, dashboards and log shipping in place.

## Rollback
Legacy scrape targets stay until cutover.
