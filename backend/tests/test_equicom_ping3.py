"""Plan 38's Equicom Ping3 logic: value maps, analog scaling, row assembly, control impact and permissions. No OID is
used and no device is contacted (the repository has no Equicom MIB, K-25)."""
import re
import subprocess

import pytest

from app.access.catalogue_data import PERMISSIONS as CATALOGUE
from app.vendors import equicom_ping3 as ping3


def test_codes_are_named_and_unknown_codes_are_never_healthy():
    assert ping3.label(ping3.POWER_SENSOR_STATES, 1) == "ok"
    assert ping3.label(ping3.POWER_SENSOR_STATES, "0") == "bad"
    for raw in (2, "x", None, 1.5, True):
        assert ping3.label(ping3.POWER_SENSOR_STATES, raw).startswith("unknown")
    assert ping3.label(ping3.KNOCK_STATES, 1) == "alarm"
    assert ping3.label(ping3.POWER_OUTPUT_MODES, 3) == "controlled_by_analog_line"


def test_rows_come_from_the_name_column_and_keep_missing_values_visible():
    outputs = ping3.power_outputs({1: "Router", 2: "ONU rack"}, {1: 0, 3: 1})
    assert outputs == [ping3.PowerOutput(1, "Router", "on"), ping3.PowerOutput(2, "ONU rack", "unknown (None)")]
    lines = ping3.digital_lines({1: "Door"}, {1: 1}, {1: 0}, {1: 1})
    assert lines == [ping3.DigitalLine(1, "Door", "input", "low", "high")]
    assert ping3.power_outputs({0: "bogus", "1": "bogus"}, {}) == []


def test_analog_lines_keep_the_raw_value_beside_the_tenths():
    lines = ping3.analog_lines({1: "Battery", 2: "Temperature", 3: "Broken"}, {1: 128, 2: "231", 3: "n/a"})
    assert [(l.name, l.raw, l.value) for l in lines] == [("Battery", 128.0, 12.8), ("Temperature", 231.0, 23.1), ("Broken", None, None)]


@pytest.mark.parametrize("control, current, wanted, impact", [
    ("power_output.mode", "on", "off", "high"),
    ("power_output.mode", "on", "controlled_by_pings", "high"),
    ("power_output.mode", "off", "controlled_by_analog_line", "high"),
    ("power_output.mode", "off", "on", "normal"),
    ("power_output.mode", "on", "on", "none"),
    ("digital_line.output", "low", "high", "high"),
    ("digital_line.direction", "input", "output", "high"),
    ("analog_line.name", "Battery", "Battery 2", "normal"),
    ("power_output.name", None, "Router", "normal"),
])
def test_cutting_or_handing_off_power_and_driving_a_relay_are_high_impact(control, current, wanted, impact):
    assert ping3.control_impact(control, current, wanted) == impact


@pytest.mark.parametrize("control, wanted", [("power_output.mode", "reboot"), ("digital_line.output", "1"),
                                             ("digital_line.direction", "both"), ("relay.fire", "x")])
def test_unknown_controls_and_values_are_refused(control, wanted):
    with pytest.raises(ValueError):
        ping3.control_impact(control, None, wanted)


def test_every_control_needs_a_catalogued_permission_and_switching_power_needs_switch_mode():
    codes = {code for code, *_ in CATALOGUE}
    assert set(ping3.CONTROL_PERMISSIONS.values()) <= codes
    assert set(ping3.CONTROL_PERMISSIONS) == {"power_output.mode", "digital_line.output", "digital_line.direction",
                                              "power_output.name", "digital_line.name", "analog_line.name"}
    # Everything that changes electrical state needs switch-mode; renaming needs only configure.
    for control in ping3.CONTROL_PERMISSIONS:
        impact = ping3.control_impact(control, None, {"power_output.mode": "off", "digital_line.output": "high",
                                                      "digital_line.direction": "output"}.get(control, "x"))
        expected = "sensors.switch_mode" if impact == "high" else "sensors.configure"
        assert ping3.CONTROL_PERMISSIONS[control] == expected, control
    # Every control the impact rules know has a permission, and the other way round.
    for control in ping3.CONTROL_PERMISSIONS:
        ping3.control_impact(control, None, {"power_output.mode": "on", "digital_line.output": "high",
                                             "digital_line.direction": "input"}.get(control, "x"))


def test_the_maps_match_the_legacy_oid_file():
    try:
        legacy = subprocess.run(["git", "show", "origin/main:vendor/meklis/switcher-core/configs/oids/equicom/ping3.yml"],
                                capture_output=True, text=True, check=True).stdout
    except (subprocess.CalledProcessError, FileNotFoundError):
        pytest.skip("origin/main is not available in this test environment")

    def values(name: str) -> dict[int, str]:
        line = next(l for l in legacy.splitlines() if f"name: {name}," in l)
        return {int(k): v.strip(" '") for k, v in re.findall(r"(\d+):\s*([^,}]+)", line.split("values:", 1)[1])}

    norm = lambda m: {k: v.lower().replace(" ", "") for k, v in m.items()}  # noqa: E731
    assert norm(values("power.out.mode")) == {k: v.replace("_", "") for k, v in ping3.POWER_OUTPUT_MODES.items()}
    assert norm(values("digital.lines.direction")) == ping3.DIGITAL_DIRECTIONS
    assert norm(values("digital.lines.output")) == ping3.DIGITAL_LEVELS
    assert norm(values("knock.state")) == ping3.KNOCK_STATES
    assert norm(values("power.sensorState")) == {0: "powerisbad", 1: "ok"}
