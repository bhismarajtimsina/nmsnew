"""Turning a decoded trap into a trap_history row: device resolution by source IP, and matching a known profile
by OID. No device is contacted; this only exercises the write path against a real database."""
from app.core.crypto import EncryptionService
from app.traps.decode import DecodedTrap
from app.traps.ingest import community_matches, record_trap, resolve_device_by_source
from tests.helpers import make_device
from tests.polling_helpers import COMMUNITY, make_access_profile


def test_community_matches_requires_an_exact_equal_configured_value():
    assert community_matches("public", "public") is True
    assert community_matches("public", "private") is False
    assert community_matches("public", None) is False  # no configured community matches nothing, like legacy's nulls


async def test_a_trap_from_an_unknown_source_is_dropped_and_nothing_is_stored(db):
    decoded = DecodedTrap(version="v2c", community="public", trap_oid="1.3.6.1.6.3.1.1.5.3", varbinds={})
    outcome = await record_trap(db, "10.99.99.99", decoded)
    assert outcome.accepted is False and outcome.device_id is None
    assert await db.fetchval("select count(*) from trap_history") == 0


async def test_a_known_traps_oid_matches_its_seeded_profile(db):
    await make_device(db, "sw1", ip="10.60.0.1")
    decoded = DecodedTrap(version="v2c", community="public", trap_oid="1.3.6.1.6.3.1.1.5.3", varbinds={"x": "1"})
    outcome = await record_trap(db, "10.60.0.1", decoded)
    assert outcome.accepted is True and outcome.known is True
    row = await db.fetchrow("select vendor, trap_name, trap_oid, version from trap_history")
    assert row["vendor"] == "global" and row["trap_name"] == "LinkDown" and row["version"] == "v2c"


async def test_an_unrecognized_oid_from_a_known_device_is_still_stored_as_unknown(db):
    device = await make_device(db, "sw1", ip="10.60.0.2")
    decoded = DecodedTrap(version="v1", community="public", trap_oid="1.3.6.1.4.1.99999.1.2.3", varbinds={})
    outcome = await record_trap(db, "10.60.0.2", decoded)
    assert outcome.accepted is True and outcome.known is False
    row = await db.fetchrow("select device_id, trap_profile_id, vendor, trap_name from trap_history")
    assert str(row["device_id"]) == device and row["trap_profile_id"] is None
    assert row["vendor"] is None and row["trap_name"] is None


async def test_resolve_device_by_source_matches_the_management_ip(db):
    device = await make_device(db, "sw1", ip="10.60.0.3")
    assert await resolve_device_by_source(db, "10.60.0.3") == device
    assert await resolve_device_by_source(db, "10.60.0.4") is None


async def test_check_community_off_never_reads_the_access_profile_at_all(db):
    """The default: no `enc` is even required when check_community is off, since it is never touched."""
    profile = await make_access_profile(db)
    device = str(await db.fetchval(
        "insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '10.60.0.5', 'switch', $1::uuid) returning id",
        profile,
    ))
    decoded = DecodedTrap(version="v2c", community="anything-at-all", trap_oid="1.3.6.1.6.3.1.1.5.3", varbinds={})
    outcome = await record_trap(db, "10.60.0.5", decoded)  # enc=None, check_community=False (the defaults)
    assert outcome.accepted is True and outcome.community_ok is True
    assert await db.fetchval("select count(*) from trap_history where device_id = $1::uuid", device) == 1


async def test_check_community_on_rejects_a_mismatch_and_stores_nothing(db):
    profile = await make_access_profile(db)
    await db.fetchval(
        "insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '10.60.0.6', 'switch', $1::uuid) returning id",
        profile,
    )
    decoded = DecodedTrap(version="v2c", community="wrong", trap_oid="1.3.6.1.6.3.1.1.5.3", varbinds={})
    outcome = await record_trap(db, "10.60.0.6", decoded, enc=EncryptionService.from_settings(), check_community=True)
    assert outcome.accepted is True and outcome.community_ok is False and outcome.known is False
    assert await db.fetchval("select count(*) from trap_history") == 0


async def test_check_community_on_accepts_the_real_community(db):
    profile = await make_access_profile(db)
    device = str(await db.fetchval(
        "insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '10.60.0.7', 'switch', $1::uuid) returning id",
        profile,
    ))
    decoded = DecodedTrap(version="v2c", community=COMMUNITY, trap_oid="1.3.6.1.6.3.1.1.5.3", varbinds={})
    outcome = await record_trap(db, "10.60.0.7", decoded, enc=EncryptionService.from_settings(), check_community=True)
    assert outcome.accepted is True and outcome.community_ok is True
    assert await db.fetchval("select count(*) from trap_history where device_id = $1::uuid", device) == 1


def test_either_the_read_or_the_write_community_matches_like_legacy():
    assert community_matches("private", "public", "private") is True
    assert community_matches("public", "public", None) is True
    assert community_matches("other", "public", "private") is False
    assert community_matches("public", None, None) is False


async def test_check_community_on_accepts_the_write_community_too(db):
    from app.repositories.access_profiles import aad

    enc = EncryptionService.from_settings()
    profile = await make_access_profile(db)
    await db.execute("update device_access_profiles set snmp_write_community_enc = $2 where id = $1::uuid",
                     profile, enc.encrypt("rw-secret", aad(str(profile), "snmp_write_community")))
    await db.execute("insert into devices (name, management_ip, device_type, access_profile_id) values ('sw1', '10.60.0.8', 'switch', $1::uuid)", profile)
    decoded = DecodedTrap(version="v2c", community="rw-secret", trap_oid="1.3.6.1.6.3.1.1.5.3", varbinds={})
    outcome = await record_trap(db, "10.60.0.8", decoded, enc=enc, check_community=True)
    assert outcome.community_ok is True and await db.fetchval("select count(*) from trap_history") == 1
