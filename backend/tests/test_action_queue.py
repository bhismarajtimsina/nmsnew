"""Plan 38's queued flow: the API only queues a confirmed action, a worker runs it, and every rule is checked again
when it runs. Fake drivers and the fake transport only; no device is contacted."""
import json

import pytest

from app.actions import drivers as drivers_module
from app.actions import safety
from app.actions.catalogue import ACTIONS
from app.core.config import settings
from app.core.crypto import EncryptionService
from app.polling.fake import FakeTransport
from app.polling.transport import DisabledTransport
from app.workers.queue import Permanent
from app.workers.runner import ACTION_STREAM
from tests.helpers import bearer, make_device, make_group, make_interface, make_user
from tests.polling_helpers import ctx  # noqa: F401

A = "/api/v1/actions"
ENC = EncryptionService.from_settings()


@pytest.fixture
def ran(monkeypatch):
    calls = []

    async def fake(conn, user, target, params):
        calls.append(target)
        return safety.Outcome(before={"state": "online"}, after={"state": "rebooting"})

    monkeypatch.setattr(settings, "device_actions_enabled", True)
    monkeypatch.setattr(drivers_module, "DRIVERS", {key: (lambda transport, enc: fake) for key in ACTIONS})
    return calls


async def queued(app_client, db, role="ISP Admin", targets=2, name="op", stop_on_failure=None):
    group = await make_group(db, f"G-{name}")
    olt = await make_device(db, f"olt-{name}", group, ip=f"10.9.{len(name)}.1")
    user = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    if role.startswith("Reseller"):
        await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    body = [{"device_id": olt, "onu": f"HWTC{i:04d}"} for i in range(targets)]
    extra = {} if stop_on_failure is None else {"stop_on_failure": stop_on_failure}
    token = (await app_client.post(f"{A}/onu.reboot/prepare", headers=headers, json={"targets": body, "params": {}, **extra})).json()["token"]
    response = await app_client.post(f"{A}/onu.reboot/execute", headers=headers,
                                     json={"token": token, "targets": body, "params": {}, **extra})
    assert response.status_code == 200, response.text
    return user, headers, response.json()


async def statuses(db, confirmation_id):
    return sorted(r["status"] for r in await db.fetch("select status from action_results where confirmation_id = $1::uuid", confirmation_id))


async def test_execute_only_queues_and_publishes_a_signed_job(app_client, db, ctx, ran):
    _, _, body = await queued(app_client, db)
    assert [r["status"] for r in body["results"]] == ["queued", "queued"] and ran == []
    entries = await ctx.redis.xrange(ACTION_STREAM)
    assert len(entries) == 1
    fields = entries[0][1]
    assert fields["confirmation_id"] == body["confirmation_id"] and fields["sig"]
    assert await db.fetchval("select count(*) from audit_logs where action = 'action.queued'") == 1


async def test_a_redelivered_job_never_runs_a_target_twice(app_client, db, ran):
    _, _, body = await queued(app_client, db)
    first = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    second = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert first == {"succeeded": 2, "failed": 0, "refused": 0, "skipped": 0}
    assert second == {"succeeded": 0, "failed": 0, "refused": 0, "skipped": 0}
    assert len(ran) == 2


@pytest.mark.parametrize("change, reason", [
    ("deactivate", "no longer active"),
    ("revoke", "no longer has permission"),
    ("switch_off", "switched off"),
    ("unscope", "no longer inside"),
])
async def test_everything_is_checked_again_when_the_worker_runs(app_client, db, ran, monkeypatch, change, reason):
    user, _, body = await queued(app_client, db, role="Reseller Operator", targets=1)
    if change == "deactivate":
        await db.execute("update users set is_active = false where id = $1::uuid", user)
    elif change == "revoke":
        await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                         "and permission_id = (select id from permissions where code = 'olts.onu.reboot')", user)
    elif change == "switch_off":
        monkeypatch.setattr(settings, "device_actions_enabled", False)
    else:
        await db.execute("delete from user_device_group_scopes where user_id = $1::uuid", user)
    counts = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert counts["refused"] == 1 and ran == []
    error = await db.fetchval("select error from action_results where confirmation_id = $1::uuid", body["confirmation_id"])
    assert reason in error


