"""The BDCOM GPON and EPON OLT profiles (Plan 14), checked offline against BDCOM's own MIB files, plus ONU identity
across an index renumber. No device is contacted; the poll below uses the fake transport."""
import re
from pathlib import Path

import pytest

from app.registry.bdcom_olt_oids import (
    EPON_PROFILE,
    FAMILY_SLUG,
    GPON_PROFILE,
    NORMALIZED,
    ONU_IDENTITY,
    ONU_ROWS,
    PROFILES,
    VENDOR_SLUG,
)
from app.registry.mib import resolve
from app.registry.oid import MAX_ROWS, Definition, Entry, profile_problems
from app.services.onu_identity import onus_by_identity
from tests.polling_helpers import ctx, make_pollable  # noqa: F401

_ROOT = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / "NMS_BDCOM_MIBS").is_dir()), None)
ALL = [(name, d) for name, defs in PROFILES.items() for d in defs]


@pytest.fixture(scope="module")
def corpora():
    if _ROOT is None:
        pytest.skip("BDCOM MIB directories are not mounted in this test environment")
    out = {}
    for directory in {d.mib_dir for _, d in ALL}:
        sources = {p.name: p.read_text(errors="ignore") for p in sorted((_ROOT / directory).iterdir()) if p.is_file()}
        out[directory] = (sources, resolve(sources))
    return out


@pytest.mark.parametrize("profile, d", ALL, ids=[d.logical_name for _, d in ALL])
def test_every_oid_matches_bdcoms_mib_is_read_only_and_has_the_right_shape(profile, d, corpora):
    sources, resolution = corpora[d.mib_dir]
    entries = {e.definition.name: e for e in resolution.files[d.mib_file].entries}
    assert d.mib_object in entries, f"{d.mib_object} is not in {d.mib_dir}/{d.mib_file}"
    entry = entries[d.mib_object]
    assert entry.numeric_oid == d.numeric_oid, (d.logical_name, entry.numeric_oid)
    clause = re.search(rf"^\s*{re.escape(d.mib_object)}\s+OBJECT-TYPE(.*?)::=", sources[d.mib_file], re.S | re.M).group(1)
    assert re.search(r"(?:MAX-)?ACCESS\s+([\w-]+)", clause).group(1) == "read-only"
    assert d.strategy == ("walk" if entry.definition.parent.endswith("Entry") else "get"), entry.definition.parent


@pytest.mark.parametrize("name", list(PROFILES))
def test_each_profile_passes_the_registry_rules_and_stays_under_the_row_cap(name):
    defs = PROFILES[name]
    assert profile_problems([Entry(Definition(d.logical_name, d.numeric_oid), d.strategy, d.max_rows, d.timeout_ms) for d in defs]) == []
    assert all(d.max_rows <= MAX_ROWS[1] for d in defs if d.strategy == "walk")
    assert len({d.numeric_oid for d in defs}) == len(defs)


def test_gpon_reads_only_gpon_objects_and_epon_only_epon_objects():
    for d in PROFILES[GPON_PROFILE]:
        assert not d.numeric_oid.startswith("1.3.6.1.4.1.3320.101."), d.logical_name
    for d in PROFILES[EPON_PROFILE]:
        assert not d.numeric_oid.startswith("1.3.6.1.4.1.3320.10."), d.logical_name


def test_the_normalization_table_names_only_real_definitions_of_the_right_technology():
    names = {GPON_PROFILE: {d.logical_name for d in PROFILES[GPON_PROFILE]}, EPON_PROFILE: {d.logical_name for d in PROFILES[EPON_PROFILE]}}
    for meaning, per_tech in NORMALIZED.items():
        assert set(per_tech) == {"gpon", "epon"}, meaning
        if per_tech["gpon"]:
            assert per_tech["gpon"] in names[GPON_PROFILE], meaning
        if per_tech["epon"]:
            assert per_tech["epon"] in names[EPON_PROFILE], meaning
    assert ONU_IDENTITY == {"gpon": "bdcom_gpon.onu.serial", "epon": "bdcom_epon.onu.mac"}


def test_the_legacy_action_names_are_not_what_they_say(corpora):
    """Recorded for Plans 26 and 38: legacy `ont.action.delete` and `ont.action.disable` are activate/enable controls."""
    _, resolution = corpora["bdcom"]
    gpon = {e.definition.name: e.numeric_oid for e in resolution.files["NMS-GPON-MIB"].entries if e.numeric_oid}
    assert gpon["gponOnuConfigActicate"] == "1.3.6.1.4.1.3320.10.3.2.1.2"  # legacy calls this ont.action.delete
    assert gpon["gponOnuConfigEnable"] == "1.3.6.1.4.1.3320.10.3.2.1.3"    # legacy calls this ont.action.disable
    assert not any(d.numeric_oid.startswith("1.3.6.1.4.1.3320.10.3.2.") for _, d in ALL)


