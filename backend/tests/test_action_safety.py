"""The dangerous-action safety layer (Plan 26) with Plan 38's queued execution. Fake drivers stand in for the real
ones and the worker step runs with the fake transport; no device is contacted. With the kill switch off (the default)
nothing can be prepared at all."""
import json

import pytest

from app.actions import drivers as drivers_module
from app.actions import safety
from app.actions.catalogue import ACTIONS
from app.core.config import settings
from app.core.crypto import EncryptionService
from app.polling.fake import FakeTransport
from tests.helpers import bearer, make_device, make_group, make_interface, make_user

A = "/api/v1/actions"
_DB: dict = {}


@pytest.fixture(autouse=True)
def _remember_db(db):
    """The worker step in `execute` runs on the same test database as the requests."""
    _DB["conn"] = db


@pytest.fixture
def executor(monkeypatch):
    calls = []

    async def fake(conn, user, target, params):
        calls.append((target, params))
        if target.get("onu") == "FAIL0001":
            raise safety.ActionFailed("ONU did not answer")
        return safety.Outcome(before={"state": "online", "community": "secret-public"}, after={"state": "rebooting"})

    monkeypatch.setattr(settings, "device_actions_enabled", True)
    monkeypatch.setattr(drivers_module, "DRIVERS", {key: (lambda transport, enc: fake) for key in ACTIONS})
    return calls


async def login_as(app_client, db, name, role):
    user = await make_user(db, name, role)
    headers = await bearer(app_client, name)
    app_client.cookies.clear()
    return user, headers


async def prepare(app_client, headers, action, targets, params=None, expect=200):
    response = await app_client.post(f"{A}/{action}/prepare", headers=headers, json={"targets": targets, "params": params or {}})
    assert response.status_code == expect, response.text
    return response.json()


async def execute(app_client, headers, action, token, targets, params=None, expect=200, run=True, db=None):
    """Confirm, then (by default) run the worker step and return the final results, as a caller polling
    GET /actions/results would see them once the worker is done."""
    response = await app_client.post(f"{A}/{action}/execute", headers=headers,
                                     json={"token": token, "targets": targets, "params": params or {}})
    assert response.status_code == expect, response.text
    body = response.json()
    if expect != 200 or not run:
        return body
    assert all(r["status"] == "queued" for r in body["results"])
    await safety.run_confirmation(db or _DB["conn"], FakeTransport(), EncryptionService.from_settings(), body["confirmation_id"])
    final = await app_client.get(f"{A}/results/{body['confirmation_id']}", headers=headers)
    assert final.status_code == 200, final.text
    return final.json()


async def setup(app_client, db, role="ISP Admin"):
    north, south = await make_group(db, "North"), await make_group(db, "South")
    olt = await make_device(db, "olt-north", north, ip="10.1.0.1")
    other = await make_device(db, "olt-south", south, ip="10.2.0.1")
    user, headers = await login_as(app_client, db, "op", role)
    return user, headers, north, olt, other


# --- the dry run ---

async def test_nothing_can_be_prepared_while_device_actions_are_switched_off(app_client, db):
    assert settings.device_actions_enabled is False  # the default
    _, headers, _, olt, _ = await setup(app_client, db)
    body = await prepare(app_client, headers, "onu.reboot", [{"device_id": olt, "onu": "HWTC1234"}], expect=503)
    assert "switched off" in body["detail"]
    assert await db.fetchval("select count(*) from action_confirmations") == 0


async def test_an_action_without_a_driver_cannot_be_prepared_even_when_switched_on(app_client, db, monkeypatch):
    monkeypatch.setattr(settings, "device_actions_enabled", True)
    _, headers, _, olt, _ = await setup(app_client, db)
    body = await prepare(app_client, headers, "onu.reboot", [{"device_id": olt, "onu": "HWTC1234"}], expect=501)
    assert "Plan 38" in body["detail"] and "onu.reboot" not in drivers_module.DRIVERS
    assert await db.fetchval("select count(*) from action_confirmations") == 0


async def test_a_dry_run_lists_every_target_and_sends_nothing(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db)
    targets = [{"device_id": olt, "onu": f"HWTC000{i}"} for i in range(3)]
    body = await prepare(app_client, headers, "onu.reboot", targets)
    assert body["summary"]["count"] == 3 and [t["onu"] for t in body["summary"]["targets"]] == ["HWTC0000", "HWTC0001", "HWTC0002"]
    assert all(t["device"] == "olt-north" and t["ip"] == "10.1.0.1" for t in body["summary"]["targets"])
    assert executor == [] and len(body["token"]) >= 40
    stored = await db.fetchrow("select token_hash, target_count, expires_at - created_at as ttl from action_confirmations")
    assert stored["token_hash"] != body["token"] and stored["target_count"] == 3 and stored["ttl"].total_seconds() == 120
    assert await db.fetchval("select count(*) from audit_logs where action = 'action.prepare'") == 1