async def test_when_the_job_cannot_be_published_nothing_runs_and_the_token_is_spent(app_client, db, ran, monkeypatch):
    monkeypatch.setattr(settings, "job_signing_key", None)
    group = await make_group(db, "G")
    olt = await make_device(db, "olt", group)
    await make_user(db, "op", "ISP Admin")
    headers = await bearer(app_client, "op")
    app_client.cookies.clear()
    body = [{"device_id": olt, "onu": "HWTC0001"}]
    token = (await app_client.post(f"{A}/onu.reboot/prepare", headers=headers, json={"targets": body, "params": {}})).json()["token"]
    response = await app_client.post(f"{A}/onu.reboot/execute", headers=headers, json={"token": token, "targets": body, "params": {}})
    assert response.status_code == 503 and "nothing was sent" in response.json()["detail"]
    row = await db.fetchrow("select status, error from action_results")
    assert (row["status"], row["error"]) == ("failed", "the action could not be queued")
    again = await app_client.post(f"{A}/onu.reboot/execute", headers=headers, json={"token": token, "targets": body, "params": {}})
    assert again.status_code == 409 and ran == []


async def test_results_are_visible_to_the_requester_only(app_client, db, ran):
    _, headers, body = await queued(app_client, db)
    cid = body["confirmation_id"]
    assert (await app_client.get(f"{A}/results/{cid}", headers=headers)).json()["results"][0]["status"] == "queued"
    await safety.run_confirmation(db, FakeTransport(), ENC, cid)
    assert {r["status"] for r in (await app_client.get(f"{A}/results/{cid}", headers=headers)).json()["results"]} == {"succeeded"}
    await make_user(db, "other", "ISP Admin")
    other = await bearer(app_client, "other")
    app_client.cookies.clear()
    assert (await app_client.get(f"{A}/results/{cid}", headers=other)).status_code == 404
    assert (await app_client.get(f"{A}/results/00000000-0000-0000-0000-000000000000", headers=headers)).status_code == 404


async def test_the_database_requires_a_finish_time_exactly_when_a_result_is_final(app_client, db, ran):
    _, _, body = await queued(app_client, db, targets=1)
    with pytest.raises(Exception):
        await db.execute("update action_results set status = 'succeeded' where confirmation_id = $1::uuid", body["confirmation_id"])
    with pytest.raises(Exception):
        await db.execute("update action_results set finished_at = now() where confirmation_id = $1::uuid", body["confirmation_id"])


async def test_the_worker_handler_refuses_a_bad_message_and_a_disabled_transport_consumes_nothing(ctx):
    from app.workers import handlers
    from app.workers.queue import Message
    from app.workers.runner import Worker

    with pytest.raises(Permanent):
        await handlers.handle_action(ctx, Message("1-0", ACTION_STREAM, {"confirmation_id": "not-a-uuid"}))
    ctx.transport = DisabledTransport()
    assert Worker(ctx, {"actions"}).streams() == []


# --- protecting an interface ---

