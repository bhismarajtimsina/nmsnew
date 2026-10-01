"""Per-model OLT capabilities and the explicit `unsupported` state (Plan 17).

Which models can report what is taken from the legacy model files (`configs/models/C-Data.yml`, `VSolution.yml`,
`gcom.yml`): a model whose legacy definition has no module for a capability does not have it. Asking for it gives a
result in the `unsupported` state, which the API and UI show as such, never an error and never an empty list that
reads like "no ONUs".
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

CAPABILITIES = ("ont_status", "ont_optical", "pon_optical", "ont_reasons", "uni_status", "sfp_optical")
STATES = ("supported", "unsupported")

_EPON_FD11 = frozenset({"ont_status", "ont_optical", "pon_optical", "ont_reasons", "uni_status"})
_FD12_FD16 = frozenset({"ont_status", "ont_optical", "ont_reasons", "uni_status"})

MODEL_CAPABILITIES: dict[str, frozenset[str]] = {
    "c_data_fd1104sn": _EPON_FD11,
    "c_data_fd1108s": _EPON_FD11,
    "c_data_fd1204sn": _FD12_FD16,
    "c_data_fd1208s": _FD12_FD16,
    "c_data_fd1216s_r1": _FD12_FD16,
    "c_data_fd1601": _FD12_FD16 | {"sfp_optical"},
    "c_data_fd1604": _FD12_FD16,
    "c_data_fd1608": _FD12_FD16,
    "c_data_fd1616": _FD12_FD16,
    # A legacy rewrite replaces the whole module list (ModelCollector assigns `modules`), so each FW 3 model has
    # exactly the modules its rewrite names (from FD16xxV3).
    "c_data_fd1601_fw3": _FD12_FD16 | {"sfp_optical"},
    "c_data_fd1604_fw3": _FD12_FD16 | {"sfp_optical"},
    "c_data_fd1608_fw3": _FD12_FD16,
    "c_data_fd1616_fw3": _FD12_FD16 | {"sfp_optical"},
    "c_data_fd1700s_fw3": frozenset({"ont_status", "ont_optical", "ont_reasons", "uni_status", "sfp_optical"}),
    "c_data_fd5008_fd5016": frozenset(),  # an access switch in C-Data's range, not an OLT
    "v_solution_v1600d": frozenset({"ont_status", "ont_optical", "pon_optical", "ont_reasons", "uni_status"}),
    "v_solution_v1600g": frozenset({"ont_status", "ont_optical", "pon_optical"}),
    "gcom_el5610_series": frozenset({"ont_status", "ont_optical", "pon_optical", "ont_reasons", "uni_status"}),
    "gcom_el5610_series_old": frozenset({"ont_status", "ont_reasons", "uni_status"}),
}
# Rewrites that change only the key and name inherit their parent's modules.
for _child, _parent in {
    "v_solution_v1600g1b": "v_solution_v1600g", "v_solution_v1600d8": "v_solution_v1600d",
    "v_solution_v1600d16": "v_solution_v1600d", "gcom_el5610_04p": "gcom_el5610_series",
    "gcom_el5610_08p": "gcom_el5610_series", "gcom_el5610_16p": "gcom_el5610_series",
}.items():
    MODEL_CAPABILITIES[_child] = MODEL_CAPABILITIES[_parent]


@dataclass(frozen=True)
class CapabilityResult:
    capability: str
    state: str          # one of STATES
    data: Any = None    # None whenever unsupported


def capability_state(model_key: str, capability: str) -> str:
    """`supported` or `unsupported`. An unknown capability or model is a programming or configuration error and
    raises; a known model that lacks the capability is the ordinary `unsupported` answer."""
    if capability not in CAPABILITIES:
        raise ValueError(f"unknown capability {capability!r}")
    if model_key not in MODEL_CAPABILITIES:
        raise KeyError(f"no capability entry for model {model_key!r}")
    return "supported" if capability in MODEL_CAPABILITIES[model_key] else "unsupported"


def capability_result(model_key: str, capability: str, data: Any = None) -> CapabilityResult:
    state = capability_state(model_key, capability)
    return CapabilityResult(capability, state, data if state == "supported" else None)
