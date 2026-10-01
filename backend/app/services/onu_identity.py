"""ONU rows keyed by identity, not by table index (Plan 14).

A BDCOM OLT reports each ONU attribute as its own table column, all indexed the same way for one poll. The index is
an implementation detail of the OLT: it changes when an ONU is re-registered or the OLT renumbers. So the rows of one
poll are joined on the index, and the result is keyed by the ONU's identity (serial for GPON, MAC for EPON, per
app/registry/bdcom_olt_oids.ONU_IDENTITY); the index itself is never stored as the ONU's key.
"""
from __future__ import annotations

from typing import Any

from app.registry.bdcom_olt_oids import NORMALIZED, ONU_IDENTITY, PROFILES

ONU_MEANINGS = [m for m in NORMALIZED if m.startswith("onu.") and m != "onu.identity"]


def _column_roots(technology: str) -> dict[str, str]:
    profile = PROFILES["bdcom_olt_gpon" if technology == "gpon" else "bdcom_olt_epon"]
    return {d.logical_name: d.numeric_oid for d in profile}


def _by_index(root: str, rows: list[tuple[str, Any]]) -> dict[str, Any]:
    prefix = root + "."
    return {oid[len(prefix):]: value for oid, value in rows if oid.startswith(prefix)}


def onus_by_identity(technology: str, walks: dict[str, list[tuple[str, Any]]]) -> dict[str, dict[str, Any]]:
    """walks: logical name -> the (oid, value) rows one poll returned for that column. Returns identity -> {meaning:
    value} for every ONU whose identity was read. An ONU without an identity row is left out rather than keyed by its
    index; two rows claiming the same identity keep the first, so a duplicate never overwrites real data silently."""
    if technology not in ONU_IDENTITY:
        raise ValueError(f"unknown technology {technology!r}")
    roots = _column_roots(technology)
    identity_name = ONU_IDENTITY[technology]
    identities = _by_index(roots[identity_name], walks.get(identity_name, []))
    columns = {
        meaning: _by_index(roots[name], walks.get(name, []))
        for meaning in ONU_MEANINGS
        if (name := NORMALIZED[meaning][technology]) is not None
    }
    result: dict[str, dict[str, Any]] = {}
    for index, identity in identities.items():
        key = str(identity).strip()
        if not key or key in result:
            continue
        result[key] = {meaning: values.get(index) for meaning, values in columns.items()}
    return result
