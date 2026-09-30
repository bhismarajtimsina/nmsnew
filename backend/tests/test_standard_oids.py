"""Every numeric OID in app/registry/standard_oids.py, checked against the real RFC1213-MIB.my file in this repository
— re-derived fresh each run, not trusted from what is typed in the module. If the file's own text and the hardcoded
values ever disagree, this fails; nothing here contacts a device."""
from pathlib import Path

import pytest

from app.registry.mib import resolve
from app.registry.standard_oids import INTERFACE_DEFINITIONS, SOURCE_FILE, SYSTEM_DEFINITIONS

_CANDIDATES = [Path(__file__).resolve().parents[2], Path("/repo")]
_REPO_ROOT = next((p for p in _CANDIDATES if (p / "BDCOM_MIBS").is_dir()), None)

ALL_DEFINITIONS = SYSTEM_DEFINITIONS + INTERFACE_DEFINITIONS


@pytest.fixture(scope="module")
def real_resolution():
    if _REPO_ROOT is None:
        pytest.skip("BDCOM_MIBS is not mounted in this test environment")
    directory = _REPO_ROOT / "BDCOM_MIBS"
    sources = {p.name: p.read_text(errors="ignore") for p in sorted(directory.iterdir()) if p.is_file()}
    return resolve(sources)


def test_the_source_file_is_the_one_actually_present_in_the_repository(real_resolution):
    assert SOURCE_FILE in real_resolution.files


@pytest.mark.parametrize("d", ALL_DEFINITIONS, ids=[d.logical_name for d in ALL_DEFINITIONS])
def test_every_hardcoded_oid_matches_what_the_real_mib_file_assigns_that_object(d, real_resolution):
    resolved = real_resolution.files[SOURCE_FILE].resolved
    assert d.mib_object in resolved, f"{d.mib_object} does not resolve at all in {SOURCE_FILE}"
    assert resolved[d.mib_object] == d.numeric_oid, f"{d.mib_object}: module says {d.numeric_oid}, the real MIB says {resolved[d.mib_object]}"


def test_no_two_standard_definitions_share_a_logical_name_or_a_numeric_oid():
    names = [d.logical_name for d in ALL_DEFINITIONS]
    oids = [d.numeric_oid for d in ALL_DEFINITIONS]
    assert len(names) == len(set(names)) and len(oids) == len(set(oids))


def test_interface_columns_are_all_genuinely_columns_of_the_one_real_interface_table(real_resolution):
    resolved = real_resolution.files[SOURCE_FILE].resolved
    if_entry = resolved["ifEntry"]
    for d in INTERFACE_DEFINITIONS:
        assert d.numeric_oid.startswith(if_entry + "."), (d.logical_name, d.numeric_oid, if_entry)


def test_the_ambiguous_merged_corpus_lookup_would_have_given_a_different_wrong_answer_for_two_of_these():
    """Documents exactly why standard_oids.py resolves from one named file: BDCOM's own private MIBs reuse "ifIndex"
    and "ifOutOctets" for unrelated table columns, so the merged, cross-file name map cannot be trusted for these."""
    if _REPO_ROOT is None:
        pytest.skip("BDCOM_MIBS is not mounted in this test environment")
    directory = _REPO_ROOT / "BDCOM_MIBS"
    sources = {p.name: p.read_text(errors="ignore") for p in sorted(directory.iterdir()) if p.is_file()}
    merged = resolve(sources)
    assert merged.by_name.get("ifIndex") != "1.3.6.1.2.1.2.2.1.1"  # the merged map picks up a private BDCOM definition instead
    assert merged.by_name.get("ifOutOctets") is None                # and this one does not resolve there at all
