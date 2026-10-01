"""The port of legacy `wca sync-active-alerts`: closing events Alertmanager forgot to resolve. The reconciliation is
driven by a fake Alertmanager; the real HTTP client is exercised against a stand-in server on 127.0.0.1 that this
test runs itself. No device and no real Alertmanager are involved."""
import json
import threading
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

from app.alerting.ingest import IngestSummary, process_alert
from app.alerting.sync import HttpAlertmanager, sync_active_alerts
from app.scheduling.jobs import InvalidJob, JobContext, run_sync_active_alerts, validate_params
from tests.helpers import make_device, make_user
from tests.test_notification_pipeline import make_contact

LONG_AGO = datetime.now(timezone.utc) - timedelta(days=1)


class FakeAlertmanager:
    def __init__(self, active=(), started_at=LONG_AGO):
        self.active = set(active)
        self._started_at = started_at
        self.asked_for_alerts = False

    async def active_fingerprints(self):
        self.asked_for_alerts = True
        return set(self.active)

    async def started_at(self):
        return self._started_at


async def open_event(db, name="interface_is_down", fingerprint="fp-1", **labels):
    summary = IngestSummary()
    alert = {"status": "firing", "labels": {"alertname": name, "severity": "warning", **labels},
             "annotations": {}, "fingerprint": fingerprint}
    await process_alert(db, alert, worker_uptime_seconds=9999, summary=summary)
    return str(await db.fetchval("select id from events where dedup_key = $1 and resolved_at is null", fingerprint))


async def is_open(db, event_id) -> bool:
    return await db.fetchval("select resolved_at is null from events where id = $1::uuid", event_id)


async def test_an_event_alertmanager_no_longer_reports_is_resolved(db):
    orphan = await open_event(db, fingerprint="fp-gone")
    still_firing = await open_event(db, fingerprint="fp-live")
    summary = await sync_active_alerts(db, FakeAlertmanager(active={"fp-live"}))
    assert (summary.active, summary.resolved) == (1, 1) and summary.resolved_ids == [orphan]
    assert not await is_open(db, orphan) and await is_open(db, still_firing)


async def test_only_events_named_after_an_alarm_rule_are_candidates(db):
    """Legacy restricts this to AlertmanagerRulesStorage's names; an event from any other source is never touched."""
    other = await open_event(db, name="not_an_alertmanager_rule", fingerprint="fp-x")
    summary = await sync_active_alerts(db, FakeAlertmanager())
    assert summary.candidates == 0 and await is_open(db, other)


async def test_an_event_without_a_fingerprint_is_never_resolved(db):
    event_id = await db.fetchval(
        "insert into events (name, dedup_key, severity) values ('interface_is_down', '', 'warning') returning id")
    await sync_active_alerts(db, FakeAlertmanager())
    assert await is_open(db, str(event_id))


async def test_dry_run_reports_and_changes_nothing(db):
    orphan = await open_event(db, fingerprint="fp-gone")
    summary = await sync_active_alerts(db, FakeAlertmanager(), dry_run=True)
    assert summary.resolved == 0 and summary.resolved_ids == [orphan]
    assert await is_open(db, orphan)
    assert await db.fetchval("select count(*) from notifications where type = 'resolved'") == 0


async def test_nothing_is_resolved_while_alertmanager_has_only_just_restarted(db):
    """Not in legacy, which would close every open alarm here: Alertmanager's active list is near empty until its
    rules are evaluated again after a restart."""
    orphan = await open_event(db, fingerprint="fp-gone")
    alertmanager = FakeAlertmanager(started_at=datetime.now(timezone.utc) - timedelta(seconds=30))
    summary = await sync_active_alerts(db, alertmanager)
    assert summary.skipped_reason and summary.resolved == 0
    assert not alertmanager.asked_for_alerts
    assert await is_open(db, orphan)