async def test_an_operator_protects_an_interface_and_the_change_is_audited_and_scoped(app_client, db):
    north = await make_group(db, "North")
    device = await make_device(db, "sw1", north)
    port = await make_interface(db, device, "Gi0/1")
    await make_user(db, "admin", "ISP Admin")
    headers = await bearer(app_client, "admin")
    app_client.cookies.clear()
    response = await app_client.put(f"/api/v1/interfaces/{port}/protection", headers=headers, json={"protected": True})
    assert response.status_code == 200 and response.json() == {"protected": True}
    assert (await app_client.get(f"/api/v1/interfaces/{port}", headers=headers)).json()["protected"] is True
    audit = await db.fetchrow("select before, after from audit_logs where action = 'interface.protection_set'")
    assert json.loads(audit["before"]) == {"protected": False} and json.loads(audit["after"]) == {"protected": True}
    res = await make_user(db, "res", "Reseller Admin")
    res_headers = await bearer(app_client, "res")
    app_client.cookies.clear()
    assert (await app_client.put(f"/api/v1/interfaces/{port}/protection", headers=res_headers, json={"protected": False})).status_code in (403, 404)
    await make_user(db, "viewer", "Reseller Viewer")
    viewer = await bearer(app_client, "viewer")
    app_client.cookies.clear()
    assert (await app_client.put(f"/api/v1/interfaces/{port}/protection", headers=viewer, json={"protected": False})).status_code == 403
    assert await db.fetchval("select protected from interfaces where id = $1::uuid", port) is True
    assert res


async def test_only_an_actions_worker_consumes_action_jobs(ctx):
    from app.workers.runner import Worker

    assert ACTION_STREAM in [name for name, _ in Worker(ctx, {"actions"}).streams()]
    for kinds in ({"poller"}, {"discovery"}, {"poller", "discovery"}):
        assert ACTION_STREAM not in [name for name, _ in Worker(ctx, kinds).streams()]


@pytest.fixture
def fails_first(monkeypatch):
    """A driver that fails on the first ONU (HWTC0000) and succeeds on the rest."""
    calls = []

    async def fake(conn, user, target, params):
        calls.append(target["onu"])
        if target["onu"] == "HWTC0000":
            raise safety.ActionFailed("the ONU did not answer")
        return safety.Outcome(before={}, after={})

    monkeypatch.setattr(settings, "device_actions_enabled", True)
    monkeypatch.setattr(drivers_module, "DRIVERS", {key: (lambda transport, enc: fake) for key in ACTIONS})
    return calls


async def test_stop_on_failure_skips_every_target_after_the_first_failure(app_client, db, fails_first):
    _, headers, body = await queued(app_client, db, targets=3, stop_on_failure=True)
    counts = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert counts == {"succeeded": 0, "failed": 1, "refused": 0, "skipped": 2}
    assert fails_first == ["HWTC0000"]
    results = (await app_client.get(f"{A}/results/{body['confirmation_id']}", headers=headers)).json()["results"]
    assert [r["status"] for r in results] == ["failed", "skipped", "skipped"]
    assert results[1]["error"] == safety.SKIPPED_REASON
    audit = await db.fetchrow("select metadata from audit_logs where action = 'action.skipped'")
    assert json.loads(audit["metadata"])["count"] == 2
    assert await db.fetchval("select count(*) from action_results where finished_at is null") == 0


async def test_without_stop_on_failure_every_target_still_runs(app_client, db, fails_first):
    _, _, body = await queued(app_client, db, targets=3)
    counts = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert counts == {"succeeded": 2, "failed": 1, "refused": 0, "skipped": 0}
    assert fails_first == ["HWTC0000", "HWTC0001", "HWTC0002"]
    assert await db.fetchval("select count(*) from audit_logs where action = 'action.skipped'") == 0


async def test_a_refused_target_also_stops_the_request(app_client, db, ran):
    user, _, body = await queued(app_client, db, role="Reseller Operator", targets=3, stop_on_failure=True)
    await db.execute("delete from user_device_group_scopes where user_id = $1::uuid", user)
    counts = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert counts == {"succeeded": 0, "failed": 0, "refused": 1, "skipped": 2} and ran == []


async def test_stop_on_failure_with_every_target_succeeding_skips_nothing(app_client, db, ran):
    _, _, body = await queued(app_client, db, targets=2, stop_on_failure=True)
    counts = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert counts == {"succeeded": 2, "failed": 0, "refused": 0, "skipped": 0} and len(ran) == 2