@pytest.mark.parametrize("technology, identity_name", [("gpon", "bdcom_gpon.onu.serial"), ("epon", "bdcom_epon.onu.mac")])
def test_onu_identity_survives_an_index_renumber(technology, identity_name):
    profile = PROFILES[GPON_PROFILE if technology == "gpon" else EPON_PROFILE]
    root = {d.logical_name: d.numeric_oid for d in profile}
    distance = NORMALIZED["onu.distance"][technology]

    def poll(index_of: dict[str, int], km: dict[str, int]):
        return {
            identity_name: [(f"{root[identity_name]}.{i}", ident) for ident, i in index_of.items()],
            distance: [(f"{root[distance]}.{i}", km[ident]) for ident, i in index_of.items()],
        }

    before = onus_by_identity(technology, poll({"ONU-A": 1, "ONU-B": 2}, {"ONU-A": 100, "ONU-B": 200}))
    after = onus_by_identity(technology, poll({"ONU-A": 7, "ONU-B": 3}, {"ONU-A": 100, "ONU-B": 200}))  # renumbered
    assert before.keys() == after.keys() == {"ONU-A", "ONU-B"}
    assert before["ONU-A"]["onu.distance"] == after["ONU-A"]["onu.distance"] == 100
    assert before["ONU-B"]["onu.distance"] == after["ONU-B"]["onu.distance"] == 200


def test_rows_without_an_identity_or_with_a_duplicate_one_are_not_keyed_by_index():
    root = {d.logical_name: d.numeric_oid for d in PROFILES[EPON_PROFILE]}
    walks = {
        "bdcom_epon.onu.mac": [(f"{root['bdcom_epon.onu.mac']}.1", "aa"), (f"{root['bdcom_epon.onu.mac']}.2", "aa"), (f"{root['bdcom_epon.onu.mac']}.3", " ")],
        "bdcom_epon.onu.distance": [(f"{root['bdcom_epon.onu.distance']}.{i}", i * 10) for i in (1, 2, 3, 4)],
    }
    result = onus_by_identity("epon", walks)
    # Index 2 repeats identity "aa" and index 3 has a blank one; index 4 has no identity row at all.
    assert list(result) == ["aa"] and result["aa"]["onu.distance"] == 10
    with pytest.raises(ValueError):
        onus_by_identity("xgspon", {})


async def test_both_profiles_are_seeded_as_drafts_for_bdcom_olts(db):
    rows = await db.fetch(
        """
        select p.name, p.status, v.slug as vendor, f.slug as family, count(e.*) as entries from oid_profiles p
        join vendors v on v.id = p.vendor_id join vendor_model_families f on f.id = p.family_id
        left join oid_profile_entries e on e.profile_id = p.id where p.name = any($1::text[]) group by p.id, v.slug, f.slug
        """,
        list(PROFILES),
    )
    assert {r["name"]: (r["status"], r["vendor"], r["family"], r["entries"]) for r in rows} == {
        name: ("draft", VENDOR_SLUG, FAMILY_SLUG, len(defs)) for name, defs in PROFILES.items()
    }


async def test_a_draft_olt_profile_polls_nothing(ctx, db):
    device = await make_pollable(db, "10.98.0.1", family="bdcom-olt")
    from app.polling.engine import poll_device

    for name in PROFILES:
        outcome = await poll_device(ctx, device, name)
        assert outcome.status != "ok" and "no active profile" in (outcome.error or "")
    assert ctx.transport.calls == []


async def test_once_activated_an_epon_poll_keeps_every_walk_within_its_bounds(ctx, db):
    await db.execute("update oid_profiles set status = 'active' where name = $1", EPON_PROFILE)
    device = await make_pollable(db, "10.98.0.2", family="bdcom-olt")
    from app.polling.engine import poll_device

    outcome = await poll_device(ctx, device, EPON_PROFILE)
    assert outcome.status == "ok"
    bounds = {d.numeric_oid: (d.max_rows, d.timeout_ms) for d in PROFILES[EPON_PROFILE] if d.strategy == "walk"}
    assert {root for _, root, *_ in ctx.transport.walk_limits} == set(bounds)
    for _, root, max_rows, timeout in ctx.transport.walk_limits:
        assert (max_rows, timeout) == bounds[root] and max_rows <= ONU_ROWS
