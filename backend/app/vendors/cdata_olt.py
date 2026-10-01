"""C-Data FD-series OLT logic for Plan 17 that does not depend on an unverified OID: optical conversion through a
per-family override table, and down-reason normalization. Ported from legacy switcher-core
(`Modules/CData/OntOpticalInfo.php`, `OntOpticalInfoFD16.php`, `FD11XX/OntOpticalInfo.php`,
`FD16xxV3/OntOpticalInfoFD16.php`, `FD17xxV3/OntOpticalInfoFD17.php`) and its value maps (`configs/oids/cdata/*.yml`),
read from `origin/main` without merging it.

There is no C-Data polling profile: the repository has no C-Data MIB (K-25).

C-Data's firmware families disagree on units, which is the per-model override table Plan 17 asks for:
- voltage is value / 10000 on FD11xx, value / 100000 on FD12xx and FD16xx, value / 100 on FD17xx;
- FD11xx reports power in 0.1 uW (dBm = 10 log10(value) - 40), the others in 0.01 dBm.

Legacy defect fixed rather than copied: FD17xx's OLT-rx checks (`olt_rx == -0.01`, `olt_rx < -100`) blank `rx`, the
ONU's own receive power, and leave the bad OLT-rx value on screen. Here each check blanks the field it tested.

Not verified against a device.
"""
from __future__ import annotations

import math
from typing import Callable

from app.vendors.common import TextReason, lookup_text_reason

# model key (app/vendors/capabilities.py) -> optical family, from the module each legacy model uses for ONT optical.
FAMILY_BY_MODEL = {
    "c_data_fd1104sn": "fd11xx", "c_data_fd1108s": "fd11xx",
    "c_data_fd1204sn": "fd12xx", "c_data_fd1208s": "fd12xx", "c_data_fd1216s_r1": "fd12xx",
    "c_data_fd1601": "fd16xx", "c_data_fd1604": "fd16xx", "c_data_fd1608": "fd16xx", "c_data_fd1616": "fd16xx",
    "c_data_fd1601_fw3": "fd16xx", "c_data_fd1604_fw3": "fd16xx", "c_data_fd1608_fw3": "fd16xx",
    "c_data_fd1616_fw3": "fd16xx",
    "c_data_fd1700s_fw3": "fd17xx",
}


def _hundredths(raw: float) -> float:
    return round(raw / 100, 2)


def _microwatt_tenths(raw: float) -> float | None:
    """FD11xx power: legacy tests `(int)value`, so anything below 1 (0, a fraction, or a negative value where log10 is
    undefined) is no reading."""
    return round(10 * math.log10(raw) - 40, 2) if raw >= 1 else None


def _divide(by: int) -> Callable[[float], float]:
    return lambda raw: round(raw / by, 2)


# family -> field -> conversion. A field a family does not report is absent (FD11xx has no ONT temperature, only
# FD16xx and FD17xx report the OLT's receive power per ONU).
OPTICAL_OVERRIDES: dict[str, dict[str, Callable[[float], float | None]]] = {
    "fd11xx": {"rx": _microwatt_tenths, "tx": _microwatt_tenths, "voltage": _divide(10000)},
    "fd12xx": {"rx": _hundredths, "tx": _hundredths, "temp": _hundredths, "voltage": _divide(100000)},
    "fd16xx": {"rx": _hundredths, "olt_rx": _hundredths, "tx": _hundredths, "temp": _hundredths,
               "voltage": _divide(100000)},
    "fd17xx": {"rx": _hundredths, "olt_rx": _hundredths, "tx": _hundredths, "temp": _hundredths, "voltage": _divide(100)},
}

# FD17xx's own "no reading" values, per field (legacy getPretty), each applied to the field it tests.
_FD17_NO_READING: dict[str, Callable[[float], bool]] = {
    "rx": lambda v: v in (-0.01, -40.0) or v < -100,
    "olt_rx": lambda v: v in (-0.01, 0.0) or v < -100,
    "tx": lambda v: v == -40.0 or v < -100,
    "voltage": lambda v: v == 0.0,
    "temp": lambda v: v == -99.0,
}


def supported_fields(model_key: str) -> frozenset[str]:
    return frozenset(OPTICAL_OVERRIDES[FAMILY_BY_MODEL[model_key]])


def scale_optical(model_key: str, field: str, raw: float | int | None) -> float | None:
    """A raw C-Data ONT optical reading in display units for this model. Raises for a field the model does not
    report (the caller shows that field as unsupported) and for an unknown model."""
    family = FAMILY_BY_MODEL[model_key]
    convert = OPTICAL_OVERRIDES[family].get(field)
    if convert is None:
        raise ValueError(f"{model_key} does not report {field!r}")
    if raw is None or isinstance(raw, bool):
        return None
    value = convert(float(raw))
    if value is not None and family == "fd17xx" and _FD17_NO_READING[field](value):
        return None
    return value


def scale_distance(model_key: str, raw: int | None) -> int | None:
    """Metres. FD17xx reports 0 for "no distance"; the other families pass the value through, as legacy does."""
    family = FAMILY_BY_MODEL[model_key]
    if raw is None or isinstance(raw, bool):
        return None
    if family == "fd17xx" and int(raw) == 0:
        return None
    return int(raw)


# Down reasons arrive as text. Per table, exactly as the legacy value maps word them, then the common reason.
DOWN_REASON_TABLES: dict[str, dict[str, tuple[str, str]]] = {
    "gpon.last_down_reason": {
        "--": ("Unknown", "unknown"), "": ("Unknown", "unknown"), "dying-gasp": ("PowerOff", "power_off"),
        "LOS": ("LOS", "los"), "losi": ("LOSi", "signal_failure"),
    },
    # Legacy's EPON map labels "losi" as LOS; the label is kept, the meaning follows the GPON table.
    "epon.last_down_reason": {
        "": ("Unknown", "unknown"), "dying-gasp": ("PowerOff", "power_off"), "losi": ("LOS", "signal_failure"),
    },
}


def normalize_down_reason(table: str, text: str | None) -> TextReason | None:
    return lookup_text_reason(DOWN_REASON_TABLES[table], text)
