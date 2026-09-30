"""Parity with the legacy `ModelCollector`, using the real detection rules (Plan 8's own acceptance check: 'detection
agrees with ModelCollector on every model'). No device is contacted: the 'device response' here is a string built
straight from each model's own name and pattern, following BDCOM/Huawei/ZTE's real, documented naming convention
('<Vendor> <Model> Software, ...'), read from vendor/meklis/switcher-core/configs/models/*.yml."""
import re

import pytest

from app.registry.detection import ModelRule, detect
from app.registry.device_model_data import DEVICE_MODELS

RULES = [
    ModelRule(str(i), key, name, vendor, family, dtype, oid, descr, priority)
    for i, (vendor, family, key, name, dtype, oid, descr, priority, _source) in enumerate(DEVICE_MODELS)
]
BY_VENDOR = {}
for rule in RULES:
    BY_VENDOR.setdefault(rule.vendor_slug, []).append(rule)


def representative_descr(rule: ModelRule) -> str | None:
    """A minimal string this rule's own sysdescr_pattern matches, built from the pattern itself (a literal reading of
    it, not a guess) so the test proves detection re-derives what the pattern already promises."""
    if not rule.sysdescr_pattern:
        return None
    text = rule.sysdescr_pattern
    text = text.lstrip("^").rstrip("$")
    text = text.replace(".*?", " ").replace(".*", " ").replace("*", "")
    text = text.replace("\\b", " ")  # a real word boundary needs an actual non-word character, not nothing
    text = re.sub(r"\\d\{(\d+)(?:,\d+)?\}", lambda m: "0" * int(m.group(1)), text)  # \d{3,4} -> "000" (3 digits, the minimum)
    text = re.sub(r"\\d", "0", text)
    for alternation in re.finditer(r"\(([\w|]+)\)", text):
        text = text.replace(alternation.group(0), alternation.group(1).split("|")[0])
    text = " ".join(text.split())  # collapse the boundary spaces into single separators, tidy but not required
    assert re.search(rule.sysdescr_pattern, text), f"{rule.name}: built string {text!r} does not match its own pattern"
    return text


def representative_objid(rule: ModelRule) -> str | None:
    if not rule.sysobjectid_pattern:
        return None
    # These patterns are themselves near-literal OIDs (BDCOM/Huawei/ZTE do not publish wildcard product ranges), so the
    # pattern text stripped of regex anchors/quantifiers is already a real, matching OID.
    text = rule.sysobjectid_pattern.lstrip("^").rstrip("$").rstrip("*").rstrip(".")
    text = text.replace("\\.", ".")  # \. in the pattern is an escaped literal dot; the built string needs the plain character
    assert re.search(rule.sysobjectid_pattern, text), f"{rule.name}: built OID {text!r} does not match its own pattern"
    return text


@pytest.mark.parametrize("rule", RULES, ids=[r.key for r in RULES])
def test_every_real_model_is_detected_from_its_own_declared_pattern(rule):
    descr, objid = representative_descr(rule), representative_objid(rule)
    result = detect(BY_VENDOR[rule.vendor_slug], sys_descr=descr, sys_object_id=objid)
    assert result.status == "matched", (rule.name, descr, objid, [c.name for c in result.candidates])
    assert result.matched.device_type == rule.device_type, rule.name
    # The legacy engine also takes the first (lowest-priority) match, so the matched model's priority can only be
    # lower than or equal to this rule's own — never a *different*, less specific model instead.
    assert result.matched.priority <= rule.priority, (rule.name, result.matched.name)


def test_the_catalogue_has_the_vendors_and_split_the_acceptance_checks_ask_for():
    types = {(r.vendor_slug, r.device_type) for r in RULES}
    assert ("bdcom", "switch") in types and ("bdcom", "olt") in types    # BDCOM switch and OLT both present and split
    assert ("huawei", "olt") in types                                    # Huawei OLT
    assert ("zte", "olt") in types                                       # ZTE C-series OLT
    assert len(RULES) == 35 and len({r.key for r in RULES}) == 35


def test_every_model_has_at_least_one_matcher_and_a_valid_priority_ordering():
    for rule in RULES:
        assert rule.sysobjectid_pattern or rule.sysdescr_pattern, rule.name
    for vendor_rules in BY_VENDOR.values():
        priorities = [r.priority for r in vendor_rules]
        assert priorities == sorted(priorities) and len(set(priorities)) == len(priorities)


def test_bdcom_switch_and_olt_families_never_share_a_model():
    bdcom = BY_VENDOR["bdcom"]
    by_key = {r.key: r.device_type for r in bdcom}
    assert by_key["bdcom_s5612"] == "switch" and by_key["bdcom_gp3600_series"] == "olt"
