"""Plan 38's first driver, `switch.port.set_admin_state`, against a fake transport only, plus the bounded SNMP SET it
uses. The exchanges below are scripted, not recorded from a device. No device is contacted."""
import re
from pathlib import Path

import pytest

from app.actions.drivers import ADMIN_LABELS, ADMIN_STATES, IF_ADMIN_STATUS, port_admin_executor
from app.actions.safety import ActionFailed
from app.core.crypto import EncryptionService
from app.core.security import CurrentUser
from app.polling.fake import FakeTransport
from app.polling.transport import BoundedTransport, Credentials, DisabledTransport, Target, TransportDisabled, UnboundedRequest, VarBind
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


def test_the_port_driver_is_the_only_one_registered_and_actions_are_off_by_default():
    from app.actions.drivers import DRIVERS
    from app.core.config import settings

    assert set(DRIVERS) == {"switch.port.set_admin_state"} and settings.device_actions_enabled is False


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
