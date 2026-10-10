"""Equicom Ping3 sensor device logic for Plan 38 that does not depend on an unverified OID: the value maps, the analog
scaling, row assembly, and which controls are dangerous. Ported from legacy switcher-core (`Modules/Sensors/Ping3/*`)
and its value maps (`configs/oids/equicom/ping3.yml`), read from `origin/main` without merging it.

There is no Ping3 polling profile and no Ping3 action driver: the repository has no Equicom MIB (enterprise 35160,
K-25), so neither the OIDs nor their writable values can be checked offline. They wait for the MIB or an operator
fixture (safety policy sections 2 and 3).

Kept visible, not guessed away:
- Analog lines: legacy divides the raw value by 10 and names no unit. The scaled value is kept with the raw one, and
  the unit stays unknown.
- Legacy's `sensors_low_battery_level` alarm compares `device_sensor{name="Battery"}` with 44. Whether that series
  carries the raw value (4.4 after scaling) or the scaled one (44) is not knowable from the code; see Plan 38.
- An unlisted code is unknown, never a healthy state.

Not verified against a device.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Literal

from app.vendors.common import parse_number

# Value maps from legacy ping3.yml, by name.
POWER_OUTPUT_MODES = {0: "on", 1: "off", 2: "controlled_by_pings", 3: "controlled_by_analog_line"}
DIGITAL_DIRECTIONS = {0: "output", 1: "input"}
DIGITAL_LEVELS = {0: "low", 1: "high"}
KNOCK_STATES = {0: "ok", 1: "alarm"}
POWER_SENSOR_STATES = {0: "bad", 1: "ok"}
ANALOG_SCALE = 10  # legacy AnalogLinesList: getParsedValue() / 10


def _code(raw: Any) -> int | None:
    number = parse_number(raw)
    return int(number) if number is not None and number == int(number) else None


def label(mapping: dict[int, str], raw: Any) -> str:
    """The name for a code, or `unknown (<raw>)`: never a guessed healthy state."""
    code = _code(raw)
    return mapping[code] if code in mapping else f"unknown ({raw})"


@dataclass(frozen=True)
class PowerOutput:
    index: int
    name: str
    mode: str


@dataclass(frozen=True)
class DigitalLine:
    index: int
    name: str
    direction: str
    output: str
    value: str


@dataclass(frozen=True)
class AnalogLine:
    index: int
    name: str
    raw: float | None
    value: float | None  # raw / 10, unit unknown


def _rows(columns: dict[str, dict[int, Any]], key: str) -> list[int]:
    """Row indexes come from the name column, as in legacy: a row without a name is not a line."""
    return sorted(i for i in columns.get(key, {}) if isinstance(i, int) and i > 0)


def power_outputs(names: dict[int, Any], modes: dict[int, Any]) -> list[PowerOutput]:
    return [PowerOutput(i, str(names[i]), label(POWER_OUTPUT_MODES, modes.get(i))) for i in _rows({"n": names}, "n")]


def digital_lines(names: dict[int, Any], directions: dict[int, Any], outputs: dict[int, Any], values: dict[int, Any]) -> list[DigitalLine]:
    return [DigitalLine(i, str(names[i]), label(DIGITAL_DIRECTIONS, directions.get(i)), label(DIGITAL_LEVELS, outputs.get(i)),
                        label(DIGITAL_LEVELS, values.get(i)))
            for i in _rows({"n": names}, "n")]


def analog_lines(names: dict[int, Any], values: dict[int, Any]) -> list[AnalogLine]:
    out = []
    for i in _rows({"n": names}, "n"):
        raw = parse_number(values.get(i))
        out.append(AnalogLine(i, str(names[i]), raw, round(raw / ANALOG_SCALE, 3) if raw is not None else None))
    return out


Impact = Literal["none", "normal", "high"]


def control_impact(control: str, current: str | None, wanted: str) -> Impact:
    """How dangerous a Ping3 control is, for the confirmation flow (Plan 26): `high` needs a typed acknowledgement,
    `normal` a confirmation, `none` means nothing would change.

    - A power output feeds whatever is plugged into it. Turning it off cuts that equipment's power. Handing it to the
      ping watchdog or an analog line lets the device cut power on its own later. Both are high impact; turning an
      output on is normal.
    - A digital line set as an output drives a relay or a contact, so changing its level or direction is high impact.
    - Renaming a line or output changes no electrical state: normal.
    """
    if current is not None and current == wanted:
        return "none"
    if control == "power_output.mode":
        if wanted not in POWER_OUTPUT_MODES.values():
            raise ValueError(f"unknown power output mode: {wanted}")
        return "normal" if wanted == "on" else "high"
    if control == "digital_line.output":
        if wanted not in DIGITAL_LEVELS.values():
            raise ValueError(f"unknown digital level: {wanted}")
        return "high"
    if control == "digital_line.direction":
        if wanted not in DIGITAL_DIRECTIONS.values():
            raise ValueError(f"unknown digital direction: {wanted}")
        return "high"
    if control in ("power_output.name", "digital_line.name", "analog_line.name"):
        return "normal"
    raise ValueError(f"unknown control: {control}")


# Legacy permission keys and what each grants here. In legacy, `sensor_devices_configure`'s route pattern
# (`^(PUT):/<id>/.*$`) also matches the toggle route, so configure silently implies switch-mode; here each control
# needs its own permission.
CONTROL_PERMISSIONS = {
    "power_output.mode": "sensors.switch_mode",
    "digital_line.output": "sensors.switch_mode",
    "digital_line.direction": "sensors.switch_mode",  # turning an input into an output can drive what is wired to it
    "power_output.name": "sensors.configure",
    "digital_line.name": "sensors.configure",
    "analog_line.name": "sensors.configure",
}
