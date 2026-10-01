"""Huawei OLT logic for Plan 15: PON/ONT parsing, optical scaling and down-reason normalization. Pure functions, no
device. Expected values come from the legacy code's own comments and formulas; the scaling cases pin what production
displays today (legacy parity), not a device-confirmed truth."""
import pytest

from app.vendors.huawei_olt import (
    COMMON_REASONS,
    DOWN_CAUSE_TABLES,
    EPON_ONT_POWER_FORMULA_CONFIRMED,
    Ont,
    PonPort,
    legacy_numeric_id,
    normalize_down_cause,
    ont_from_oid,
    parse_ont_name,
    parse_pon_name,
    scale_distance,
    scale_optical,
)

GPON_017 = PonPort("gpon", 0, 1, 7)
EPON_023 = PonPort("epon", 0, 2, 3)
ROOT = "1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4"


@pytest.mark.parametrize("name, port", [
    ("GPON 0/1/7", GPON_017), ("EPON 0/2/3", EPON_023), ("GPON 1/16/15", PonPort("gpon", 1, 16, 15)), (" GPON 0/1/7 ", GPON_017),
])
def test_pon_port_names_parse_to_frame_slot_and_port(name, port):
    assert parse_pon_name(name) == port and port.name == name.strip()


@pytest.mark.parametrize("name", ["ethernet0/6/1", "GPON 0/1", "XGPON 0/1/7", "GPON 0/1/7:1", "gpon 0/1/7", "GPON 100/1/7"])
def test_anything_that_is_not_a_pon_port_is_not_one(name):
    assert parse_pon_name(name) is None


def test_ont_names_parse():
    assert parse_ont_name("GPON 0/1/7:1") == Ont(GPON_017, 1)
    assert parse_ont_name("GPON 0/1/7") is None


@pytest.mark.parametrize("item, legacy", [
    (GPON_017, 200107), (Ont(GPON_017, 0), 200107000), (Ont(GPON_017, 1), 200107001), (PonPort("gpon", 1, 6, 1), 210601),
])
def test_legacy_numeric_ids_match_legacys_own_examples(item, legacy):
    assert legacy_numeric_id(item) == legacy  # from the comment block in HuaweiOLTAbstractModule.php


def test_gpon_and_epon_ont_indexes_each_parse_to_the_right_board_pon_and_ont():
    pons = {4194312448: GPON_017, 4194320640: EPON_023}
    assert ont_from_oid(f"{ROOT}.4194312448.12", ROOT, pons) == Ont(GPON_017, 12)
    assert ont_from_oid(f"{ROOT}.4194320640.63", ROOT, pons) == Ont(EPON_023, 63)


@pytest.mark.parametrize("oid", [
    f"{ROOT}.4194320640.64",       # EPON has 64 ONTs per PON (0..63)
    f"{ROOT}.4194312448.128",      # GPON has 128 (0..127)
    f"{ROOT}.999.1",               # unknown PON ifIndex
    f"{ROOT}.4194312448",          # no ONT id
    f"{ROOT}.4194312448.1.2",      # too long
    "1.3.6.1.4.1.2011.6.128.1.1.2.51.1.5.4194312448.1",  # another column
])
def test_rows_that_do_not_fit_are_refused_not_guessed(oid):
    assert ont_from_oid(oid, ROOT, {4194312448: GPON_017, 4194320640: EPON_023}) is None


@pytest.mark.parametrize("field, raw, expected", [
    ("rx", -2350, -23.5), ("tx", 215, 2.15), ("olt_rx", 7650, -23.5), ("temp", 45, 45.0), ("voltage", 3300, 3.3),
])
def test_optical_values_scale_as_legacy_displays_them(field, raw, expected):
    assert scale_optical(field, raw) == expected


@pytest.mark.parametrize("field, raw", [
    ("rx", 2147483647), ("tx", 2147483647), ("olt_rx", 2147483647), ("temp", 2147483647), ("voltage", 2147483647),
    ("rx", -5000), ("rx", -6000), ("olt_rx", 5000), ("rx", None),
])
def test_invalid_and_out_of_range_readings_are_no_reading(field, raw):
    assert scale_optical(field, raw) is None


def test_unknown_fields_are_refused_and_distance_minus_one_is_no_distance():
    with pytest.raises(ValueError):
        scale_optical("bias", 10)
    assert scale_distance(-1) is None and scale_distance(1234) == 1234 and scale_distance(None) is None


def test_the_epon_power_formula_conflict_stays_flagged_until_a_fixture_settles_it():
    assert EPON_ONT_POWER_FORMULA_CONFIRMED is False


@pytest.mark.parametrize("table", list(DOWN_CAUSE_TABLES))
def test_every_down_reason_maps_to_a_common_alarm_reason(table):
    for code, (label, reason) in DOWN_CAUSE_TABLES[table].items():
        assert reason in COMMON_REASONS, (table, code, label)


@pytest.mark.parametrize("table, code, reason", [
    ("gpon.last_down_cause", 1, "los"), ("gpon.last_down_cause", 13, "power_off"), ("gpon.last_down_cause", 15, "loki"),
    ("epon.last_down_cause", 1, "los"), ("epon.last_down_cause", 13, "power_off"), ("epon.last_down_cause", 15, "loki"),
    ("registration_history.down_cause", 12, "auth_fail"), ("registration_history.down_cause", 13, "power_off"),
    ("registration_history.down_cause", 255, "none"), ("epon.last_down_cause", 37, "rogue_ont"),
])
def test_the_reasons_plan_15_names_normalize_correctly(table, code, reason):
    assert normalize_down_cause(table, code).reason == reason


def test_code_18_means_different_things_per_table_as_in_legacy():
    assert normalize_down_cause("gpon.last_down_cause", 18).reason == "unknown"
    assert normalize_down_cause("epon.last_down_cause", 18).label == "DeactivatedByRing"


def test_an_unlisted_code_is_unknown_with_the_raw_code_visible_and_none_stays_none():
    reason = normalize_down_cause("gpon.last_down_cause", 99)
    assert (reason.code, reason.label, reason.reason) == (99, "Code 99", "unknown")
    assert normalize_down_cause("gpon.last_down_cause", None) is None


def test_the_labels_are_the_legacy_value_maps_own_words():
    """Parity with configs/oids/huawei/smartax.yml, copied here so a later edit to one side is caught."""
    legacy_gpon = {1: "LOS", 2: "LOSi", 3: "LOFi", 4: "SFI", 5: "LOAI", 6: "LOAMI", 7: "DeactivateFail", 8: "Deactivated",
                   9: "Reset", 10: "ReRegister", 11: "PopUpFail", 13: "PowerOff", 15: "LOKI", -1: "Unknown", 18: "Unknown"}
    legacy_history = {0: "Deleted", 1: "LinkedDown", 2: "LOSi", 3: "LOFi", 4: "SFI", 5: "LOAI", 6: "LOAMI", 7: "DisableFail",
                      8: "Deactivated", 9: "Reset", 10: "ReRegister", 11: "PopUpFail", 12: "AuthFail", 13: "PowerDown",
                      14: "Reserved", 15: "Loki", 255: "NoError", -1: "Invalid"}
    assert {c: label for c, (label, _) in DOWN_CAUSE_TABLES["gpon.last_down_cause"].items()} == legacy_gpon
    assert {c: label for c, (label, _) in DOWN_CAUSE_TABLES["registration_history.down_cause"].items()} == legacy_history
