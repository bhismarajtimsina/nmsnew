"""The Alertmanager ingestion logic, ported from the real production processor. Synthetic payloads only, in the
standard, documented Alertmanager webhook shape (a public, versioned format, not vendor- or device-specific)."""
import time

from app.alerting.ingest import process_alert, process_webhook, resolve_device, IngestSummary, RESOLVED_GRACE_PERIOD_SECONDS
from tests.helpers import make_device


def alert(status="firing", alertname="test_alert", fingerprint="fp-1", severity="warning", description="d", **labels):
    return {"status": status, "labels": {"alertname": alertname, "severity": severity, **labels},
           "annotations": {"description": description}, "fingerprint": fingerprint}


async def test_a_firing_alert_creates_an_open_event(db):
    summary = IngestSummary()
    await process_alert(db, alert(), worker_uptime_seconds=9999, summary=summary)
    assert summary.created == 1
    row = await db.fetchrow("select name, dedup_key, severity, resolved_at from events")
    assert (row["name"], row["dedup_key"], row["severity"], row["resolved_at"]) == ("test_alert", "fp-1", "warning", None)


async def test_a_repeated_firing_notification_for_the_same_open_alert_is_a_duplicate_not_a_new_event(db):
    summary = IngestSummary()
    for _ in range(3):
        await process_alert(db, alert(), worker_uptime_seconds=9999, summary=summary)
    assert (summary.created, summary.duplicate) == (1, 2)
    assert await db.fetchval("select count(*) from events") == 1


async def test_resolving_closes_the_matching_open_event_by_name_and_fingerprint(db):
    summary = IngestSummary()
    await process_alert(db, alert(), worker_uptime_seconds=9999, summary=summary)
    await process_alert(db, alert(status="resolved"), worker_uptime_seconds=9999, summary=summary)
    assert summary.resolved == 1
    assert await db.fetchval("select resolved_at is not null from events") is True


async def test_resolving_during_the_startup_grace_period_is_skipped_not_applied(db):
    summary = IngestSummary()
    await process_alert(db, alert(), worker_uptime_seconds=9999, summary=summary)
    await process_alert(db, alert(status="resolved"), worker_uptime_seconds=RESOLVED_GRACE_PERIOD_SECONDS - 1, summary=summary)
    assert summary.resolved_skipped_grace == 1 and summary.resolved == 0
    assert await db.fetchval("select resolved_at from events") is None


async def test_resolving_something_never_opened_is_reported_not_silently_ignored(db):
    summary = IngestSummary()
    await process_alert(db, alert(status="resolved"), worker_uptime_seconds=9999, summary=summary)
    assert summary.resolved_not_found == 1


async def test_severity_defaults_to_critical_and_is_normalised_to_a_known_value(db):
    a = alert(fingerprint="fp-a"); del a["labels"]["severity"]
    summary = IngestSummary()
    await process_alert(db, a, worker_uptime_seconds=9999, summary=summary)
    assert await db.fetchval("select severity from events where dedup_key = 'fp-a'") == "critical"

    weird = alert(fingerprint="fp-b", severity="CATASTROPHIC")
    await process_alert(db, weird, worker_uptime_seconds=9999, summary=summary)
    assert await db.fetchval("select severity from events where dedup_key = 'fp-b'") == "critical"  # falls back, never crashes


async def test_alertname_is_removed_from_the_stored_labels_since_it_duplicates_the_name_column(db):
    summary = IngestSummary()
    await process_alert(db, alert(ip="10.1.1.1"), worker_uptime_seconds=9999, summary=summary)
    labels = await db.fetchval("select labels from events")
    import json
    assert "alertname" not in json.loads(labels) and json.loads(labels)["ip"] == "10.1.1.1"


async def test_device_resolution_prefers_dev_id_then_host_then_ip(db):
    by_id = await make_device(db, "by-id", ip="10.2.0.1")
    by_host = await make_device(db, "by-host", ip="10.2.0.2")
    by_ip = await make_device(db, "by-ip", ip="10.2.0.3")
    assert await resolve_device(db, {"dev_id": by_id, "host": "10.2.0.2", "ip": "10.2.0.3"}) == by_id
    assert await resolve_device(db, {"host": "10.2.0.2", "ip": "10.2.0.3"}) == by_host
    assert await resolve_device(db, {"ip": "10.2.0.3"}) == by_ip
    assert await resolve_device(db, {"ip": "10.9.9.9"}) is None
    assert await resolve_device(db, {}) is None


async def test_an_alert_missing_alertname_or_fingerprint_is_reported_and_never_crashes(db):
    summary = IngestSummary()
    await process_alert(db, {"status": "firing", "labels": {}, "annotations": {}}, worker_uptime_seconds=9999, summary=summary)
    await process_alert(db, {"status": "firing", "labels": {"alertname": "x"}, "annotations": {}}, worker_uptime_seconds=9999, summary=summary)
    assert len(summary.errors) == 2 and summary.created == 0
    assert await db.fetchval("select count(*) from events") == 0


async def test_a_whole_webhook_payload_processes_every_alert_and_tallies_the_summary(db):
    payload = {"version": "4", "status": "firing", "alerts": [alert(fingerprint="fp-x"), alert(fingerprint="fp-x"), alert(fingerprint="fp-y")]}
    summary = await process_webhook(db, payload, worker_started_at=time.monotonic() - 9999)
    assert (summary.created, summary.duplicate) == (2, 1)
    assert await db.fetchval("select count(*) from events") == 2
