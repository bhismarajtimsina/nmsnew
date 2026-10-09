"""Plan 38's first driver, `switch.port.set_admin_state`, against a fake transport only, plus the bounded SNMP SET it
uses. The exchanges below are scripted, not recorded from a device. No device is contacted."""
import re
from pathlib import Path

import pytest

from app.actions.drivers import (
    ADMIN_LABELS, ADMIN_STATES, BDCOM_CONFIG_OPERATION, BDCOM_CONFIG_RESULT, BDCOM_SAVE_COMMAND_CONFIG, IF_ADMIN_STATUS,
    SAVE_TIMEOUT_MS, port_admin_executor, save_config_executor,
)
from app.actions.safety import ActionFailed
from app.core.crypto import EncryptionService
from app.core.security import CurrentUser
from app.polling.fake import FakeTransport
from app.polling.transport import (
    MAX_TIMEOUT_MS, BoundedTransport, Credentials, DisabledTransport, Target, TransportDisabled, TransportTimeout, UnboundedRequest,
    VarBind,
)
from app.registry.mib import resolve
from app.repositories.access_profiles import aad
from tests.helpers import make_device, make_group, make_interface
from tests.polling_helpers import COMMUNITY, make_access_profile

WRITE = "rw-CANARY-write-91"
ADDRESS = "10.70.0.1"
ADMIN = CurrentUser("00000000-0000-0000-0000-000000000001", "op", "Op", None, "ISP Admin", "r", "all",
                    frozenset({"switches.port.set_admin_state", "dangerous_actions.execute"}))
_REPO = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / "BDCOM_MIBS").is_dir()), None)


# --- the OID is the one RFC 1213 defines, and it is writable with these values ---

def test_if_admin_status_is_rfc1213s_writable_column_with_these_values():
    if _REPO is None:
        pytest.skip("BDCOM_MIBS is not mounted in this test environment")
    sources = {p.name: p.read_text(errors="ignore") for p in sorted((_REPO / "BDCOM_MIBS").iterdir())
               if p.is_file() and p.name != "Q-BRIDGE-MIB.my"}
    entries = {e.definition.name: e for e in resolve(sources).files["RFC1213-MIB.my"].entries}
    assert entries["ifAdminStatus"].numeric_oid == IF_ADMIN_STATUS
    clause = re.search(r"^\s*ifAdminStatus\s+OBJECT-TYPE(.*?)::=", sources["RFC1213-MIB.my"], re.S | re.M).group(1)
    assert re.search(r"ACCESS\s+([\w-]+)", clause).group(1) == "read-write"
    enum = {int(n): name for name, n in re.findall(r"(\w+)\((\d+)\)", clause)}
    assert enum == ADMIN_LABELS and all(enum[v] == k for k, v in ADMIN_STATES.items())


# --- the bounded SET ---

V2C = Credentials("v2c", community="read-only", write_community=WRITE)


async def test_a_set_goes_out_with_the_write_community_only():
    fake = FakeTransport()
    bounded = BoundedTransport(fake)
    await bounded.set(Target(ADDRESS, V2C), [VarBind(f"{IF_ADMIN_STATUS}.3", "integer", 2)], timeout_ms=2000)
    assert fake.seen_communities == [WRITE] and fake.sets == [(ADDRESS, ((f"{IF_ADMIN_STATUS}.3", "integer", 2),))]
    assert bounded.requests[-1].kind == "set"


@pytest.mark.parametrize("varbinds, creds", [
    ([], V2C),
    ([VarBind(f"1.3.6.1.2.1.2.2.1.7.{i}", "integer", 2) for i in range(5)], V2C),
    ([VarBind("ifAdminStatus.3", "integer", 2)], V2C),
    ([VarBind("1.3.6.1.2.1.2.2.1.7.3", "integer", True)], V2C),
    ([VarBind("1.3.6.1.2.1.2.2.1.7.3", "integer", 2**31)], V2C),
    ([VarBind("1.3.6.1.2.1.31.1.1.1.18.3", "octet_string", "a" * 256)], V2C),
    ([VarBind("1.3.6.1.2.1.31.1.1.1.18.3", "octet_string", "two\nlines")], V2C),
    ([VarBind("1.3.6.1.2.1.2.2.1.7.3", "counter", 2)], V2C),
    ([VarBind("1.3.6.1.2.1.2.2.1.7.3", "integer", 2)], Credentials("v2c", community="read-only")),  # no write community
])
async def test_a_set_outside_the_bounds_is_refused_before_anything_is_sent(varbinds, creds):
    fake = FakeTransport()
    with pytest.raises(UnboundedRequest):
        await BoundedTransport(fake).set(Target(ADDRESS, creds), varbinds, timeout_ms=2000)
    assert fake.calls == []


