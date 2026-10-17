"""Plan 13's MAC lookup endpoint: `search.mac`, scoped, rate-limited, audited, queued for a worker that sends one bounded
GET. The worker runs against the fake transport only; no device is contacted."""
import json

import pytest

from app.core.config import settings
from app.diagnostics import mac as lookup
from app.diagnostics.ping import PER_DEVICE_PER_MINUTE, PER_USER_PER_MINUTE
from app.polling.transport import DisabledTransport
from app.services.mac_lookup import DOT1Q_TP_FDB_PORT
from app.workers import handlers
from app.workers.queue import Message, Permanent, Skipped
from tests.helpers import bearer, make_device, make_group, make_user
from tests.polling_helpers import COMMUNITY, ctx, make_pollable  # noqa: F401

P = "/api/v1/search/mac"
MAC = "00:1A:2B:3C:4D:5E"
OCTETS = "0.26.43.60.77.94"


@pytest.fixture
def transport_on(monkeypatch):
    monkeypatch.setattr(settings, "snmp_transport", "test")


async def login(app_client, db, name="op", role="ISP Admin", group=None):
    user = await make_user(db, name, role)
    await db.execute("insert into role_permissions (role_id, permission_id) select role_id, (select id from permissions "
                     "where code = 'search.mac') from users where id = $1::uuid on conflict do nothing", user)
    if group is not None:
        await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, group)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user, headers


async def requested(app_client, db, *, address="10.61.0.1", name="op", vlans=(10, 20), **pollable):
    device = await make_pollable(db, address, **pollable)
    user, headers = await login(app_client, db, name)
    response = await app_client.post(P, headers=headers, json={"device_id": device, "mac": MAC, "vlans": list(vlans)})
    assert response.status_code == 202, response.text
    return user, headers, device, response.json()


def message(request_id):
    return Message("1-0", lookup.MAC_STREAM, {"request_id": request_id})


# --- the API ---

async def test_refused_while_the_transport_is_disabled_and_nothing_is_queued(app_client, db, ctx):
    assert settings.snmp_transport == "disabled"
    device = await make_device(db, "sw")
    _, headers = await login(app_client, db)
    response = await app_client.post(P, headers=headers, json={"device_id": device, "mac": MAC, "vlans": [1]})
    assert response.status_code == 503 and "nothing was sent" in response.json()["detail"]
    assert await ctx.redis.xlen(lookup.MAC_STREAM) == 0


async def test_a_lookup_is_queued_signed_audited_and_readable_by_its_requester(app_client, db, ctx, transport_on):
    user, headers, device, body = await requested(app_client, db, vlans=(20, 10, 20))
    assert body == {"request_id": body["request_id"], "device_id": device, "mac": "00:1a:2b:3c:4d:5e", "vlans": [10, 20],
                    "status": "queued", "result": None, "error": None}
    entries = await ctx.redis.xrange(lookup.MAC_STREAM)
    assert len(entries) == 1 and entries[0][1]["request_id"] == body["request_id"] and entries[0][1]["sig"]
    assert "10.61.0.1" not in json.dumps(entries[0][1])
    assert 0 < await ctx.redis.ttl(lookup.record_key(body["request_id"])) <= lookup.RESULT_TTL_SECONDS
    audit = await db.fetchrow("select actor_user_id, resource_id, metadata from audit_logs where action = 'search.mac.requested'")
    assert str(audit["actor_user_id"]) == user and str(audit["resource_id"]) == device
    assert json.loads(audit["metadata"])["mac"] == "00:1a:2b:3c:4d:5e"
    assert (await app_client.get(f"{P}/{body['request_id']}", headers=headers)).json()["status"] == "queued"
    _, other = await login(app_client, db, "other")
    assert (await app_client.get(f"{P}/{body['request_id']}", headers=other)).status_code == 404


@pytest.mark.parametrize("payload", [
    {"mac": "not-a-mac-at-all", "vlans": [1]},
    {"mac": MAC, "vlans": [0]},
    {"mac": MAC, "vlans": [4095]},
    {"mac": MAC, "vlans": []},
    {"mac": MAC, "vlans": list(range(1, 34))},
    {"mac": MAC, "vlans": [1], "address": "10.0.0.9"},
])
async def test_a_bad_mac_or_vlan_list_is_refused_and_nothing_is_queued(app_client, db, ctx, transport_on, payload):
    device = await make_device(db, "sw")
    _, headers = await login(app_client, db)
    assert (await app_client.post(P, headers=headers, json={"device_id": device, **payload})).status_code == 422
    assert await ctx.redis.xlen(lookup.MAC_STREAM) == 0