async def test_stop_on_failure_is_part_of_what_was_confirmed(app_client, db, ran):
    group = await make_group(db, "G")
    olt = await make_device(db, "olt", group)
    await make_user(db, "op", "ISP Admin")
    headers = await bearer(app_client, "op")
    app_client.cookies.clear()
    body = [{"device_id": olt, "onu": "HWTC0001"}, {"device_id": olt, "onu": "HWTC0002"}]
    prepared = (await app_client.post(f"{A}/onu.reboot/prepare", headers=headers,
                                      json={"targets": body, "params": {}, "stop_on_failure": True})).json()
    assert prepared["summary"]["stop_on_failure"] is True
    response = await app_client.post(f"{A}/onu.reboot/execute", headers=headers,
                                     json={"token": prepared["token"], "targets": body, "params": {}})
    assert response.status_code == 409 and ran == []
    assert await db.fetchval("select count(*) from action_results") == 0
    stored = await db.fetchval("select metadata from audit_logs where action = 'action.prepare'")
    assert json.loads(stored)["stop_on_failure"] is True


async def test_the_database_accepts_skipped_only_with_a_reason(app_client, db, ran):
    _, _, body = await queued(app_client, db, targets=1)
    with pytest.raises(Exception):
        await db.execute("update action_results set status = 'skipped', finished_at = now() where confirmation_id = $1::uuid",
                         body["confirmation_id"])
    with pytest.raises(Exception):
        await db.execute("update action_results set status = 'bogus', error = 'x', finished_at = now() where confirmation_id = $1::uuid",
                         body["confirmation_id"])


async def test_the_worker_announces_a_finished_action_with_only_its_id(app_client, db, ctx, ran):
    from app.realtime.bus import ACTIONS_FINISHED, REALTIME_CHANNEL
    from app.workers import handlers
    from app.workers.queue import Message

    _, _, body = await queued(app_client, db, targets=1)
    pubsub = ctx.redis.pubsub()
    await pubsub.subscribe(REALTIME_CHANNEL)
    await pubsub.get_message(timeout=1.0)  # the subscribe confirmation
    await handlers.handle_action(ctx, Message("1-0", ACTION_STREAM, {"confirmation_id": body["confirmation_id"]}))
    message = await pubsub.get_message(ignore_subscribe_messages=True, timeout=2.0)
    await pubsub.aclose()
    assert json.loads(message["data"]) == {"name": ACTIONS_FINISHED, "data": {"confirmation_id": body["confirmation_id"]}}
    assert await statuses(db, body["confirmation_id"]) == ["succeeded"]


async def test_a_redis_failure_does_not_fail_the_notice(caplog):
    from redis.exceptions import ConnectionError as RedisConnectionError

    from app.realtime.bus import notify_action_finished

    class Down:
        async def publish(self, *args):
            raise RedisConnectionError("down")

    await notify_action_finished(Down(), "00000000-0000-0000-0000-000000000001")
    assert "could not publish actions.finished" in caplog.text


def test_only_action_holders_may_subscribe_to_action_notices():
    from app.realtime.permissions import CHANNEL_RULES, is_subscribe_allowed

    gate = frozenset({"dangerous_actions.execute"})
    assert is_subscribe_allowed(CHANNEL_RULES, channel="actions.finished", user_permissions=gate, scope_all=False)
    assert not is_subscribe_allowed(CHANNEL_RULES, channel="actions.finished", user_permissions=frozenset({"devices.view"}),
                                    scope_all=True)


async def test_stopping_never_touches_a_target_another_worker_is_running(app_client, db, fails_first):
    _, _, body = await queued(app_client, db, targets=3, stop_on_failure=True)
    await db.execute("update action_results set status = 'running' where confirmation_id = $1::uuid and target->>'onu' = 'HWTC0002'",
                     body["confirmation_id"])
    counts = await safety.run_confirmation(db, FakeTransport(), ENC, body["confirmation_id"])
    assert counts == {"succeeded": 0, "failed": 1, "refused": 0, "skipped": 1}
    assert await statuses(db, body["confirmation_id"]) == ["failed", "running", "skipped"]
