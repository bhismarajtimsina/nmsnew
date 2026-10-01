"""The BDCOM switch profile (Plan 13), checked offline: every OID against BDCOM's own MIB files, the safety bounds the
policy requires, and the fact that a draft polls nothing. No device is contacted; the polls below use the fake
transport."""
import re
from pathlib import Path

import pytest

from app.registry.bdcom_switch_oids import (
    DEFINITIONS,
    ENTERPRISE_ROOT,
    FAMILY_SLUG,
    FORBIDDEN_ROOTS,
    MIB_DIRECTORY,
    MIN_PRIVATE_WALK_DEPTH,
    PROFILE_NAME,
    VENDOR_SLUG,
)
from app.registry.mib import resolve
from app.registry.oid import Definition, Entry, profile_problems
from tests.polling_helpers import ctx, make_pollable  # noqa: F401

_CANDIDATES = [Path(__file__).resolve().parents[2], Path("/repo")]
_ROOT = next((p for p in _CANDIDATES if (p / MIB_DIRECTORY).is_dir()), None)


@pytest.fixture(scope="module")
def mibs():
    if _ROOT is None:
        pytest.skip(f"{MIB_DIRECTORY} is not mounted in this test environment")
    sources = {p.name: p.read_text(errors="ignore") for p in sorted((_ROOT / MIB_DIRECTORY).iterdir()) if p.is_file()}
    return sources, resolve(sources)


def _object_clause(source: str, name: str) -> str:
    match = re.search(rf"^\s*{re.escape(name)}\s+OBJECT-TYPE(.*?)::=", source, re.S | re.M)
    assert match, f"{name} has no OBJECT-TYPE clause"
    return match.group(1)


@pytest.mark.parametrize("d", DEFINITIONS, ids=[d.logical_name for d in DEFINITIONS])
def test_every_oid_is_the_one_bdcoms_own_mib_assigns_and_is_read_only(d, mibs):
    sources, resolution = mibs
    assert d.mib_file in resolution.files, f"{d.mib_file} is not in {MIB_DIRECTORY}"
    entries = {e.definition.name: e for e in resolution.files[d.mib_file].entries}
    assert d.mib_object in entries, f"{d.mib_object} is not defined in {d.mib_file}"
    assert entries[d.mib_object].numeric_oid == d.numeric_oid, (d.logical_name, entries[d.mib_object].numeric_oid)
    access = re.search(r"(?:MAX-)?ACCESS\s+([\w-]+)", _object_clause(sources[d.mib_file], d.mib_object)).group(1)
    assert access == "read-only", f"{d.mib_object} is {access} in the MIB; a polling profile reads only"


@pytest.mark.parametrize("d", DEFINITIONS, ids=[d.logical_name for d in DEFINITIONS])
def test_scalars_are_read_with_get_and_table_columns_with_a_bounded_walk(d, mibs):
    _, resolution = mibs
    entries = {e.definition.name: e for e in resolution.files[d.mib_file].entries}
    parent = entries[d.mib_object].definition.parent
    is_column = parent.endswith("Entry")
    assert d.strategy == ("walk" if is_column else "get"), (d.logical_name, parent)
    if d.strategy == "walk":
        assert d.max_rows is not None and d.timeout_ms is not None


def test_the_profile_passes_the_registry_rules_and_touches_nothing_forbidden():
    entries = [Entry(Definition(d.logical_name, d.numeric_oid), d.strategy, d.max_rows, d.timeout_ms) for d in DEFINITIONS]
    assert profile_problems(entries) == []
    assert len({d.numeric_oid for d in DEFINITIONS}) == len(DEFINITIONS)
    for d in DEFINITIONS:
        for root, what in FORBIDDEN_ROOTS.items():
            assert not (d.numeric_oid + ".").startswith(root + "."), f"{d.logical_name} reads {what}"
        if d.strategy == "walk" and d.numeric_oid.startswith(ENTERPRISE_ROOT + "."):
            assert len(d.numeric_oid.split(".")) >= MIN_PRIVATE_WALK_DEPTH, f"{d.logical_name} walks too close to the enterprise root"