async def test_scope_and_permission_are_required(app_client, db, ctx, transport_on):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    hidden = await make_device(db, "hidden", theirs)
    _, reseller = await login(app_client, db, "res", "Reseller Operator", group=mine)
    assert (await app_client.post(P, headers=reseller, json={"device_id": hidden, "mac": MAC, "vlans": [1]})).status_code == 404
    device = await make_device(db, "sw")
    user, headers = await login(app_client, db)
    await db.execute("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
                     "and permission_id = (select id from permissions where code = 'search.mac')", user)
    assert (await app_client.post(P, headers=headers, json={"device_id": device, "mac": MAC, "vlans": [1]})).status_code == 403
    assert await ctx.redis.xlen(lookup.MAC_STREAM) == 0


async def test_rate_limited_per_user_and_per_device_apart_from_pings(app_client, db, ctx, transport_on):
    devices = [await make_device(db, f"sw-{i}") for i in range(PER_USER_PER_MINUTE + 1)]
    user, headers = await login(app_client, db)
    await ctx.redis.set(f"cs:ratelimit:diag:user:{user}", 999)  # a user out of pings may still look MACs up
    codes = [(await app_client.post(P, headers=headers, json={"device_id": d, "mac": MAC, "vlans": [1]})).status_code for d in devices]
    assert codes == [202] * PER_USER_PER_MINUTE + [429]
    shared = await make_device(db, "shared")
    codes = []
    for i in range(PER_DEVICE_PER_MINUTE + 1):
        _, h = await login(app_client, db, f"op{i}")
        codes.append((await app_client.post(P, headers=h, json={"device_id": shared, "mac": MAC, "vlans": [1]})).status_code)
    assert codes == [202] * PER_DEVICE_PER_MINUTE + [429]


# --- the worker ---

async def test_the_worker_sends_one_bounded_get_from_the_database_address_and_reports_where_the_mac_is(app_client, db, ctx, transport_on):
    _, headers, device, body = await requested(app_client, db)
    ctx.transport.script_get("10.61.0.1", {f"{DOT1Q_TP_FDB_PORT}.20.{OCTETS}": 7, f"{DOT1Q_TP_FDB_PORT}.10.{OCTETS}": 0})
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    gets = [c for c in ctx.transport.calls if c[0] == "get"]
    assert gets == [("get", "10.61.0.1", (f"{DOT1Q_TP_FDB_PORT}.10.{OCTETS}", f"{DOT1Q_TP_FDB_PORT}.20.{OCTETS}"))]
    assert ctx.transport.seen_communities == [COMMUNITY]
    result = (await app_client.get(f"{P}/{body['request_id']}", headers=headers)).json()
    assert result["status"] == "succeeded" and result["result"] == {"found": [{"vlan": 20, "bridge_port": 7}]}
    audit = json.loads(await db.fetchval("select metadata from audit_logs where action = 'search.mac'"))
    assert audit["status"] == "succeeded" and COMMUNITY not in json.dumps(audit)
    with pytest.raises(Skipped):
        await handlers.handle_mac_lookup(ctx, message(body["request_id"]))  # a redelivered job never asks twice
    assert len([c for c in ctx.transport.calls if c[0] == "get"]) == 1


@pytest.mark.parametrize("pollable,reason", [
    ({"owner": "legacy"}, "legacy system"),
    ({"with_profile": False}, "no access profile"),
])
async def test_the_worker_applies_the_polling_guards(app_client, db, ctx, transport_on, pollable, reason):
    _, _, _, body = await requested(app_client, db, **pollable)
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    record = await lookup.load(ctx.redis, body["request_id"])
    assert record.status == "refused" and reason in record.error and ctx.transport.calls == []


async def test_polling_switched_off_does_not_stop_a_lookup_but_an_open_breaker_does(app_client, db, ctx, transport_on):
    _, _, device, body = await requested(app_client, db, enabled=False)
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    assert (await lookup.load(ctx.redis, body["request_id"])).status == "succeeded"
    _, _, other, body2 = await requested(app_client, db, address="10.61.0.2", name="op2")
    await db.execute("insert into device_poll_state (device_id, consecutive_failures, breaker_open_until) "
                     "values ($1::uuid, 5, now() + interval '5 minutes')", other)
    await handlers.handle_mac_lookup(ctx, message(body2["request_id"]))
    record = await lookup.load(ctx.redis, body2["request_id"])
    assert record.status == "refused" and "circuit breaker" in record.error
    await db.execute("update device_poll_state set breaker_open_until = now() - interval '1 minute' where device_id = $1::uuid", other)
    await lookup.save(ctx.redis, body2["request_id"], lookup.Record(**{**record.__dict__, "status": "queued", "error": None}))
    await handlers.handle_mac_lookup(ctx, message(body2["request_id"]))
    assert (await lookup.load(ctx.redis, body2["request_id"])).status == "succeeded"  # a breaker that has closed again