async def test_the_disabled_production_transport_refuses_a_set():
    with pytest.raises(TransportDisabled):
        await BoundedTransport(DisabledTransport()).set(Target(ADDRESS, V2C), [VarBind(f"{IF_ADMIN_STATUS}.3", "integer", 2)], timeout_ms=2000)


# --- the driver ---

async def switch(db, *, write=True, if_index=12, group=None):
    enc = EncryptionService.from_settings()
    profile = await make_access_profile(db, f"p-{if_index}-{write}")
    if write:
        await db.execute("update device_access_profiles set snmp_write_community_enc = $2 where id = $1::uuid",
                         profile, enc.encrypt(WRITE, aad(str(profile), "snmp_write_community")))
    device = await make_device(db, "sw-access-1", group, ip=ADDRESS)
    await db.execute("update devices set access_profile_id = $2::uuid where id = $1::uuid", device, profile)
    port = await make_interface(db, device, "Gi0/12", if_index)  # interfaces.if_index is NOT NULL in the schema
    return enc, port


def oid(index=12):
    return f"{IF_ADMIN_STATUS}.{index}"


async def test_disabling_an_enabled_port_reads_writes_and_verifies_with_the_write_community(db):
    enc, port = await switch(db)
    fake = FakeTransport()
    fake.script_get(ADDRESS, {oid(): 1})
    outcome = await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})
    # The exchange, in order: read the current state, write the new one, read it back.
    assert [(c[0], c[2]) for c in fake.calls] == [("get", (oid(),)), ("set", (oid(),)), ("get", (oid(),))]
    assert fake.sets == [(ADDRESS, ((oid(), "integer", 2),))]
    assert set(fake.seen_communities) == {WRITE} and COMMUNITY not in fake.seen_communities  # read community never used
    assert outcome.before == {"interface": "Gi0/12", "if_index": 12, "admin_status": "up"}      # the undo hint
    assert outcome.after == {"interface": "Gi0/12", "if_index": 12, "admin_status": "down", "changed": True}


async def test_a_port_already_in_the_asked_state_is_not_written(db):
    enc, port = await switch(db)
    fake = FakeTransport()
    fake.script_get(ADDRESS, {oid(): 2})
    outcome = await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})
    assert fake.sets == [] and outcome.after["changed"] is False and outcome.after["admin_status"] == "down"


async def test_a_change_the_device_does_not_apply_is_a_failure(db):
    enc, port = await switch(db)
    fake = FakeTransport()
    fake.script_get(ADDRESS, {oid(): 1})
    fake.ignore_sets.add(ADDRESS)
    with pytest.raises(ActionFailed, match="now reports up"):
        await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})


async def test_a_refused_set_fails_with_the_community_scrubbed_from_the_error(db):
    enc, port = await switch(db)
    fake = FakeTransport()
    fake.script_get(ADDRESS, {oid(): 1})
    fake.refuse_sets[ADDRESS] = f"noAccess for community {WRITE}"
    with pytest.raises(ActionFailed) as failure:
        await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})
    assert WRITE not in str(failure.value) and "[redacted]" in str(failure.value)


async def test_no_write_community_means_no_request_at_all(db):
    enc, port = await switch(db, write=False)
    fake = FakeTransport()
    with pytest.raises(ActionFailed, match="no write community"):
        await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})
    assert fake.calls == []


async def test_an_interface_outside_the_callers_scope_is_not_touched(db):
    north = await make_group(db, "North")
    enc, port = await switch(db, group=north)
    reseller = CurrentUser("00000000-0000-0000-0000-000000000002", "res", "Res", None, "Reseller Operator", "r", "assigned",
                           frozenset({"switches.port.set_admin_state", "dangerous_actions.execute"}))
    fake = FakeTransport()
    with pytest.raises(ActionFailed, match="not visible"):
        await port_admin_executor(fake, enc)(db, reseller, {"interface_id": port}, {"state": "down"})
    assert fake.calls == []


