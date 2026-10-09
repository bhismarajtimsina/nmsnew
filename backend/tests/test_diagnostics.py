"""Plan 38's on-demand diagnostics: ICMP ping of an in-scope device, queued for a worker. A scripted prober only; no
packet is sent and no device is contacted."""
import json

import pytest

from app.core.config import settings
from app.diagnostics import ping as diag
from app.workers import handlers
from app.workers.queue import Message, Permanent, Skipped
from tests.helpers import bearer, make_device, make_group, make_user
from tests.polling_helpers import ctx  # noqa: F401

P = "/api/v1/diagnostics"


class ScriptedProber:
    def __init__(self, result=None, error=None):
        self.result = result or diag.PingResult(sent=4, received=3, rtts_ms=[1.0, 2.0, 3.0])
        self.error = error
        self.calls: list[tuple[str, int]] = []

    async def ping(self, address, *, count):
        self.calls.append((address, count))
        if self.error:
            raise self.error
        return self.result


@pytest.fixture
def enabled(monkeypatch):
    monkeypatch.setattr(settings, "diagnostics_enabled", True)


async def _login(app_client, db, name="op", role="ISP Admin", group=None):
    user = await make_user(db, name, role)
    # Resellers do not hold the ping permission by default; grant it here, so scope is what these tests exercise.
    await db.execute("insert into role_permissions (role_id, permission_id) select role_id, (select id from permissions "
                     "where code = 'diagnostics.icmp_ping') from users where id = $1::uuid on conflict do nothing", user)
    if group is not None:
        await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user, headers


async def requested(app_client, db, ctx, *, ip="10.60.0.1", name="op"):
    group = await make_group(db, f"G-{name}")
    device = await make_device(db, f"sw-{name}", group, ip=ip)
    user, headers = await _login(app_client, db, name)
    response = await app_client.post(f"{P}/ping", headers=headers, json={"device_id": device, "count": 3})
    assert response.status_code == 202, response.text
    return user, headers, device, response.json()


def message(request_id):
    return Message("1-0", diag.DIAG_STREAM, {"request_id": request_id})


# --- the API ---

async def test_switched_off_by_default_and_nothing_is_queued(app_client, db, ctx):
    assert settings.diagnostics_enabled is False
    device = await make_device(db, "sw")
    _, headers = await _login(app_client, db)
    response = await app_client.post(f"{P}/ping", headers=headers, json={"device_id": device})
    assert response.status_code == 503 and "nothing was sent" in response.json()["detail"]
    assert await ctx.redis.xlen(diag.DIAG_STREAM) == 0


async def test_a_ping_is_queued_signed_audited_and_readable_by_its_requester(app_client, db, ctx, enabled):
    user, headers, device, body = await requested(app_client, db, ctx)
    assert body == {"request_id": body["request_id"], "device_id": device, "status": "queued", "result": None, "error": None}
    entries = await ctx.redis.xrange(diag.DIAG_STREAM)
    assert len(entries) == 1 and entries[0][1]["request_id"] == body["request_id"] and entries[0][1]["sig"]
    assert "10.60.0.1" not in json.dumps(entries[0][1])  # the job names a request, never an address
    record = await diag.load(ctx.redis, body["request_id"])
    assert (record.user_id, record.device_id, record.count) == (user, device, 3)
    assert 0 < await ctx.redis.ttl(diag.record_key(body["request_id"])) <= diag.RESULT_TTL_SECONDS
    audit = await db.fetchrow("select actor_user_id, resource_id, metadata from audit_logs where action = 'diagnostics.ping.requested'")
    assert str(audit["actor_user_id"]) == user and str(audit["resource_id"]) == device
    assert (await app_client.get(f"{P}/{body['request_id']}", headers=headers)).json()["status"] == "queued"


async def test_results_are_for_the_requester_only(app_client, db, ctx, enabled):
    _, _, _, body = await requested(app_client, db, ctx)
    _, other = await _login(app_client, db, "other")
    assert (await app_client.get(f"{P}/{body['request_id']}", headers=other)).status_code == 404
    assert (await app_client.get(f"{P}/00000000-0000-0000-0000-000000000000", headers=other)).status_code == 404


async def test_a_device_outside_the_callers_scope_is_not_found_and_nothing_is_queued(app_client, db, ctx, enabled):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    hidden = await make_device(db, "hidden", theirs)
    _, headers = await _login(app_client, db, "res", "Reseller Operator", group=mine)
    response = await app_client.post(f"{P}/ping", headers=headers, json={"device_id": hidden})
    assert response.status_code in (403, 404)
    assert await ctx.redis.xlen(diag.DIAG_STREAM) == 0