@pytest.mark.parametrize("change,reason", [
    ("update users set is_active = false where id = $1::uuid", "no longer active"),
    ("delete from role_permissions where role_id = (select role_id from users where id = $1::uuid) "
     "and permission_id = (select id from permissions where code = 'search.mac')", "no longer has permission"),
])
async def test_the_requester_is_checked_again_when_the_worker_runs(app_client, db, ctx, transport_on, change, reason):
    user, _, _, body = await requested(app_client, db)
    await db.execute(change, user)
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    record = await lookup.load(ctx.redis, body["request_id"])
    assert record.status == "refused" and reason in record.error and ctx.transport.calls == []


async def test_a_device_moved_out_of_scope_is_refused(app_client, db, ctx, transport_on):
    mine, theirs = await make_group(db, "Mine"), await make_group(db, "Theirs")
    device = await make_pollable(db, "10.61.0.5")
    await db.execute("update devices set group_id = $2::uuid where id = $1::uuid", device, mine)
    _, headers = await login(app_client, db, "res", "Reseller Operator", group=mine)
    body = (await app_client.post(P, headers=headers, json={"device_id": device, "mac": MAC, "vlans": [1]})).json()
    await db.execute("update devices set group_id = $2::uuid where id = $1::uuid", device, theirs)
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    record = await lookup.load(ctx.redis, body["request_id"])
    assert record.status == "refused" and "scope" in record.error and ctx.transport.calls == []


async def test_a_device_error_is_reported_without_the_community(app_client, db, ctx, transport_on):
    _, _, _, body = await requested(app_client, db)
    ctx.transport.errors["10.61.0.1"] = f"bad community {COMMUNITY}"
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    record = await lookup.load(ctx.redis, body["request_id"])
    assert record.status == "failed" and COMMUNITY not in record.error and "[redacted]" in record.error


async def test_a_disabled_transport_refuses_and_a_malformed_or_expired_job_does_nothing(app_client, db, ctx, transport_on):
    _, _, _, body = await requested(app_client, db)
    ctx.transport = DisabledTransport()  # type: ignore[misc]
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    assert (await lookup.load(ctx.redis, body["request_id"])).status == "refused"
    with pytest.raises(Permanent):
        await handlers.handle_mac_lookup(ctx, message("not-a-uuid"))
    with pytest.raises(Skipped):
        await handlers.handle_mac_lookup(ctx, message("00000000-0000-0000-0000-000000000000"))


async def test_the_worker_consumes_lookups_only_with_a_transport(ctx):
    from app.workers.runner import Worker

    assert lookup.MAC_STREAM in [s for s, _ in Worker(ctx, {"diagnostics"}).streams()]
    assert lookup.MAC_STREAM not in [s for s, _ in Worker(ctx, {"poller"}).streams()]
    ctx.transport = DisabledTransport()  # type: ignore[misc]
    assert lookup.MAC_STREAM not in [s for s, _ in Worker(ctx, {"diagnostics"}).streams()]


async def test_the_result_is_announced_by_request_id_alone_to_mac_lookup_holders(app_client, db, ctx, transport_on):
    from app.realtime.bus import MAC_LOOKUP_FINISHED, REALTIME_CHANNEL
    from app.realtime.permissions import CHANNEL_RULES, is_subscribe_allowed

    _, _, _, body = await requested(app_client, db)
    pubsub = ctx.redis.pubsub()
    await pubsub.subscribe(REALTIME_CHANNEL)
    await pubsub.get_message(timeout=1)
    await handlers.handle_mac_lookup(ctx, message(body["request_id"]))
    notice = await pubsub.get_message(ignore_subscribe_messages=True, timeout=2.0)
    await pubsub.aclose()
    assert json.loads(notice["data"]) == {"name": MAC_LOOKUP_FINISHED, "data": {"request_id": body["request_id"]}}
    assert is_subscribe_allowed(CHANNEL_RULES, channel=MAC_LOOKUP_FINISHED, user_permissions=frozenset({"search.mac"}), scope_all=False)
    assert not is_subscribe_allowed(CHANNEL_RULES, channel=MAC_LOOKUP_FINISHED, user_permissions=frozenset({"diagnostics.icmp_ping"}),
                                    scope_all=True)