async def test_duplicate_targets_collapse_and_a_request_above_the_cap_is_refused_not_trimmed(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db)
    same = [{"device_id": olt, "onu": "HWTC0001"}] * 3
    assert (await prepare(app_client, headers, "onu.reboot", same))["summary"]["count"] == 1
    cap = ACTIONS["onu.reset"].max_targets
    too_many = [{"device_id": olt, "onu": f"HWTC{i:04d}"} for i in range(cap + 1)]
    assert "at most" in (await prepare(app_client, headers, "onu.reset", too_many, expect=422))["detail"]
    assert await db.fetchval("select count(*) from action_confirmations where action = 'onu.reset'") == 0


@pytest.mark.parametrize("targets, params", [
    ([{"device_id": "not-a-uuid", "onu": "HWTC0001"}], {}),
    ([{"onu": "HWTC0001"}], {}),
    ([{"device_id": "00000000-0000-0000-0000-000000000001", "onu": "bad onu; rm"}], {}),
    ([{"device_id": "00000000-0000-0000-0000-000000000001", "onu": "HWTC0001", "extra": "x"}], {}),
    ([{"device_id": "00000000-0000-0000-0000-000000000001", "onu": "HWTC0001"}], {"force": "yes"}),
])
async def test_malformed_targets_and_unknown_parameters_are_refused(app_client, db, executor, targets, params):
    _, headers, *_ = await setup(app_client, db)
    await prepare(app_client, headers, "onu.reboot", targets, params, expect=422)


async def test_parameters_are_checked_against_the_allowed_values(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db)
    port = await make_interface(db, olt, "Gi0/1")
    await prepare(app_client, headers, "switch.port.set_admin_state", [{"interface_id": port}], {"state": "sideways"}, expect=422)
    await prepare(app_client, headers, "switch.port.set_admin_state", [{"interface_id": port}], expect=422)
    body = await prepare(app_client, headers, "switch.port.set_admin_state", [{"interface_id": port}], {"state": "down"})
    assert body["summary"]["targets"][0]["interface"] == "Gi0/1" and body["summary"]["params"] == {"state": "down"}


# --- who may ---

async def test_an_unauthorized_user_is_blocked_by_the_gate_and_by_the_actions_own_permission(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db, role="ISP Support")  # has the gate, not olts.onu.reset
    await prepare(app_client, headers, "onu.reset", [{"device_id": olt, "onu": "HWTC0001"}], expect=403)
    await db.execute("delete from role_permissions where role_id = (select id from roles where name = 'ISP Support') "
                     "and permission_id = (select id from permissions where code = 'dangerous_actions.execute')")
    assert (await app_client.get(A, headers=headers)).status_code == 403
    assert (await app_client.post(f"{A}/onu.reboot/prepare", json={"targets": [{"device_id": olt, "onu": "X"}]})).status_code == 401


async def test_a_reseller_can_act_only_inside_its_scope(app_client, db, executor):
    user, headers, north, olt, other = await setup(app_client, db, role="Reseller Operator")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, north)
    await prepare(app_client, headers, "onu.reboot", [{"device_id": olt, "onu": "HWTC0001"}])
    mixed = [{"device_id": olt, "onu": "HWTC0001"}, {"device_id": other, "onu": "HWTC0002"}]
    body = await prepare(app_client, headers, "onu.reboot", mixed, expect=404)
    assert "scope" in body["detail"]  # one out-of-scope target refuses the whole batch
    await prepare(app_client, headers, "onu.reboot", [{"device_id": "00000000-0000-0000-0000-000000000009", "onu": "X1"}], expect=404)


async def test_the_action_list_shows_only_what_the_role_may_run(app_client, db, executor):
    _, headers, *_ = await setup(app_client, db, role="Reseller Operator")
    keys = {a["key"] for a in (await app_client.get(A, headers=headers)).json()["items"]}
    assert "onu.reboot" in keys and "switch.reboot" not in keys and "onu.reset" not in keys


# --- the confirmation ---

async def test_a_confirmed_action_runs_once_records_results_and_audits_with_secrets_redacted(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db)
    targets = [{"device_id": olt, "onu": "HWTC0001"}, {"device_id": olt, "onu": "FAIL0001"}]
    token = (await prepare(app_client, headers, "onu.reboot", targets))["token"]
    body = await execute(app_client, headers, "onu.reboot", token, targets)
    assert {r["target"]["onu"]: (r["status"], r["error"]) for r in body["results"]} == {
        "HWTC0001": ("succeeded", None), "FAIL0001": ("failed", "ONU did not answer")}
    assert len(executor) == 2
    rows = await db.fetch("select status, before, after from action_results order by status")
    assert [r["status"] for r in rows] == ["failed", "succeeded"]
    ok = json.loads(rows[1]["before"])
    assert ok == {"state": "online", "community": "[redacted]"} and json.loads(rows[1]["after"]) == {"state": "rebooting"}
    audit = await db.fetch("select before, after, metadata from audit_logs where action = 'action.execute'")
    assert len(audit) == 2 and all("secret-public" not in json.dumps([dict(a) for a in audit], default=str) for _ in [0])
    # Used once: the same token again is refused, and the executor does not run again.
    await execute(app_client, headers, "onu.reboot", token, targets, expect=409)
    assert len(executor) == 2