def test_the_legacy_stp_names_are_really_lag_objects_and_are_left_out(mibs):
    """The legacy file calls 1.3.6.1.4.1.3320.2.232.1.1.1.1.x `stp.port.*`; BDCOM's MIB says they are dot3adAgg*."""
    _, resolution = mibs
    lag = {e.definition.name: e.numeric_oid for e in resolution.files["NMS-IEEE8023-LAG-MIB.my"].entries if e.numeric_oid}
    assert lag["dot3adAggMACAddress"] == "1.3.6.1.4.1.3320.2.232.1.1.1.1.2"
    assert not any(d.numeric_oid.startswith("1.3.6.1.4.1.3320.2.232.") for d in DEFINITIONS)


async def test_the_seeded_profile_is_a_draft_for_bdcom_switches_only(db):
    row = await db.fetchrow(
        """
        select p.status, p.version, v.slug as vendor, f.slug as family, count(e.*) as entries
        from oid_profiles p join vendors v on v.id = p.vendor_id join vendor_model_families f on f.id = p.family_id
        left join oid_profile_entries e on e.profile_id = p.id
        where p.name = $1 group by p.id, v.slug, f.slug
        """,
        PROFILE_NAME,
    )
    assert dict(row) == {"status": "draft", "version": 1, "vendor": VENDOR_SLUG, "family": FAMILY_SLUG, "entries": len(DEFINITIONS)}
    stored = await db.fetch(
        """
        select d.numeric_oid, d.access, e.walk_strategy, e.max_rows from oid_profile_entries e
        join oid_definitions d on d.id = e.definition_id join oid_profiles p on p.id = e.profile_id where p.name = $1
        """,
        PROFILE_NAME,
    )
    assert {r["numeric_oid"] for r in stored} == {d.numeric_oid for d in DEFINITIONS}
    assert all(r["access"] == "read-only" for r in stored)
    assert all(r["max_rows"] is not None for r in stored if r["walk_strategy"] == "walk")


async def test_a_draft_polls_nothing(ctx, db):
    device = await make_pollable(db, "10.96.0.1")
    from app.polling.engine import poll_device

    outcome = await poll_device(ctx, device, PROFILE_NAME)
    assert outcome.status != "ok" and "no active profile" in (outcome.error or "")
    assert ctx.transport.calls == []


async def test_once_an_operator_activates_it_a_poll_stays_within_every_bound(ctx, db):
    """What activation would do, against the fake transport: one GET of scalar instances, then one bounded walk per
    column, each with the profile's own bounds, and nothing else."""
    await db.execute("update oid_profiles set status = 'active' where name = $1", PROFILE_NAME)
    device = await make_pollable(db, "10.96.0.2")
    serial = next(d for d in DEFINITIONS if d.logical_name == "bdcom.system.serial_number")
    rx = next(d for d in DEFINITIONS if d.logical_name == "bdcom.sfp.rx_power")
    ctx.transport.script_get("10.96.0.2", {serial.numeric_oid + ".0": "SN-TEST"})
    ctx.transport.script_table("10.96.0.2", rx.numeric_oid, [(f"{rx.numeric_oid}.{i}", -42) for i in range(1, 5)])
    from app.polling.engine import poll_device

    outcome = await poll_device(ctx, device, PROFILE_NAME)
    assert outcome.status == "ok"
    gets = [c for c in ctx.transport.calls if c[0] == "get"]
    walks = [c for c in ctx.transport.calls if c[0] == "walk"]
    assert len(gets) == 1 and set(gets[0][2]) == {d.numeric_oid + ".0" for d in DEFINITIONS if d.strategy == "get"}
    assert {w[2][0] for w in walks} == {d.numeric_oid for d in DEFINITIONS if d.strategy == "walk"}
    bounds = {d.numeric_oid: (d.max_rows, d.timeout_ms) for d in DEFINITIONS if d.strategy == "walk"}
    for _, root, max_rows, timeout in ctx.transport.walk_limits:
        assert (max_rows, timeout) == bounds[root]
    readings = {r.name: r.value for r in outcome.readings}
    assert readings["bdcom.system.serial_number"] == "SN-TEST" and len(readings["bdcom.sfp.rx_power"]) == 4
