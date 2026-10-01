"""The real, seeded system_basic and interface_basic profiles, exercised through the actual polling engine (Plan 11)
with a fake transport — the first time the engine runs against production OID data rather than throwaway test
fixtures. No device is contacted."""
from app.registry.standard_oids import INTERFACE_BASIC_PROFILE, INTERFACE_DEFINITIONS, SYSTEM_BASIC_PROFILE, SYSTEM_DEFINITIONS
from tests.polling_helpers import ctx, make_pollable  # noqa: F401

SYS_DESCR, SYS_OBJECT_ID, SYS_UPTIME, SYS_NAME = (
    "1.3.6.1.2.1.1.1", "1.3.6.1.2.1.1.2", "1.3.6.1.2.1.1.3", "1.3.6.1.2.1.1.5",
)
IF_DESCR, IF_OPER_STATUS, IF_IN_OCTETS = "1.3.6.1.2.1.2.2.1.2", "1.3.6.1.2.1.2.2.1.8", "1.3.6.1.2.1.2.2.1.10"


async def test_the_seeded_profiles_are_active_and_well_formed(db):
    for name, count in ((SYSTEM_BASIC_PROFILE, len(SYSTEM_DEFINITIONS)), (INTERFACE_BASIC_PROFILE, len(INTERFACE_DEFINITIONS))):
        row = await db.fetchrow("select status, version from oid_profiles where name = $1", name)
        assert row["status"] == "active" and row["version"] == 1
        entries = await db.fetchval(
            "select count(*) from oid_profile_entries e join oid_profiles p on p.id = e.profile_id where p.name = $1", name)
        assert entries == count


async def test_polling_system_basic_against_a_fake_device_reads_every_real_standard_object(ctx, db):
    device = await make_pollable(db, "10.95.0.1")
    # A real agent answers only for the scalar's instance (.0); the fake device is scripted the same way, so a profile
    # that asked for the bare object OIDs would get nothing back here, as it would from a real device.
    ctx.transport.script_get("10.95.0.1", {
        SYS_DESCR + ".0": "BDCOM(tm) S5612 Software, Version 127335", SYS_OBJECT_ID + ".0": "1.3.6.1.4.1.3320.1.283.0",
        SYS_UPTIME + ".0": 123456, SYS_NAME + ".0": "edge-sw-1",
    })
    from app.polling.engine import poll_device

    outcome = await poll_device(ctx, device, SYSTEM_BASIC_PROFILE)
    assert outcome.status == "ok" and outcome.rows == 4  # sysContact and sysLocation were not scripted, so absent from the reply
    by_name = {r.oid: r.value for r in outcome.readings}
    assert by_name[SYS_NAME] == "edge-sw-1" and by_name[SYS_OBJECT_ID] == "1.3.6.1.4.1.3320.1.283.0"
    # Every request the fake device saw came from the real, seeded profile's own OIDs, in one GET.
    (call,) = ctx.transport.calls
    instances = {oid + ".0" for oid in (SYS_DESCR, SYS_OBJECT_ID, SYS_UPTIME, "1.3.6.1.2.1.1.4", "1.3.6.1.2.1.1.6", SYS_NAME)}
    assert call[0] == "get" and set(call[2]) == instances


async def test_polling_interface_basic_walks_every_real_column_bounded(ctx, db):
    device = await make_pollable(db, "10.95.0.2")
    for column in (IF_DESCR, IF_OPER_STATUS, IF_IN_OCTETS):
        ctx.transport.script_table("10.95.0.2", column, [(f"{column}.{i}", f"v{i}" if column == IF_DESCR else i) for i in range(1, 5)])
    from app.polling.engine import poll_device

    outcome = await poll_device(ctx, device, INTERFACE_BASIC_PROFILE)
    assert outcome.status == "ok" and outcome.truncated is False
    walked = {call[2][0] for call in ctx.transport.calls if call[0] == "walk"}
    assert len(walked) == len(INTERFACE_DEFINITIONS)  # every real column was walked, once each
    for _, root, max_rows, timeout in ctx.transport.walk_limits:
        assert max_rows == 512 + 1 and timeout == 8000  # the real seeded bounds plus one probe row, not a test-only override
    by_oid = {r.oid: r.value for r in outcome.readings}
    assert len(by_oid[IF_DESCR]) == 4 and by_oid[IF_DESCR][0] == (f"{IF_DESCR}.1", "v1")


async def test_a_table_larger_than_the_real_bound_is_truncated_not_walked_without_limit(ctx, db):
    device = await make_pollable(db, "10.95.0.3")
    ctx.transport.ignore_walk_limit = True
    ctx.transport.script_table("10.95.0.3", IF_DESCR, [(f"{IF_DESCR}.{i}", f"v{i}") for i in range(1000)])
    from app.polling.engine import poll_device

    outcome = await poll_device(ctx, device, INTERFACE_BASIC_PROFILE)
    assert outcome.status == "ok" and outcome.truncated is True and outcome.rows <= 512 * len(INTERFACE_DEFINITIONS)


def test_a_get_requests_the_scalar_instance_and_leaves_an_instanced_oid_alone():
    from app.polling.engine import scalar_instance

    assert scalar_instance("1.3.6.1.2.1.1.5") == "1.3.6.1.2.1.1.5.0"
    assert scalar_instance("1.3.6.1.2.1.1.5.0") == "1.3.6.1.2.1.1.5.0"