async def test_with_the_disabled_transport_the_action_fails_and_nothing_is_sent(db):
    enc, port = await switch(db)
    with pytest.raises(ActionFailed, match="disabled"):
        await port_admin_executor(DisabledTransport(), enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})


def test_only_the_mib_checked_drivers_are_registered_and_actions_are_off_by_default():
    from app.actions.drivers import DRIVERS
    from app.core.config import settings

    assert set(DRIVERS) == {"switch.port.set_admin_state", "switch.save_config"} and settings.device_actions_enabled is False


async def test_a_protected_interface_is_never_shut_down_but_can_be_brought_up(db):
    enc, port = await switch(db)
    await db.execute("update interfaces set protected = true where id = $1::uuid", port)
    fake = FakeTransport()
    fake.script_get(ADDRESS, {oid(): 2})
    with pytest.raises(ActionFailed, match="protected"):
        await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "down"})
    assert fake.calls == []  # refused before anything was sent
    outcome = await port_admin_executor(fake, enc)(db, ADMIN, {"interface_id": port}, {"state": "up"})
    assert outcome.after["admin_status"] == "up" and outcome.after["changed"] is True



# --- `switch.save_config` on BDCOM (NMS-CONFIG-MGMT) ---

def _clause(text: str, name: str) -> str:
    return re.search(rf"^\s*{name}\s+OBJECT-TYPE(.*?)::=\s*\{{[^}}]*\}}", text, re.S | re.M).group(1)


def test_the_save_objects_are_nms_config_mgmts_writable_operation_and_its_result():
    if _REPO is None or not (_REPO / "NMS_BDCOM_MIBS").is_dir():
        pytest.skip("NMS_BDCOM_MIBS is not mounted in this test environment")
    sources = {p.name: p.read_text(errors="ignore") for p in sorted((_REPO / "NMS_BDCOM_MIBS").iterdir()) if p.is_file()}
    # By file, not by name: `operation` is defined with three different OIDs across BDCOM's MIBs.
    entries = {e.definition.name: e for e in resolve(sources).files["NMS-CONFIG-MGMT.my"].entries}
    assert f"{entries['operation'].numeric_oid}.0" == BDCOM_CONFIG_OPERATION
    assert f"{entries['result'].numeric_oid}.0" == BDCOM_CONFIG_RESULT
    text = sources["NMS-CONFIG-MGMT.my"]
    operation = _clause(text, "operation")
    assert re.search(r"ACCESS\s+([\w-]+)", operation).group(1) == "read-write"
    assert re.search(r"(\d+) means to save the comm+and configuration", operation).group(1) == str(BDCOM_SAVE_COMMAND_CONFIG)
    assert re.search(r"ACCESS\s+([\w-]+)", _clause(text, "result")).group(1) == "read-only"
    # The BDCOM-named copy places the same objects at the same sub-identifiers.
    bd = (_REPO / "BDCOM_MIBS" / "BDCOM-CONFIG-MGMT.my").read_text(errors="ignore")
    assert re.search(r"libdm\s+OBJECT IDENTIFIER ::= \{ bdWorkGroup 15 \}", bd)
    assert re.search(r"linmsm\s+OBJECT IDENTIFIER ::= \{ nmsWorkGroup 15 \}", text)
    assert "1.3.6.1.4.1.3320.20" in BDCOM_CONFIG_OPERATION  # nmsWorkGroup, per NMS-SMI


SAVER = CurrentUser("00000000-0000-0000-0000-000000000001", "op", "Op", None, "ISP Admin", "r", "all",
                    frozenset({"switches.save_config", "dangerous_actions.execute"}))


async def bdcom(db, *, vendor="bdcom", write=True, group=None, timeout_ms=2000, ip=ADDRESS):
    enc = EncryptionService.from_settings()
    profile = await make_access_profile(db, f"save-{vendor}-{write}-{ip}")
    await db.execute("update device_access_profiles set timeout_ms = $2 where id = $1::uuid", profile, timeout_ms)
    if write:
        await db.execute("update device_access_profiles set snmp_write_community_enc = $2 where id = $1::uuid",
                         profile, enc.encrypt(WRITE, aad(str(profile), "snmp_write_community")))
    device = await make_device(db, "olt-core-1", group, ip=ip)
    await db.execute("update devices set access_profile_id = $2::uuid, vendor_id = (select id from vendors where slug = $3) "
                     "where id = $1::uuid", device, profile, vendor)
    return enc, device


