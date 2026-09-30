import asyncpg
import pytest

from app.registry.oid import Definition, Entry, definition_problems, entry_problems, profile_problems
from tests.helpers import bearer, make_user

CHECK = (asyncpg.CheckViolationError,)


async def make_definition(db, name="if_in_octets", oid="1.3.6.1.2.1.2.2.1.10", access="read-only", safety="safe", vendor=None):
    return await db.fetchval(
        """
        insert into oid_definitions (vendor_id, logical_name, numeric_oid, module, access, safety_level, source_note)
        values ($1, $2, $3, 'interfaces', $4, $5, 'test') returning id
        """,
        vendor, name, oid, access, safety,
    )


async def make_profile(db, name="switch_basic", version=1, status="draft"):
    return await db.fetchval("insert into oid_profiles (name, version, status) values ($1, $2, $3) returning id", name, version, status)


async def add_entry(db, profile, definition, strategy="get", rows=None, timeout=None):
    await db.execute(
        "insert into oid_profile_entries (profile_id, definition_id, walk_strategy, max_rows, timeout_ms) values ($1, $2, $3, $4, $5)",
        profile, definition, strategy, rows, timeout,
    )


# (access, safety, strategy, max_rows, timeout_ms)
ENTRY_CASES = [
    ("read-only", "safe", "get", None, None),
    ("read-only", "safe", "getnext", None, None),
    ("read-only", "bounded", "walk", 500, 5000),
    ("read-only", "safe", "bulkwalk", 5000, 30000),
    ("not-accessible", "safe", "get", None, None),
    ("read-only", "safe", "walk", None, 5000),      # no row limit
    ("read-only", "safe", "walk", 500, None),       # no timeout
    ("read-only", "safe", "bulkwalk", None, None),
    ("read-write", "dangerous", "get", None, None),  # writable
    ("write-only", "dangerous", "get", None, None),
    ("read-create", "dangerous", "get", None, None),
    ("read-only", "dangerous", "get", None, None),   # read-only but flagged dangerous
    ("read-only", "safe", "walk", 0, 5000),
    ("read-only", "safe", "walk", 5001, 5000),
    ("read-only", "safe", "walk", 500, 100),
    ("read-only", "safe", "walk", 500, 30001),
    ("read-only", "safe", "sweep", None, None),      # unknown strategy
]


@pytest.mark.parametrize("access,safety,strategy,rows,timeout", ENTRY_CASES)
async def test_python_validator_and_database_agree_on_every_entry(db, access, safety, strategy, rows, timeout):
    """The database triggers and the Python validator are two implementations of one rule. They must never disagree."""
    definition = await make_definition(db, access=access, safety=safety)
    profile = await make_profile(db)
    try:
        await add_entry(db, profile, definition, strategy, rows, timeout)
        database_accepts = True
    except (asyncpg.CheckViolationError,):
        database_accepts = False
    python_accepts = not entry_problems(Entry(Definition("x", "1.3.6", access, safety), strategy, rows, timeout))
    assert database_accepts == python_accepts, (access, safety, strategy, rows, timeout)


DEFINITION_CASES = [
    ("1.3.6.1.2.1.1.1.0", "read-only", "safe", True),
    ("1.3.6.1.4.1.3320.1", "read-write", "dangerous", True),   # recorded so it is known, and blocked from polling
    ("1.3.6.1.4.1.3320.1", "read-write", "safe", False),       # writable but not marked dangerous
    ("1.3.6.1.4.1.3320.1", "write-only", "bounded", False),
    ("sysDescr.0", "read-only", "safe", False),                # not dotted decimal
    ("1.3.6.", "read-only", "safe", False),
    ("1", "read-only", "safe", False),
    ("1.3.6.1.x", "read-only", "safe", False),
]


@pytest.mark.parametrize("oid,access,safety,valid", DEFINITION_CASES)
async def test_python_validator_and_database_agree_on_every_definition(db, oid, access, safety, valid):
    try:
        await make_definition(db, oid=oid, access=access, safety=safety)
        database_accepts = True
    except CHECK:
        database_accepts = False
    python_accepts = not definition_problems(Definition("x", oid, access, safety))
    assert database_accepts == python_accepts == valid


async def test_a_writable_oid_is_recorded_but_can_never_be_polled(db):
    reset = await make_definition(db, "sys_reboot", "1.3.6.1.4.1.3320.9.9.1", "read-write", "dangerous")
    profile = await make_profile(db)
    with pytest.raises(asyncpg.CheckViolationError) as raised:
        await add_entry(db, profile, reset)
    assert "cannot be part of a polling profile" in str(raised.value) and "sys_reboot" in str(raised.value)


async def test_an_oid_already_in_a_profile_cannot_later_be_made_writable(db):
    definition = await make_definition(db)
    await add_entry(db, await make_profile(db), definition)
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update oid_definitions set access = 'read-write', safety_level = 'dangerous' where id = $1", definition)


