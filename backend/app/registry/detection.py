"""Model detection from sysDescr and sysObjectID, ported from the legacy switcher-core ModelCollector.

Matching semantics are copied exactly from `Model::detectByDescription`/`detectByObjId` (PHP `preg_match`, i.e. "pattern
found anywhere in the string", equivalent to Python's `re.search`; an empty value auto-passes that half of the check).
Models are tried in `priority` order — the order the vendor's own config author wrote them in, specific models first,
generic fallbacks last — and the first full match wins, exactly like the legacy engine. This is what makes detection
agree with `ModelCollector` on every existing model (Plan 8's acceptance check).

The one improvement over the legacy engine: if the matching models are not all the same `device_type`, detection refuses
to guess and returns unknown instead. A BDCOM switch mis-detected as another BDCOM switch model is harmless — both get
switch pollers. A switch mis-detected as an OLT would run the wrong, unsafe poller set (risk K-09), so that case is
never silently resolved by file order.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field


@dataclass(frozen=True)
class ModelRule:
    id: str
    key: str
    name: str
    vendor_slug: str
    family_slug: str
    device_type: str
    sysobjectid_pattern: str | None
    sysdescr_pattern: str | None
    priority: int


@dataclass(frozen=True)
class DetectionResult:
    matched: ModelRule | None
    ambiguous: bool
    candidates: list[ModelRule] = field(default_factory=list)

    @property
    def status(self) -> str:
        if self.ambiguous:
            return "ambiguous"
        return "matched" if self.matched else "unknown"


def _matches(pattern: str | None, value: str | None, *, try_leading_dot: bool = False) -> bool:
    if not value:
        return True  # legacy semantics: nothing to check against means this half of the rule does not rule anything out
    if not pattern:
        return True
    try:
        if re.search(pattern, value) is not None:
            return True
        # These patterns were written for SNMP-library output that renders an absolute OID with a leading dot
        # (".1.3.6.1.4.1.3320..."), which is what every ported objid pattern here starts with (`^.1...`). This system's
        # own canonical sysObjectID has no leading dot (the discovery worker strips it), so without this, every one of
        # these patterns would silently fail to match a real, correctly-stored value. Confirmed by testing against the
        # actual documented value for a real device (see test_device_models_api.py).
        return try_leading_dot and re.search(pattern, "." + value) is not None
    except re.error:
        return False  # a malformed pattern matches nothing rather than raising into the caller


def detect(rules: list[ModelRule], *, sys_descr: str | None, sys_object_id: str | None) -> DetectionResult:
    candidates = [
        rule for rule in sorted(rules, key=lambda r: r.priority)
        if _matches(rule.sysdescr_pattern, sys_descr) and _matches(rule.sysobjectid_pattern, sys_object_id, try_leading_dot=True)
    ]
    if not candidates:
        return DetectionResult(matched=None, ambiguous=False, candidates=[])
    best = candidates[0]
    device_types = {c.device_type for c in candidates}
    if len(device_types) > 1:
        return DetectionResult(matched=None, ambiguous=True, candidates=candidates)
    return DetectionResult(matched=best, ambiguous=False, candidates=candidates)
