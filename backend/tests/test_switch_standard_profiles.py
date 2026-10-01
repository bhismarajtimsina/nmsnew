"""Plan 18's vendor-neutral switch profiles (RMON errors, VLAN summary), checked offline against the RMON-MIB and
Q-BRIDGE-MIB files in the repository, and the rule that no profile anywhere walks a full FDB. No device is contacted;
the poll below uses the fake transport."""
import re
from pathlib import Path

import pytest

from app.registry import bdcom_olt_oids, bdcom_switch_oids, standard_oids
from app.registry.mib import resolve
from app.registry.oid import MAX_ROWS, Definition, Entry, profile_problems
from app.registry.switch_standard_oids import MIB_DIRECTORY, PROFILES, Q_BRIDGE_FILE, RMON_FILE, RMON_PROFILE, VLAN_PROFILE
from tests.polling_helpers import ctx, make_pollable  # noqa: F401

_ROOT = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / MIB_DIRECTORY).is_dir()), None)
ALL = [(name, d) for name, defs in PROFILES.items() for d in defs]
FULL_FDB_ROOTS = ("1.3.6.1.2.1.17.4.3", "1.3.6.1.2.1.17.7.1.2.2")  # dot1dTpFdbTable, dot1qTpFdbTable


@pytest.fixture(scope="module")
def mibs():
    if _ROOT is None:
        pytest.skip(f"{MIB_DIRECTORY} is not mounted in this test environment")
    directory = _ROOT / MIB_DIRECTORY
    texts = {p.name: p.read_text(errors="ignore") for p in sorted(directory.iterdir()) if p.is_file()}
    rmon_sources = {name: text for name, text in texts.items() if name != Q_BRIDGE_FILE}
    # The file holds two modules both named Q-BRIDGE-MIB; only the real one ({ dot1dBridge 7 }) is used (see
    # tests/test_mac_lookup.py), with RFC 4188's one line for dot1dBridge, whose BRIDGE-MIB is not in the repository.
    modules = [m + "END\n" for m in texts[Q_BRIDGE_FILE].split("\nEND") if "DEFINITIONS ::= BEGIN" in m]
    (q_bridge,) = [m for m in modules if "::= { dot1dBridge 7 }" in m]
    q_sources = {
        Q_BRIDGE_FILE: q_bridge,
        "BRIDGE-MIB-STUB": "BRIDGE-MIB-STUB DEFINITIONS ::= BEGIN\ndot1dBridge OBJECT IDENTIFIER ::= { mib-2 17 }\nEND\n",
    }
    return {RMON_FILE: (rmon_sources, resolve(rmon_sources)), Q_BRIDGE_FILE: (q_sources, resolve(q_sources))}


@pytest.mark.parametrize("profile, d", ALL, ids=[d.logical_name for _, d in ALL])
def test_every_oid_matches_its_mib_is_read_only_and_has_the_right_shape(profile, d, mibs):
    sources, resolution = mibs[d.mib_file]
    entries = {e.definition.name: e for e in resolution.files[d.mib_file].entries}
    entry = entries[d.mib_object]
    assert entry.numeric_oid == d.numeric_oid, (d.logical_name, entry.numeric_oid)
    clause = re.search(rf"^\s*{re.escape(d.mib_object)}\s+OBJECT-TYPE(.*?)::=", sources[d.mib_file], re.S | re.M).group(1)
    assert re.search(r"(?:MAX-)?ACCESS\s+([\w-]+)", clause).group(1) == "read-only"
    assert d.strategy == ("walk" if entry.definition.parent.endswith("Entry") else "get"), entry.definition.parent


def test_the_columns_left_out_are_the_writable_ones(mibs):
    """D-30: the RMON data source and the VLAN name are writable, so the registry would refuse them in a profile."""
    for file, obj in ((RMON_FILE, "etherStatsDataSource"), (Q_BRIDGE_FILE, "dot1qVlanStaticName")):
        sources, _ = mibs[file]
        clause = re.search(rf"^\s*{obj}\s+OBJECT-TYPE(.*?)::=", sources[file], re.S | re.M).group(1)
        assert re.search(r"(?:MAX-)?ACCESS\s+([\w-]+)", clause).group(1) in ("read-write", "read-create")
        assert obj not in {d.mib_object for _, d in ALL}