async def test_saving_writes_one_to_the_mib_object_with_the_write_community_and_records_the_result(db):
    enc, device = await bdcom(db)
    fake = FakeTransport()
    fake.script_get(ADDRESS, {BDCOM_CONFIG_RESULT: 0})
    outcome = await save_config_executor(fake, enc)(db, SAVER, {"device_id": device}, {})
    assert [(c[0], c[2]) for c in fake.calls] == [("set", (BDCOM_CONFIG_OPERATION,)), ("get", (BDCOM_CONFIG_RESULT,))]
    assert fake.sets == [(ADDRESS, ((BDCOM_CONFIG_OPERATION, "integer", 1),))]
    assert set(fake.seen_communities) == {WRITE}
    assert outcome.before == {"device": "olt-core-1"}
    assert outcome.after == {"device": "olt-core-1", "saved": True, "result_code": 0}


async def test_saving_allows_flash_time_but_stays_inside_the_transport_cap(db):
    enc, device = await bdcom(db, timeout_ms=1000)
    fake = FakeTransport()
    await save_config_executor(fake, enc)(db, SAVER, {"device_id": device}, {})
    assert fake.set_limits == [(ADDRESS, min(SAVE_TIMEOUT_MS, MAX_TIMEOUT_MS))]
    assert fake.get_limits[-1][1] == 1000  # the follow-up read uses the profile's own timeout


@pytest.mark.parametrize("vendor", ["huawei", "zte", None])
async def test_other_vendors_are_refused_before_anything_is_sent(db, vendor):
    enc, device = await bdcom(db, vendor=vendor)
    fake = FakeTransport()
    with pytest.raises(ActionFailed, match="only supported on BDCOM"):
        await save_config_executor(fake, enc)(db, SAVER, {"device_id": device}, {})
    assert fake.calls == []


async def test_a_refused_save_fails_with_the_community_scrubbed_and_says_it_may_have_saved(db):
    enc, device = await bdcom(db)
    fake = FakeTransport()
    fake.refuse_sets[ADDRESS] = f"genErr for {WRITE}"
    with pytest.raises(ActionFailed) as failure:
        await save_config_executor(fake, enc)(db, SAVER, {"device_id": device}, {})
    assert WRITE not in str(failure.value) and "may still have saved" in str(failure.value)
    assert [c[0] for c in fake.calls] == ["set"]  # no follow-up read after a failed write


async def test_an_unanswered_follow_up_read_does_not_turn_an_accepted_save_into_a_failure(db):
    enc, device = await bdcom(db)

    class ReadTimesOut(FakeTransport):
        async def get(self, target, oids, *, timeout_ms, retries):
            await super().get(target, oids, timeout_ms=timeout_ms, retries=retries)
            raise TransportTimeout("no response")

    fake = ReadTimesOut()
    outcome = await save_config_executor(fake, enc)(db, SAVER, {"device_id": device}, {})
    assert outcome.after == {"device": "olt-core-1", "saved": True, "result_code": None}


async def test_saving_needs_a_write_community_and_a_visible_device(db):
    enc, device = await bdcom(db, write=False)
    fake = FakeTransport()
    with pytest.raises(ActionFailed, match="no write community"):
        await save_config_executor(fake, enc)(db, SAVER, {"device_id": device}, {})
    north = await make_group(db, "North")
    enc2, hidden = await bdcom(db, group=north, ip="10.70.0.2")
    reseller = CurrentUser("00000000-0000-0000-0000-000000000002", "res", "Res", None, "Reseller Operator", "r", "assigned",
                           frozenset({"switches.save_config", "dangerous_actions.execute"}))
    with pytest.raises(ActionFailed, match="not visible"):
        await save_config_executor(fake, enc2)(db, reseller, {"device_id": hidden}, {})
    assert fake.calls == []


async def test_with_the_disabled_transport_a_save_fails_and_nothing_is_sent(db):
    enc, device = await bdcom(db)
    with pytest.raises(ActionFailed, match="disabled"):
        await save_config_executor(DisabledTransport(), enc)(db, SAVER, {"device_id": device}, {})
