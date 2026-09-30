"""The resolver module, tested against synthetic fixtures (edge cases) and the real repository MIB files (does it
actually work on real vendor text). No device is contacted: the input is text files already on disk."""
import os
from pathlib import Path

import pytest

from app.registry.mib import STANDARD_ROOTS, parse_definitions, resolve

_CANDIDATES = [Path(__file__).resolve().parents[2], Path("/repo")]
REPO_ROOT = next((p for p in _CANDIDATES if (p / "BDCOM_MIBS").is_dir()), None)


def load_dir(name: str) -> dict[str, str]:
    if REPO_ROOT is None:
        pytest.skip(f"{name} is not mounted in this test environment")
    directory = REPO_ROOT / name
    return {p.name: p.read_text(errors="ignore") for p in sorted(directory.iterdir()) if p.is_file()}


# --- synthetic fixtures: exact, controlled edge cases -------------------------------------------------------------

def test_a_definition_inside_a_comment_is_not_mistaken_for_a_real_one():
    text = "-- fooBar OBJECT-TYPE\n--   ::= { root 1 }\nrealOne OBJECT-TYPE\n    ::= { root 2 }\n"
    assert [d.name for d in parse_definitions(text)] == ["realOne"]


def test_a_commented_out_parent_clause_between_a_definition_and_its_real_one_is_ignored():
    # Without stripping comments first, a plain search for the next "::= { ... }" after the definition would find this
    # commented-out one before the real parent clause and bind the object to the wrong parent and sub-id.
    text = "realOne OBJECT-TYPE\n-- old value, keep for reference: ::= { wrongParent 99 }\n    ::= { root 2 }\n"
    defs = parse_definitions(text)
    assert len(defs) == 1 and (defs[0].parent, defs[0].sub_id) == ("root", 2)


def test_a_name_used_only_as_an_imports_symbol_is_not_mistaken_for_a_definition():
    # This is the exact bug noted in tools/bdcom-mib-oid-map.py's own docstring: IMPORTS lists MODULE-IDENTITY as a
    # symbol it imports, and a definition-name regex anchored anywhere in the line would wrongly match "IMPORTS".
    text = "IMPORTS\n    MODULE-IDENTITY, OBJECT-TYPE\n        FROM SNMPv2-SMI;\n\nrealThing OBJECT-TYPE\n    ::= { x 1 }\n"
    assert [d.name for d in parse_definitions(text)] == ["realThing"]


def test_a_syntax_field_naming_object_identifier_is_not_mistaken_for_a_definition_called_syntax():
    # A real, common OBJECT-TYPE macro body: SYNTAX is a field of the enclosing object, not a definition of its own.
    # Found against the real MIBs in this repository (BDCOM-1705.mib, RFC1213-MIB.my and others all trigger this).
    text = (
        "sysObjectID OBJECT-TYPE\n"
        "    SYNTAX  OBJECT IDENTIFIER\n"
        "    ACCESS  read-only\n"
        "    STATUS  mandatory\n"
        "    ::= { system 2 }\n"
    )
    names = [d.name for d in parse_definitions(text)]
    assert names == ["sysObjectID"]


def test_an_uppercase_first_word_before_a_keyword_is_never_treated_as_a_definition():
    text = "FanIndex OBJECT-TYPE\n    SYNTAX INTEGER\n    ::= { fanTable 1 }\n"
    assert parse_definitions(text) == []


def test_object_identifier_clause_is_recognised_as_a_definition():
    text = "myBranch OBJECT IDENTIFIER ::= { enterprises 9999 }\n"
    defs = parse_definitions(text)
    assert len(defs) == 1 and defs[0].name == "myBranch" and defs[0].parent == "enterprises" and defs[0].sub_id == 9999


def test_a_chain_across_two_files_resolves_using_the_standard_roots():
    a = "childThing OBJECT-TYPE\n    ::= { parentThing 5 }\n"
    b = "parentThing OBJECT IDENTIFIER ::= { enterprises 12345 }\n"
    result = resolve({"a.mib": a, "b.mib": b})
    assert result.by_name["parentThing"] == "1.3.6.1.4.1.12345"
    assert result.by_name["childThing"] == "1.3.6.1.4.1.12345.5"


def test_a_definition_whose_parent_is_never_defined_anywhere_is_left_unresolved():
    text = "orphan OBJECT-TYPE\n    ::= { neverDefined 1 }\n"
    result = resolve({"a.mib": text})
    assert "orphan" not in result.by_name


def test_a_circular_parent_chain_does_not_infinite_loop_and_stays_unresolved():
    text = "a OBJECT-TYPE\n    ::= { b 1 }\nb OBJECT-TYPE\n    ::= { a 1 }\n"
    result = resolve({"a.mib": text})
    assert result.by_name == {}


def test_standard_roots_are_available_without_being_defined_anywhere():
    result = resolve({"empty.mib": "-- nothing here\n"})
    assert result.by_name == {}  # the roots themselves are not reported as "objects", only things anchored to them
    assert STANDARD_ROOTS["mib-2"] == "1.3.6.1.2.1" and STANDARD_ROOTS["enterprises"] == "1.3.6.1.4.1"


def test_resolved_oids_never_carry_a_leading_dot():
    text = "thing OBJECT IDENTIFIER ::= { enterprises 1 }\n"
    result = resolve({"a.mib": text})
    assert result.by_name["thing"] == "1.3.6.1.4.1.1" and not result.by_name["thing"].startswith(".")


def test_per_file_results_only_include_that_files_own_definitions():
    a = "onlyInA OBJECT-TYPE\n    ::= { enterprises 1 }\n"
    b = "onlyInB OBJECT-TYPE\n    ::= { enterprises 2 }\n"
    result = resolve({"a.mib": a, "b.mib": b})
    assert set(result.files["a.mib"].resolved) == {"onlyInA"}
    assert set(result.files["b.mib"].resolved) == {"onlyInB"}


# --- real files: does this actually work on the real vendor MIBs in this repository --------------------------------

def test_standard_mib_2_objects_resolve_correctly_from_the_real_bdcom_mibs_directory():
    sources = load_dir("BDCOM_MIBS")
    result = resolve(sources)
    # These are well-known, independently verifiable RFC 1213 / SNMPv2-MIB values.
    assert result.by_name["sysDescr"] == "1.3.6.1.2.1.1.1"
    assert result.by_name["sysObjectID"] == "1.3.6.1.2.1.1.2"
    assert result.by_name["sysUpTime"] == "1.3.6.1.2.1.1.3"
    assert result.by_name["ifDescr"] == "1.3.6.1.2.1.2.2.1.2"
    assert result.by_name["ifTable"] == "1.3.6.1.2.1.2.2"


def test_bdcom_private_objects_resolve_under_the_real_bdcom_enterprise_number():
    sources = load_dir("NMS_BDCOM_MIBS")
    result = resolve(sources)
    private = [oid for oid in result.by_name.values() if oid.startswith("1.3.6.1.4.1.3320")]
    assert len(private) > 2000  # the great majority of ~2900 real definitions in this directory


def test_most_real_definitions_in_the_repository_mibs_resolve():
    for directory in ("BDCOM_MIBS", "NMS_BDCOM_MIBS"):
        sources = load_dir(directory)
        result = resolve(sources)
        total = sum(len(f.definitions) for f in result.files.values())
        resolved = sum(len(f.resolved) for f in result.files.values())
        assert total > 1000 and resolved / total > 0.4, (directory, total, resolved)