@pytest.mark.parametrize("change", ["other_target", "other_params", "other_action", "added_target"])
async def test_a_token_cannot_be_moved_to_another_request_and_is_burned_by_trying(app_client, db, executor, change):
    _, headers, _, olt, other = await setup(app_client, db)
    port = await make_interface(db, olt, "Gi0/1")
    port2 = await make_interface(db, olt, "Gi0/2", 2)
    target = [{"interface_id": port}]
    token = (await prepare(app_client, headers, "switch.port.set_admin_state", target, {"state": "down"}))["token"]
    attempt = {
        "other_target": ("switch.port.set_admin_state", [{"interface_id": port2}], {"state": "down"}),
        "other_params": ("switch.port.set_admin_state", target, {"state": "up"}),
        "other_action": ("switch.counters.clear", target, {}),
        "added_target": ("switch.port.set_admin_state", target + [{"interface_id": port2}], {"state": "down"}),
    }[change]
    if change == "added_target":
        await execute(app_client, headers, *attempt[:1], token, attempt[1], attempt[2], expect=422)  # over the cap of 1
        await execute(app_client, headers, "switch.port.set_admin_state", token, target, {"state": "down"})  # still valid
        return
    body = await execute(app_client, headers, attempt[0], token, attempt[1], attempt[2], expect=409)
    assert body["detail"] == "the confirmation is invalid, expired, already used, or for a different request"
    await execute(app_client, headers, "switch.port.set_admin_state", token, target, {"state": "down"}, expect=409)  # burned
    assert executor == []


async def test_a_token_expires_and_belongs_to_the_user_it_was_issued_to(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db)
    targets = [{"device_id": olt, "onu": "HWTC0001"}]
    token = (await prepare(app_client, headers, "onu.reboot", targets))["token"]
    _, other_headers = await login_as(app_client, db, "op2", "ISP Admin")
    await execute(app_client, other_headers, "onu.reboot", token, targets, expect=409)
    await execute(app_client, headers, "onu.reboot", token, targets)  # someone else's attempt did not burn it

    token = (await prepare(app_client, headers, "onu.reboot", targets))["token"]
    await db.execute("update action_confirmations set created_at = created_at - interval '5 minutes', expires_at = now() - interval '1 second' where used_at is null")
    await execute(app_client, headers, "onu.reboot", token, targets, expect=409)
    assert len(executor) == 1


async def test_scope_is_checked_again_at_execution(app_client, db, executor):
    user, headers, north, olt, _ = await setup(app_client, db, role="Reseller Operator")
    await db.execute("insert into user_device_group_scopes (user_id, device_group_id) values ($1::uuid, $2::uuid)", user, north)
    targets = [{"device_id": olt, "onu": "HWTC0001"}]
    token = (await prepare(app_client, headers, "onu.reboot", targets))["token"]
    await db.execute("delete from user_device_group_scopes where user_id = $1::uuid", user)
    body = await execute(app_client, headers, "onu.reboot", token, targets)
    assert body["results"][0]["status"] == "refused" and executor == []


async def test_the_database_caps_the_lifetime_and_the_batch(db):
    user = await make_user(db, "x")
    for ttl, count in (("11 minutes", 1), ("1 minute", 0), ("1 minute", 501)):
        with pytest.raises(Exception):
            await db.execute(
                "insert into action_confirmations (token_hash, user_id, action, targets, target_count, params_digest, summary, expires_at) "
                "values (md5(random()::text), $1::uuid, 'onu.reboot', (select jsonb_agg(g) from generate_series(1, greatest($2, 1)) g), $2, 'd', '{}', now() + $3::interval)",
                user, count, ttl)


async def test_a_token_for_one_action_cannot_run_another_with_the_same_targets_and_parameters(app_client, db, executor):
    _, headers, _, olt, _ = await setup(app_client, db)
    targets = [{"device_id": olt}]
    token = (await prepare(app_client, headers, "switch.save_config", targets))["token"]
    await execute(app_client, headers, "switch.reboot", token, targets, expect=409)
    await execute(app_client, headers, "switch.save_config", token, targets, expect=409)  # burned by the attempt
    assert executor == []


def test_the_service_itself_demands_the_gate_not_only_the_route():
    """Plan 38's code may call the service without going through the HTTP route."""
    from app.core.security import CurrentUser

    without_gate = CurrentUser("1", "u", "U", None, "Custom", "r", "all", frozenset({"switches.reboot"}))
    with_gate = CurrentUser("1", "u", "U", None, "Custom", "r", "all", frozenset({"switches.reboot", "dangerous_actions.execute"}))
    with pytest.raises(safety.NotAllowed):
        safety.spec_for(without_gate, "switch.reboot")
    assert safety.spec_for(with_gate, "switch.reboot").key == "switch.reboot"
    with pytest.raises(safety.UnknownAction):
        safety.spec_for(with_gate, "switch.self_destruct")