@pytest.mark.parametrize("name", list(PROFILES))
def test_each_profile_passes_the_registry_rules_and_stays_under_the_row_cap(name):
    defs = PROFILES[name]
    assert profile_problems([Entry(Definition(d.logical_name, d.numeric_oid), d.strategy, d.max_rows, d.timeout_ms) for d in defs]) == []
    assert all(d.max_rows <= MAX_ROWS[1] for d in defs if d.strategy == "walk")
    assert len({d.numeric_oid for d in defs}) == len(defs)


def _code_profiles():
    yield "system_basic", [d.numeric_oid for d in standard_oids.SYSTEM_DEFINITIONS]
    yield "interface_basic", [d.numeric_oid for d in standard_oids.INTERFACE_DEFINITIONS]
    yield bdcom_switch_oids.PROFILE_NAME, [d.numeric_oid for d in bdcom_switch_oids.DEFINITIONS]
    for name, defs in bdcom_olt_oids.PROFILES.items():
        yield name, [d.numeric_oid for d in defs]
    for name, defs in PROFILES.items():
        yield name, [d.numeric_oid for d in defs]


def _touches_fdb(oid: str) -> bool:
    return any(oid == root or oid.startswith(root + ".") or root.startswith(oid + ".") for root in FULL_FDB_ROOTS)


@pytest.mark.parametrize("name, oids", list(_code_profiles()), ids=[n for n, _ in _code_profiles()])
def test_no_profile_in_code_walks_a_full_fdb(name, oids):
    assert not [o for o in oids if _touches_fdb(o)]


def test_the_fdb_check_itself_catches_the_table_its_columns_and_its_parents():
    assert _touches_fdb("1.3.6.1.2.1.17.7.1.2.2.1.2") and _touches_fdb("1.3.6.1.2.1.17.4.3") and _touches_fdb("1.3.6.1.2.1.17.7.1.2")
    assert not _touches_fdb("1.3.6.1.2.1.17.7.1.4.2.1.6")


async def test_no_seeded_profile_walks_a_full_fdb(db):
    rows = await db.fetch(
        "select p.name, d.numeric_oid from oid_profile_entries e join oid_profiles p on p.id = e.profile_id "
        "join oid_definitions d on d.id = e.definition_id"
    )
    assert rows and not [(r["name"], r["numeric_oid"]) for r in rows if _touches_fdb(r["numeric_oid"])]


async def test_both_profiles_are_seeded_as_vendor_neutral_drafts(db):
    rows = await db.fetch(
        "select p.name, p.status, p.vendor_id, count(e.*) as entries from oid_profiles p "
        "left join oid_profile_entries e on e.profile_id = p.id where p.name = any($1::text[]) group by p.id",
        list(PROFILES),
    )
    assert {r["name"]: (r["status"], r["vendor_id"], r["entries"]) for r in rows} == {
        name: ("draft", None, len(defs)) for name, defs in PROFILES.items()
    }


async def test_a_draft_switch_profile_polls_nothing(ctx, db):
    from app.polling.engine import poll_device

    device = await make_pollable(db, "10.96.0.1")
    for name in PROFILES:
        outcome = await poll_device(ctx, device, name)
        assert outcome.status != "ok" and "no active profile" in (outcome.error or "")
    assert ctx.transport.calls == []


async def test_once_activated_the_vlan_summary_gets_scalars_at_instance_zero_and_bounds_its_walk(ctx, db):
    from app.polling.engine import poll_device

    await db.execute("update oid_profiles set status = 'active' where name = $1", VLAN_PROFILE)
    device = await make_pollable(db, "10.96.0.2")
    outcome = await poll_device(ctx, device, VLAN_PROFILE)
    assert outcome.status == "ok"
    gets = [oid for call in ctx.transport.calls if call[0] == "get" for oid in call[2]]
    assert sorted(gets) == sorted(d.numeric_oid + ".0" for d in PROFILES[VLAN_PROFILE] if d.strategy == "get")
    assert [(root, rows - 1) for _, root, rows, _ in ctx.transport.walk_limits] == [("1.3.6.1.2.1.17.7.1.4.2.1.6", 4096)]


async def test_once_activated_the_rmon_profile_walks_only_its_bounded_columns(ctx, db):
    from app.polling.engine import poll_device

    await db.execute("update oid_profiles set status = 'active' where name = $1", RMON_PROFILE)
    device = await make_pollable(db, "10.96.0.3")
    assert (await poll_device(ctx, device, RMON_PROFILE)).status == "ok"
    assert {(root, rows - 1) for _, root, rows, _ in ctx.transport.walk_limits} == {(d.numeric_oid, 512) for d in PROFILES[RMON_PROFILE]}
