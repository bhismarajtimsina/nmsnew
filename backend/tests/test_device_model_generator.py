"""Unit tests for tools/gen_device_model_catalogue.py's own parser, run directly against synthetic YAML fixtures (not the
real vendor files — that parity is test_device_model_catalogue.py's job). This is what would have caught, before ever
touching the generated data, the real bug found while building this: a `\\d{3,4}`-style quantifier's comma breaking a
naive split on every comma in the `detect: {...}` block."""
import sys
from pathlib import Path

import pytest

# The test container only mounts backend/, not the repository root; tools/test-backend.sh and CI also mount/check out
# tools/ itself. Same situation as docker-compose.cybersathy.yml in test_deployment.py.
_CANDIDATES = [Path(__file__).resolve().parents[2] / "tools", Path("/repo/tools")]
_tools_dir = next((p for p in _CANDIDATES if (p / "gen_device_model_catalogue.py").is_file()), None)
if _tools_dir is None:
    pytest.skip("tools/ is not mounted in this test environment", allow_module_level=True)
sys.path.insert(0, str(_tools_dir))
import gen_device_model_catalogue as gen  # noqa: E402


def write(tmp_path, text):
    path = tmp_path / "Fixture.yml"
    path.write_text(text)
    return path


def test_a_quantifier_comma_does_not_split_the_detect_block(tmp_path):
    path = write(tmp_path, """models:
  - name: Fixture Wide
    key: fixture_wide
    detect: {description: '^Fixture.*\\bW\\d{3,4}', objid: '^1.2.3.4'}
    device_type: SWITCH
""")
    entries = gen.parse_file(path)
    assert entries == [{
        "name": "Fixture Wide", "key": "fixture_wide", "device_type": "switch",
        "sysdescr_pattern": r"^Fixture.*\bW\d{3,4}", "sysobjectid_pattern": "^1.2.3.4",
    }]


def test_a_detect_block_missing_a_field_is_refused_not_silently_partial(tmp_path):
    path = write(tmp_path, """models:
  - name: Fixture Broken
    key: fixture_broken
    detect: {description: '^Fixture'}
    device_type: SWITCH
""")
    with pytest.raises(gen.SourceError):
        gen.parse_file(path)


def test_an_entry_missing_key_or_device_type_is_refused(tmp_path):
    for body in (
        "models:\n  - name: No Key\n    detect: {description: 'x', objid: 'y'}\n    device_type: SWITCH\n",
        "models:\n  - name: No Type\n    key: no_type\n    detect: {description: 'x', objid: 'y'}\n",
    ):
        with pytest.raises(gen.SourceError):
            gen.parse_file(write(tmp_path, body))


def test_an_unknown_device_type_is_refused(tmp_path):
    path = write(tmp_path, "models:\n  - name: Odd\n    key: odd\n    detect: {description: 'x', objid: 'y'}\n    device_type: ROUTER\n")
    with pytest.raises(gen.SourceError):
        gen.parse_file(path)


def test_multiple_entries_in_one_file_are_all_found(tmp_path):
    path = write(tmp_path, """models:
  - name: First
    key: first
    detect: {description: 'A', objid: '1'}
    device_type: OLT
  - name: Second
    key: second
    detect: {description: 'B', objid: '2'}
    device_type: SWITCH
""")
    entries = gen.parse_file(path)
    assert [e["key"] for e in entries] == ["first", "second"]
    assert [e["device_type"] for e in entries] == ["olt", "switch"]


def test_unquoted_and_double_quoted_values_are_both_read():
    assert gen._split_detect("description: .*GP3600,  objid: ^.1.3.6.1.4.1.3320") == {
        "description": ".*GP3600", "objid": "^.1.3.6.1.4.1.3320"}
    assert gen._split_detect('description: "^Foo.*", objid: "^1.2.3"') == {"description": "^Foo.*", "objid": "^1.2.3"}