async def test_a_resolved_event_notifies_contacts_like_any_other_resolve(db):
    uid = await make_user(db, "admin", "ISP Admin")
    contact = await make_contact(db, uid)
    device = await make_device(db, "sw1")
    orphan = await open_event(db, fingerprint="fp-gone", dev_id=device)
    await sync_active_alerts(db, FakeAlertmanager())
    rows = await db.fetch("select type from notifications where event_id = $1::uuid and contact_id = $2::uuid "
                          "order by created_at", orphan, contact)
    assert [r["type"] for r in rows] == ["alert", "resolved"]


async def test_a_flapped_event_closed_here_holds_its_resolved_notification_too(db):
    uid = await make_user(db, "admin", "ISP Admin")
    await make_contact(db, uid)
    device = await make_device(db, "sw1")
    orphan = await open_event(db, fingerprint="fp-gone", dev_id=device)
    await db.execute("update events set flap_count = 3 where id = $1::uuid", orphan)
    await sync_active_alerts(db, FakeAlertmanager(), flap_window_seconds=900)
    send_at = await db.fetchval("select send_at from notifications where type = 'resolved'")
    assert send_at >= datetime.now(timezone.utc) + timedelta(seconds=890)


# --- the real HTTP client, against a stand-in Alertmanager on loopback ---

class _StandIn(BaseHTTPRequestHandler):
    alerts: list = []
    status: dict = {}
    paths: list = []

    def do_GET(self):
        type(self).paths.append(self.path)
        body = self.alerts if self.path.startswith("/am/api/v2/alerts") else self.status
        data = json.dumps(body).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass


@pytest.fixture
def standin():
    _StandIn.paths = []
    server = HTTPServer(("127.0.0.1", 0), _StandIn)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    yield f"http://127.0.0.1:{server.server_address[1]}/am/"
    server.shutdown()
    server.server_close()


async def test_the_http_client_asks_for_silenced_and_inhibited_alerts_and_skips_unprocessed_ones(standin):
    _StandIn.alerts = [
        {"fingerprint": "a", "status": {"state": "active"}},
        {"fingerprint": "s", "status": {"state": "suppressed"}},  # silenced or inhibited: still firing
        {"fingerprint": "u", "status": {"state": "unprocessed"}},
        {"fingerprint": "", "status": {"state": "active"}},
        "not an alert",
    ]
    fingerprints = await HttpAlertmanager(standin).active_fingerprints()
    assert fingerprints == {"a", "s"}
    # Literal, not the module constant: asking without silenced/inhibited would close events for alerts that are only
    # silenced, which is exactly what legacy's query avoids.
    assert _StandIn.paths == ["/am/api/v2/alerts?active=true&silenced=true&inhibited=true&unprocessed=false"]


async def test_the_http_client_reads_alertmanagers_start_time(standin):
    _StandIn.status = {"uptime": "2026-10-01T08:00:00.123Z"}
    started = await HttpAlertmanager(standin).started_at()
    assert started == datetime(2026, 10, 1, 8, 0, 0, 123000, tzinfo=timezone.utc)


async def test_the_http_client_refuses_a_response_that_is_not_a_list(standin):
    _StandIn.alerts = {"error": "nope"}
    with pytest.raises(RuntimeError):
        await HttpAlertmanager(standin).active_fingerprints()


# --- the scheduled job ---

def test_the_job_type_validates_its_params():
    assert validate_params("sync_active_alerts", {}).dry_run is False
    with pytest.raises(InvalidJob):
        validate_params("sync_active_alerts", {"timeout_seconds": 0})
    with pytest.raises(InvalidJob):
        validate_params("sync_active_alerts", {"url": "http://elsewhere"})  # the target is configuration, not a param


async def test_the_job_fails_clearly_when_alertmanager_is_not_configured(monkeypatch):
    from app.core.config import settings

    monkeypatch.setattr(settings, "alertmanager_url", "")
    ctx = JobContext(pool=None, queue=None, slot="x", job_key="sync_active_alerts", now_ms=0)
    with pytest.raises(ValueError, match="ALERTMANAGER_URL"):
        await run_sync_active_alerts(ctx, validate_params("sync_active_alerts", {}))