async def test_an_active_profile_is_immutable_and_versions_replace_it(db):
    first, second = await make_definition(db, "a", "1.3.6.1.2.1.1.1.0"), await make_definition(db, "b", "1.3.6.1.2.1.1.5.0")
    v1 = await make_profile(db, "core", 1)
    with pytest.raises(asyncpg.CheckViolationError):
        await db.execute("update oid_profiles set status = 'active' where id = $1", v1)  # no entries yet
    await add_entry(db, v1, first)
    await db.execute("update oid_profiles set status = 'active' where id = $1", v1)
    assert await db.fetchval("select activated_at is not null from oid_profiles where id = $1", v1)
    for statement in (
        lambda: add_entry(db, v1, second),
        lambda: db.execute("update oid_profile_entries set position = 5 where profile_id = $1", v1),
        lambda: db.execute("delete from oid_profile_entries where profile_id = $1", v1),
        lambda: db.execute("update oid_profiles set name = 'renamed' where id = $1", v1),
        lambda: db.execute("update oid_profiles set version = 9 where id = $1", v1),
    ):
        with pytest.raises((asyncpg.CheckViolationError,)):
            await statement()
    v2 = await make_profile(db, "core", 2)
    await add_entry(db, v2, first)
    await add_entry(db, v2, second)
    with pytest.raises(asyncpg.UniqueViolationError):
        await db.execute("update oid_profiles set status = 'active' where id = $1", v2)  # only one active version per name
    await db.execute("update oid_profiles set status = 'retired' where id = $1", v1)
    await db.execute("update oid_profiles set status = 'active' where id = $1", v2)
    assert await db.fetchval("select count(*) from oid_profiles where name = 'core' and status = 'active'") == 1


async def test_a_scale_transform_needs_a_physical_range_and_a_fixture(db):
    bad = [
        ("s1", "scale", 0.01, None, None, "fx"),        # no range
        ("s2", "scale", 0.01, -50, 10, None),           # no fixture
        ("s3", "scale", None, -50, 10, "fx"),           # no factor
        ("s4", "scale", 0, -50, 10, "fx"),              # zero factor
        ("s5", "scale", 0.01, 10, -50, "fx"),           # inverted range
    ]
    for name, kind, factor, low, high, fixture in bad:
        with pytest.raises(CHECK):
            await db.execute(
                "insert into oid_transform_rules (name, kind, factor, valid_min, valid_max, fixture_ref) values ($1, $2, $3, $4, $5, $6)",
                name, kind, factor, low, high, fixture)
    await db.execute(
        "insert into oid_transform_rules (name, kind, factor, unit, valid_min, valid_max, fixture_ref) "
        "values ('centi_dbm', 'scale', 0.01, 'dBm', -60, 10, 'fixtures/zte/c300/onu_rx.json')")
    assert await db.fetchval("select count(*) from oid_transform_rules") == 1


async def test_logical_names_are_unique_per_vendor_including_the_vendor_neutral_ones(db):
    bdcom = await db.fetchval("select id from vendors where slug = 'bdcom'")
    zte = await db.fetchval("select id from vendors where slug = 'zte'")
    await make_definition(db, "onu_rx", "1.3.6.1.4.1.1.1", vendor=bdcom)
    await make_definition(db, "onu_rx", "1.3.6.1.4.1.2.2", vendor=zte)          # same name, different vendor: fine
    with pytest.raises(asyncpg.UniqueViolationError):
        await make_definition(db, "onu_rx", "1.3.6.1.4.1.3.3", vendor=bdcom)
    await make_definition(db, "sys_descr", "1.3.6.1.2.1.1.1.0")                 # vendor-neutral
    with pytest.raises(asyncpg.UniqueViolationError):
        await make_definition(db, "sys_descr", "1.3.6.1.2.1.1.1.0")


def test_a_whole_profile_is_checked_for_duplicates_and_emptiness():
    ok = Entry(Definition("a", "1.3.6.1"), "get")
    assert profile_problems([ok]) == []
    assert profile_problems([]) == ["a profile needs at least one entry"]
    assert "a: listed twice" in profile_problems([ok, ok])
    bad = Entry(Definition("w", "1.3.6.2", "read-write", "dangerous"), "get")
    assert any("writable or dangerous" in p for p in profile_problems([ok, bad]))


async def test_profiles_are_readable_through_the_api_with_their_bounds(app_client, db):
    await make_user(db, "support", "ISP Support")
    await make_user(db, "reseller", "Reseller Admin")
    definition = await make_definition(db, "if_descr", "1.3.6.1.2.1.2.2.1.2")
    profile = await make_profile(db, "switch_basic")
    await add_entry(db, profile, definition, "walk", 512, 4000)
    support = await bearer(app_client, "support")
    reseller = await bearer(app_client, "reseller")
    app_client.cookies.clear()
    listing = (await app_client.get("/api/v1/oid-profiles", headers=support)).json()
    mine = next(p for p in listing if p["name"] == "switch_basic")
    assert mine["entries"] == 1 and mine["status"] == "draft"
    assert {"system_basic", "interface_basic"} <= {p["name"] for p in listing}  # the real, always-present standard profiles
    detail = (await app_client.get(f"/api/v1/oid-profiles/{profile}", headers=support)).json()
    entry = detail["entries"][0]
    assert (entry["logical_name"], entry["walk_strategy"], entry["max_rows"], entry["timeout_ms"]) == ("if_descr", "walk", 512, 4000)
    assert (await app_client.get("/api/v1/oid-profiles", headers=reseller)).status_code == 403
    assert (await app_client.get("/api/v1/oid-profiles/not-a-uuid", headers=support)).status_code == 404
    assert (await app_client.get("/api/v1/oid-profiles/00000000-0000-0000-0000-000000000000", headers=support)).status_code == 404