async def test_the_permission_is_required(app_client, db, ctx, enabled):
    device = await make_device(db, "sw")
    user, headers = await _login(app_client, db)
    await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                     "and permission_id = (select id from permissions where code = 'diagnostics.icmp_ping')", user)
    assert (await app_client.post(f"{P}/ping", headers=headers, json={"device_id": device})).status_code == 403


@pytest.mark.parametrize("extra", [{"count": 6}, {"count": 0}, {"address": "8.8.8.8"}, {"ip": "10.0.0.1"}])
async def test_the_request_cannot_ask_for_more_packets_or_name_an_address(app_client, db, ctx, enabled, extra):
    device = await make_device(db, "sw")
    _, headers = await _login(app_client, db)
    assert (await app_client.post(f"{P}/ping", headers=headers, json={"device_id": device, **extra})).status_code == 422


async def test_each_user_is_rate_limited(app_client, db, ctx, enabled):
    group = await make_group(db, "G")
    devices = [await make_device(db, f"sw-{i}", group) for i in range(diag.PER_USER_PER_MINUTE + 1)]
    _, headers = await _login(app_client, db)
    codes = [(await app_client.post(f"{P}/ping", headers=headers, json={"device_id": d})).status_code for d in devices]
    assert codes == [202] * diag.PER_USER_PER_MINUTE + [429]


async def test_each_device_is_rate_limited_across_users(app_client, db, ctx, enabled):
    device = await make_device(db, "sw")
    codes = []
    for i in range(diag.PER_DEVICE_PER_MINUTE + 1):
        _, headers = await _login(app_client, db, f"op{i}")
        codes.append((await app_client.post(f"{P}/ping", headers=headers, json={"device_id": device})).status_code)
    assert codes == [202] * diag.PER_DEVICE_PER_MINUTE + [429]


# --- the worker ---

async def test_the_worker_pings_the_address_from_the_database_and_announces_the_result(app_client, db, ctx, enabled):
    from app.realtime.bus import DIAGNOSTICS_FINISHED, REALTIME_CHANNEL

    _, headers, device, body = await requested(app_client, db, ctx)
    await db.execute("update devices set management_ip = '10.60.9.9' where id = $1::uuid", device)  # changed since the request
    pubsub = ctx.redis.pubsub()
    await pubsub.subscribe(REALTIME_CHANNEL)
    await pubsub.get_message(timeout=1.0)
    prober = ScriptedProber()
    await handlers.handle_diagnostic(ctx, message(body["request_id"]), prober)
    notice = await pubsub.get_message(ignore_subscribe_messages=True, timeout=2.0)
    await pubsub.aclose()
    assert prober.calls == [("10.60.9.9", 3)]
    out = (await app_client.get(f"{P}/{body['request_id']}", headers=headers)).json()
    assert out["status"] == "succeeded"
    assert out["result"] == {"sent": 4, "received": 3, "loss_percent": 25.0, "min_ms": 1.0, "avg_ms": 2.0, "max_ms": 3.0}
    assert json.loads(notice["data"]) == {"name": DIAGNOSTICS_FINISHED, "data": {"request_id": body["request_id"]}}
    audit = json.loads(await db.fetchval("select metadata from audit_logs where action = 'diagnostics.ping'"))
    assert audit["status"] == "succeeded" and audit["result"]["received"] == 3


async def test_a_redelivered_job_never_probes_twice(app_client, db, ctx, enabled):
    _, _, _, body = await requested(app_client, db, ctx)
    prober = ScriptedProber()
    await handlers.handle_diagnostic(ctx, message(body["request_id"]), prober)
    with pytest.raises(Skipped):
        await handlers.handle_diagnostic(ctx, message(body["request_id"]), prober)
    assert len(prober.calls) == 1


async def test_an_expired_or_malformed_job_does_nothing(ctx):
    prober = ScriptedProber()
    with pytest.raises(Skipped):
        await handlers.handle_diagnostic(ctx, message("00000000-0000-0000-0000-000000000009"), prober)
    with pytest.raises(Permanent):
        await handlers.handle_diagnostic(ctx, message("not-a-uuid"), prober)
    assert prober.calls == []


