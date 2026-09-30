"""Detection logic, using synthetic, clearly-fake rules and strings — no real vendor data. Real-data parity lives in
test_device_model_catalogue.py."""
import pytest

from app.registry.detection import DetectionResult, ModelRule, detect

SPECIFIC = ModelRule("1", "specific", "Specific Model X100", "acme", "acme-switch", "switch", r"^1\.2\.3\.4\.100$", r"X100", priority=0)
GENERIC = ModelRule("2", "generic", "Generic Acme switch", "acme", "acme-switch", "switch", r"^1\.2\.3\.4", r"Acme", priority=1)
OTHER_VENDOR = ModelRule("3", "other", "Other Vendor Thing", "other", "other-switch", "switch", r"^9\.9\.9", r"OtherCo", priority=0)
CROSS_TYPE_SWITCH = ModelRule("4", "cross-switch", "Ambiguous switch", "acme", "acme-switch", "switch", None, r"Ambiguous", priority=0)
CROSS_TYPE_OLT = ModelRule("5", "cross-olt", "Ambiguous OLT", "acme", "acme-olt", "olt", None, r"Ambiguous", priority=1)
RULES = [SPECIFIC, GENERIC, OTHER_VENDOR]


def test_no_match_is_unknown_not_an_error():
    result = detect(RULES, sys_descr="Completely unrelated device", sys_object_id="5.5.5.5")
    assert result.status == "unknown" and result.matched is None and result.candidates == []


def test_the_most_specific_matching_rule_wins_by_priority():
    result = detect(RULES, sys_descr="Acme X100 Software", sys_object_id="1.2.3.4.100")
    assert result.status == "matched" and result.matched is SPECIFIC


def test_a_generic_rule_still_matches_when_the_specific_one_does_not():
    result = detect(RULES, sys_descr="Acme X200 Software", sys_object_id="1.2.3.4.200")
    assert result.matched is GENERIC


def test_an_empty_value_auto_passes_its_half_of_the_rule_matching_legacy_semantics():
    assert detect(RULES, sys_descr=None, sys_object_id="1.2.3.4.100").matched is SPECIFIC
    assert detect(RULES, sys_descr="", sys_object_id="1.2.3.4.100").matched is SPECIFIC
    only_generic = [GENERIC]
    assert detect(only_generic, sys_descr=None, sys_object_id="1.2.3.4.999").matched is GENERIC


def test_both_empty_matches_the_first_rule_by_priority_alone():
    result = detect(RULES, sys_descr=None, sys_object_id=None)
    assert result.matched is SPECIFIC  # priority 0, and both its patterns auto-pass


def test_matching_is_a_search_not_a_full_match_like_the_legacy_preg_match():
    loose = ModelRule("6", "loose", "Loose", "acme", "acme-switch", "switch", None, r"X100", priority=0)
    result = detect([loose], sys_descr="prefix noise X100 suffix noise", sys_object_id=None)
    assert result.matched is loose


def test_a_pattern_from_one_vendor_cannot_match_another_vendors_string():
    result = detect(RULES, sys_descr="OtherCo Widget-9", sys_object_id="9.9.9.1")
    assert result.matched is OTHER_VENDOR


def test_cross_device_type_ambiguity_is_never_guessed():
    result = detect([CROSS_TYPE_SWITCH, CROSS_TYPE_OLT], sys_descr="Ambiguous unit 1", sys_object_id=None)
    assert result.status == "ambiguous" and result.matched is None
    assert {c.id for c in result.candidates} == {"4", "5"}


def test_same_type_multiple_matches_is_not_treated_as_ambiguous():
    # Two switch models both matching is normal and safe: whichever is picked, the poller family is the same.
    a = ModelRule("a", "a", "A", "acme", "acme-switch", "switch", None, r"Multi", priority=0)
    b = ModelRule("b", "b", "B", "acme", "acme-switch", "switch", None, r"Multi", priority=1)
    result = detect([a, b], sys_descr="Multi unit", sys_object_id=None)
    assert result.status == "matched" and result.matched is a and len(result.candidates) == 2


def test_a_malformed_pattern_matches_nothing_instead_of_raising():
    broken = ModelRule("x", "x", "Broken", "acme", "acme-switch", "switch", r"^1\.2\.3\.4(", None, priority=0)
    result = detect([broken], sys_descr=None, sys_object_id="1.2.3.4.1")
    assert result.status == "unknown"


def test_detection_result_status_property():
    assert DetectionResult(matched=SPECIFIC, ambiguous=False).status == "matched"
    assert DetectionResult(matched=None, ambiguous=False).status == "unknown"
    assert DetectionResult(matched=None, ambiguous=True).status == "ambiguous"


def test_objid_patterns_written_for_a_leading_dot_also_match_this_systems_dot_less_canonical_form():
    """Every ported legacy pattern starts '^.1.3.6...' assuming the SNMP-library leading-dot convention, but this
    system's own discovery worker stores sysObjectID without one (handlers.py strips it). Both forms must match."""
    rule = ModelRule("z", "z", "Z", "acme", "acme-switch", "switch", r"^.1.2.3.4", None, priority=0)
    assert detect([rule], sys_descr=None, sys_object_id=".1.2.3.4.99").matched is rule   # the dotted form the pattern was written for
    assert detect([rule], sys_descr=None, sys_object_id="1.2.3.4.99").matched is rule    # this system's own canonical, dot-less form
    assert detect([rule], sys_descr=None, sys_object_id="9.9.9.9").matched is None       # still refuses an unrelated value
