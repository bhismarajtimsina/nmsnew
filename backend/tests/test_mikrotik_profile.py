"""The MikroTik RouterOS profile (Plan 19), checked offline against MikroTik's own MIKROTIK-MIB in MIKROTIK_MIBS/. No
device is contacted; polls use the fake transport."""
import re
from pathlib import Path

import pytest

from app.registry.mib import resolve
from app.registry.mikrotik_oids import (
    DEFINITIONS,
    FAMILY_SLUG,
    MIB_DIRECTORY,
    MIB_FILE,
    PROFILE_NAME,
    VENDOR_SLUG,
    scale,
)
from app.registry.oid import MAX_ROWS, Definition, Entry, profile_problems
from tests.polling_helpers import ctx, make_pollable  # noqa: F401

_REPO = next((p for p in (Path(__file__).resolve().parents[2], Path("/repo")) if (p / MIB_DIRECTORY).is_dir()), None)
# The standard modules MIKROTIK-MIB imports, from the copies already in the repository.
_IMPORTS = ["SNMPv2-SMI.my", "SNMPv2-TC.my", "SNMPv2-CONF.my", "INET-ADDRESS-MIB-rfc4001.MIB"]
WRITABLE = {"mtxrSystemReboot", "mtxrUSBPowerReset"}


@pytest.fixture(scope="module")
def mib():
    if _REPO is None:
        pytest.skip(f"{MIB_DIRECTORY} is not mounted in this test environment")
    sources = {name: (_REPO / "BDCOM_MIBS" / name).read_text(errors="ignore") for name in _IMPORTS}
    sources[MIB_FILE] = (_REPO / MIB_DIRECTORY / MIB_FILE).read_text(errors="ignore")
    return sources, resolve(sources)


def _clause(text: str, obj: str) -> str:
    return re.search(rf"^\s*{re.escape(obj)}\s+OBJECT-TYPE(.*?)::=", text, re.S | re.M).group(1)


@pytest.mark.parametrize("d", DEFINITIONS, ids=lambda d: d.logical_name)
def test_every_oid_matches_mikrotiks_mib_is_read_only_and_has_the_right_shape(d, mib):
    sources, resolution = mib
    entry = {e.definition.name: e for e in resolution.files[MIB_FILE].entries}[d.mib_object]
    assert entry.numeric_oid == d.numeric_oid, (d.logical_name, entry.numeric_oid)
    assert re.search(r"(?:MAX-)?ACCESS\s+([\w-]+)", _clause(sources[MIB_FILE], d.mib_object)).group(1) == "read-only"
    assert d.strategy == ("walk" if entry.definition.parent.endswith("Entry") else "get"), entry.definition.parent


@pytest.mark.parametrize("d", DEFINITIONS, ids=lambda d: d.logical_name)
def test_every_scale_comes_from_the_objects_own_textual_convention(d, mib):
    sources, _ = mib
    syntax = re.search(r"SYNTAX\s+(\w+)", _clause(sources[MIB_FILE], d.mib_object)).group(1)
    hint = re.search(rf"^{syntax}\s*::=\s*TEXTUAL-CONVENTION(.*?)SYNTAX", sources[MIB_FILE], re.S | re.M)
    decimals = re.search(r'DISPLAY-HINT\s+"d-(\d)"', hint.group(1)) if hint else None
    assert d.divisor == (10 ** int(decimals.group(1)) if decimals else 1), (d.mib_object, syntax)


def test_the_writable_actions_are_in_the_mib_and_never_in_the_profile(mib):
    sources, _ = mib
    for obj in WRITABLE:
        assert re.search(r"(?:MAX-)?ACCESS\s+([\w-]+)", _clause(sources[MIB_FILE], obj)).group(1) == "read-write"
    assert not WRITABLE & {d.mib_object for d in DEFINITIONS}
    assert not any(d.numeric_oid.startswith("1.3.6.1.4.1.14988.1.1.7.1") for d in DEFINITIONS)


def test_the_profile_passes_the_registry_rules_and_every_walk_is_bounded():
    entries = [Entry(Definition(d.logical_name, d.numeric_oid), d.strategy, d.max_rows, d.timeout_ms) for d in DEFINITIONS]
    assert profile_problems(entries) == []
    assert all(d.max_rows and d.max_rows <= MAX_ROWS[1] for d in DEFINITIONS if d.strategy == "walk")
    assert len({d.numeric_oid for d in DEFINITIONS}) == len(DEFINITIONS)


@pytest.mark.parametrize("name, raw, expected", [
    ("mikrotik.health.voltage", 241, 24.1), ("mikrotik.health.cpu_temperature", -55, -5.5),
    ("mikrotik.optical.rx_power", -7234, -7.234), ("mikrotik.optical.supply_voltage", 3301, 3.301),
    ("mikrotik.optical.temperature", 41, 41), ("mikrotik.dhcp.lease_count", 812, 812),
    ("mikrotik.health.voltage", None, None), ("mikrotik.health.power_supply_ok", True, None),
])
def test_readings_scale_by_the_mibs_conventions(name, raw, expected):
    assert scale(name, raw) == expected


async def test_it_is_seeded_as_a_mikrotik_draft_and_a_draft_polls_nothing(ctx, db):
    from app.polling.engine import poll_device

    row = await db.fetchrow(
        "select p.status, v.slug as vendor, f.slug as family, count(e.*) as n from oid_profiles p "
        "join vendors v on v.id = p.vendor_id join vendor_model_families f on f.id = p.family_id "
        "left join oid_profile_entries e on e.profile_id = p.id where p.name = $1 group by p.id, v.slug, f.slug", PROFILE_NAME)
    assert (row["status"], row["vendor"], row["family"], row["n"]) == ("draft", VENDOR_SLUG, FAMILY_SLUG, len(DEFINITIONS))
    device = await make_pollable(db, "10.93.0.1", vendor=VENDOR_SLUG, family=FAMILY_SLUG)
    outcome = await poll_device(ctx, device, PROFILE_NAME)
    assert outcome.status != "ok" and ctx.transport.calls == []


async def test_once_activated_scalars_are_one_get_and_every_walk_keeps_its_bound(ctx, db):
    from app.polling.engine import poll_device

    await db.execute("update oid_profiles set status = 'active' where name = $1", PROFILE_NAME)
    device = await make_pollable(db, "10.93.0.2", vendor=VENDOR_SLUG, family=FAMILY_SLUG)
    assert (await poll_device(ctx, device, PROFILE_NAME)).status == "ok"
    gets = [c for c in ctx.transport.calls if c[0] == "get"]
    assert len(gets) == 1 and set(gets[0][2]) == {d.numeric_oid + ".0" for d in DEFINITIONS if d.strategy == "get"}
    bounds = {d.numeric_oid: d.max_rows for d in DEFINITIONS if d.strategy == "walk"}
    assert {root: rows - 1 for _, root, rows, _ in ctx.transport.walk_limits} == bounds  # the cap plus one probe row