@pytest.mark.parametrize("change, reason", [
    ("deactivate", "no longer active"),
    ("revoke", "no longer has permission"),
    ("unscope", "no longer inside"),
])
async def test_the_requester_is_checked_again_when_the_worker_runs(app_client, db, ctx, enabled, change, reason):
    group = await make_group(db, "G")
    device = await make_device(db, "sw", group)
    user, headers = await _login(app_client, db, "res", "Reseller Operator", group=group)
    body = (await app_client.post(f"{P}/ping", headers=headers, json={"device_id": device})).json()
    if change == "deactivate":
        await db.execute("update users set is_active = false where id = $1::uuid", user)
    elif change == "revoke":
        await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                         "and permission_id = (select id from permissions where code = 'diagnostics.icmp_ping')", user)
    else:
        await db.execute("delete from user_device_group_scopes where user_id = $1::uuid", user)
    prober = ScriptedProber()
    await handlers.handle_diagnostic(ctx, message(body["request_id"]), prober)
    record = await diag.load(ctx.redis, body["request_id"])
    assert prober.calls == [] and record.status == "refused" and reason in record.error


async def test_a_disabled_prober_refuses_and_a_socket_error_fails(app_client, db, ctx, enabled):
    _, _, _, first = await requested(app_client, db, ctx, ip="10.60.0.1", name="a")
    _, _, _, second = await requested(app_client, db, ctx, ip="10.60.0.2", name="b")
    await handlers.handle_diagnostic(ctx, message(first["request_id"]), diag.DisabledProber())
    await handlers.handle_diagnostic(ctx, message(second["request_id"]), ScriptedProber(error=PermissionError("no raw socket")))
    a, b = await diag.load(ctx.redis, first["request_id"]), await diag.load(ctx.redis, second["request_id"])
    assert (a.status, b.status) == ("refused", "failed")
    assert "switched off" in a.error and "PermissionError" in b.error and a.result is None and b.result is None


async def test_the_worker_consumes_diagnostics_only_with_a_real_prober(ctx):
    from dataclasses import replace

    from app.polling.transport import DisabledTransport
    from app.workers.runner import Worker

    ctx = replace(ctx, transport=DisabledTransport())
    off = Worker(ctx, {"diagnostics"})
    assert off.streams() == [] and off.status().startswith("idle")
    on = Worker(ctx, {"diagnostics", "poller"}, prober=ScriptedProber())
    assert [stream for stream, _ in on.streams()] == [diag.DIAG_STREAM]  # the SNMP transport is still disabled here
    assert on.status() == "running"
    # worker_heartbeats.status is varchar(60); a longer idle message made every heartbeat fail.
    assert all(len(w.status()) <= 60 for w in (off, on, Worker(ctx, {"discovery"})))


# --- the probe itself ---

def test_a_ping_summary_reports_loss_and_round_trip_times():
    assert diag.PingResult(sent=4, received=0).summary() == {
        "sent": 4, "received": 0, "loss_percent": 100.0, "min_ms": None, "avg_ms": None, "max_ms": None}
    assert diag.PingResult(sent=3, received=3, rtts_ms=[1.234, 2.0, 0.5]).summary()["min_ms"] == 0.5
    assert diag.PingResult(sent=0, received=0).loss_percent == 100.0


async def test_the_real_prober_caps_its_packets_and_timeouts(monkeypatch):
    import icmplib

    seen = {}

    class Host:
        packets_sent, packets_received, rtts = 5, 5, [1.0] * 5

    async def fake_ping(address, **kwargs):
        seen.update(kwargs, address=address)
        return Host()

    monkeypatch.setattr(icmplib, "async_ping", fake_ping)
    result = await diag.IcmpProber(privileged=False).ping("192.0.2.1", count=50)
    assert seen == {"address": "192.0.2.1", "count": diag.MAX_COUNT, "interval": diag.PACKET_INTERVAL_SECONDS,
                    "timeout": diag.PACKET_TIMEOUT_SECONDS, "privileged": False}
    assert result.received == 5
    assert isinstance(diag.build_prober(False, False), diag.DisabledProber)
    assert isinstance(diag.build_prober(True, False), diag.IcmpProber)


def test_only_ping_holders_may_subscribe_to_diagnostic_notices():
    from app.realtime.permissions import CHANNEL_RULES, is_subscribe_allowed

    assert is_subscribe_allowed(CHANNEL_RULES, channel="diagnostics.finished", user_permissions=frozenset({"diagnostics.icmp_ping"}),
                                scope_all=False)
    assert not is_subscribe_allowed(CHANNEL_RULES, channel="diagnostics.finished", user_permissions=frozenset({"devices.view"}),
                                    scope_all=True)
