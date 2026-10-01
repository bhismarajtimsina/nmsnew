"""ZTE C300/C600 logic for Plan 16: names and indexes, optical conversion, offline reasons and phase states. Pure
functions, no device. Expected values come from the legacy formulas and value maps; they pin what production computes
today (or the documented correction of it), not a device-confirmed truth."""
import pytest

from app.vendors.common import COMMON_REASONS
from app.vendors.zte_olt import (
    EPON_OPTICAL_UNITS_CONFIRMED,
    OFFLINE_REASON_TABLES,
    PHASE_STATE_TABLES,
    PHASE_STATUSES,
    Ont,
    PonPort,
    decode_c300_index,
    display_name,
    encode_c300_index,
    gpon_onu_power,
    legacy_numeric_id,
    max_onts,
    normalize_offline_reason,
    normalize_phase_state,
    ont_from_c600_oid,
    parse_name,
    scale_optical,
)

G123 = PonPort("gpon", 1, 2, 3)
E145 = PonPort("epon", 1, 4, 5)
CARDS = {(1, 1): "epon", (1, 2): "gpon", (1, 4): "epon", (1, 17): "epon", (2, 2): "gpon"}


# --- names ---

@pytest.mark.parametrize("name, item", [
    ("gpon-olt_1/2/3", G123), ("gpon_olt-1/2/3", G123), ("gpon-onu_1/2/3:4", Ont(G123, 4)), ("gpon_onu-1/2/3:128", Ont(G123, 128)),
    ("epon-onu_1/4/5:64", Ont(E145, 64)), (" gpon-olt_1/2/3 ", G123),
])
def test_both_models_names_parse(name, item):
    assert parse_name(name) == item


@pytest.mark.parametrize("name", [
    "gpon-olt_1/2/3:4", "gpon-onu_1/2/3", "gpon-onu_1/2/3:0", "gpon-onu_1/2/3:129", "epon-onu_1/4/5:65", "gei_1/3/1",
    "xgpon-olt_1/2/3", "GPON-olt_1/2/3", "gpon-olt_10/2/3",
])
def test_names_this_olt_does_not_write_are_refused(name):
    assert parse_name(name) is None


def test_display_names_follow_each_models_own_spelling():
    assert display_name(Ont(G123, 4), "c300") == "gpon-onu_1/2/3:4" and display_name(G123, "c300") == "gpon-olt_1/2/3"
    assert display_name(Ont(G123, 4), "c600") == "gpon_onu-1/2/3:4" and display_name(G123, "c600") == "gpon_olt-1/2/3"
    with pytest.raises(ValueError):
        display_name(G123, "c320")


def test_legacy_numeric_ids_and_pon_sizes():
    assert legacy_numeric_id(G123) == 10203000 and legacy_numeric_id(Ont(G123, 4)) == 10203004
    assert (max_onts("gpon"), max_onts("epon"), max_onts("epon", "ETTOK"), max_onts("epon", "EPFC")) == (128, 64, 128, 64)


# --- C300 indexes ---

@pytest.mark.parametrize("item, layout", [
    (G123, 1), (Ont(G123, 4), 1), (Ont(PonPort("gpon", 2, 2, 16), 128), 1),
    (Ont(E145, 1), 3), (Ont(E145, 64), 3), (PonPort("epon", 1, 17, 8), 3),
    (Ont(PonPort("epon", 1, 4, 16), 64), 9), (Ont(E145, 7), 9),
])
def test_c300_indexes_round_trip(item, layout):
    assert decode_c300_index(encode_c300_index(item, layout), CARDS) == item


def test_c300_index_values_match_the_legacy_bit_layout():
    # gpon-olt_1/2/3: "0001" + 0000 + 00000010 + 00000011 + 00000000
    assert encode_c300_index(G123, 1) == str(0x10020300)
    # epon-onu_1/4/5:6 on an 8-port card: "0011" + 0000 + 00100 + 100 + 00000110 + 00000000
    assert encode_c300_index(Ont(E145, 6), 3) == str(0b0011_0000_00100_100_00000110_00000000)
    # the same ONT on a 16-port card: "1001" + 000 + 00100 + 0100 + 00000110 + 00000000
    assert encode_c300_index(Ont(E145, 6), 9) == str(0b1001_000_00100_0100_00000110_00000000)


@pytest.mark.parametrize("index", [
    str(0x10020301),                    # layout 1 with a non-zero low byte
    f"{0x10030300}.1",                  # shelf 1 slot 3: no card there
    str(0x10020000),                    # port 0
    f"{0x10020300}.129",                # GPON has 128 ONTs
    str(0b0011_0000_00100_100_01000001_00000000),  # EPON ONT 65
    f"{0b0011_0000_00100_100_00000001_00000000}.1",  # layout 3 carries the ONT itself; a second component is wrong
    str(0x20020300),                    # unknown layout
    str(0b1001_001_00100_0100_00000110_00000000),  # 16-port, shelf 2: legacy's decoder would read shelf 1
    str(0b1001_000_10001_0100_00000110_00000000),  # 16-port, slot 17: legacy's decoder would read slot 1
    "abc", "1.2.3", "0", str(2**32), str(2**32 + 0x10020300),
])
def test_c300_indexes_that_do_not_fit_are_refused_not_guessed(index):
    assert decode_c300_index(index, CARDS) is None


def test_the_technology_comes_from_the_card_not_the_layout():
    """Layout 1 also addresses EPON ports (legacy reads EPON OLT rx through it)."""
    assert decode_c300_index(encode_c300_index(E145, 1), CARDS) == E145


# --- C600 indexes ---

ROOT = "1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10"
PONS = {285278465: G123, 285278721: E145}


def test_c600_ont_rows_parse_with_and_without_the_trailing_component():
    assert ont_from_c600_oid(f"{ROOT}.285278465.4.1", ROOT, PONS, trailing=1) == Ont(G123, 4)
    assert ont_from_c600_oid(f"{ROOT}.285278721.64", ROOT, PONS) == Ont(E145, 64)


@pytest.mark.parametrize("oid, trailing", [
    (f"{ROOT}.285278465.4.2", 1),    # legacy only reads .1
    (f"{ROOT}.285278465.4", 1),      # missing the trailing component
    (f"{ROOT}.285278465.4.1", 0),    # one component too many
    (f"{ROOT}.285278465.0", 0),      # ONT ids start at 1
    (f"{ROOT}.285278721.65", 0),     # EPON has 64
    (f"{ROOT}.999.1", 0),            # unknown PON
    (f"{ROOT}9.285278465.4", 0),     # another column
])
def test_c600_rows_that_do_not_fit_are_refused(oid, trailing):
    assert ont_from_c600_oid(oid, ROOT, PONS, trailing=trailing) is None


# --- optical ---

@pytest.mark.parametrize("raw, dbm", [
    (0, -30.0), (1, -29.998), (10000, -10.0), (15000, 0.0), (30000, 30.0),
    (30001, -101.07), (63036, -35.0), (65534, -30.004),
])
def test_gpon_onu_power_matches_the_legacy_formula_at_and_around_its_boundaries(raw, dbm):
    assert gpon_onu_power(raw) == dbm


@pytest.mark.parametrize("raw", [65535, 65536, 131072, -1, None, True])
def test_gpon_onu_power_no_reading_and_out_of_range_values_are_refused_not_wrapped(raw):
    assert gpon_onu_power(raw) is None


@pytest.mark.parametrize("tech, field, raw, expected", [
    ("gpon", "rx", 10000, -10.0), ("gpon", "tx", 16250, 2.5), ("gpon", "temp", 11520, 45.0), ("gpon", "voltage", 165, 3.3),
    ("gpon", "olt_rx", -23456, -23.456), ("epon", "olt_rx", -23456, -23.456), ("epon", "rx", -21.4567, -21.457),
    ("epon", "tx", 2.1, 2.1), ("epon", "temp", 40, 40.0),
    ("gpon", "rx", 63036, -35.0),     # a bad signal is shown, not hidden
    ("gpon", "tx", 30001, -101.07),   # legacy filters rx, not tx
    ("gpon", "tx", 30000, 30.0),
])
def test_optical_values_scale_as_legacy_computes_them(tech, field, raw, expected):
    assert scale_optical(tech, field, raw) == expected


@pytest.mark.parametrize("tech, field, raw", [
    ("gpon", "rx", 30001),     # -101.07: beyond the -70 dBm window
    ("gpon", "rx", 30000),     # 30.0: at the window's top
    ("gpon", "rx", 65535), ("gpon", "tx", 65535), ("gpon", "rx", 70000),
    ("gpon", "olt_rx", -1000), ("gpon", "olt_rx", -80000), ("epon", "olt_rx", -1000),  # sentinels, not 0 dBm
    ("gpon", "olt_rx", -70000), ("gpon", "olt_rx", 30000), ("epon", "rx", -70), ("epon", "rx", 30),
    ("gpon", "rx", None), ("gpon", "temp", False),
])
def test_no_reading_sentinels_and_implausible_receive_powers_are_no_reading(tech, field, raw):
    assert scale_optical(tech, field, raw) is None


def test_the_rx_window_is_exclusive_at_both_ends():
    assert scale_optical("gpon", "olt_rx", -69999) == -69.999 and scale_optical("gpon", "olt_rx", 29999) == 29.999
    assert scale_optical("epon", "rx", -69.999) == -69.999


def test_unknown_fields_and_technologies_are_refused_and_epon_units_stay_flagged():
    with pytest.raises(ValueError):
        scale_optical("gpon", "bias", 10)
    with pytest.raises(ValueError):
        scale_optical("xgspon", "rx", 10)
    assert EPON_OPTICAL_UNITS_CONFIRMED is False


# --- reasons and phase states ---

@pytest.mark.parametrize("table", list(OFFLINE_REASON_TABLES))
def test_every_offline_reason_maps_to_a_common_alarm_reason(table):
    for code, (label, reason) in OFFLINE_REASON_TABLES[table].items():
        assert reason in COMMON_REASONS, (table, code, label)


@pytest.mark.parametrize("table, code, label, reason", [
    ("c300.gpon.last_offline_reason", 1, "unknown", "unknown"), ("c300.gpon.last_offline_reason", 2, "LOS", "los"),
    ("c300.gpon.last_offline_reason", 3, "LOSi", "signal_failure"), ("c300.gpon.last_offline_reason", 4, "lofi", "signal_failure"),
    ("c300.gpon.last_offline_reason", 5, "sfi", "signal_failure"), ("c300.gpon.last_offline_reason", 6, "loai", "signal_failure"),
    ("c300.gpon.last_offline_reason", 7, "loami", "signal_failure"), ("c300.gpon.last_offline_reason", 8, "authFail", "auth_fail"),
    ("c300.gpon.last_offline_reason", 9, "PowerOff", "power_off"), ("c300.gpon.last_offline_reason", 10, "deactiveSucc", "admin_action"),
    ("c300.gpon.last_offline_reason", 11, "deactiveFail", "admin_action"), ("c300.gpon.last_offline_reason", 12, "reboot", "admin_action"),
    ("c300.gpon.last_offline_reason", 13, "shutdown", "admin_action"),
    ("c600.gpon.last_offline_reason", 4, "LOFi", "signal_failure"), ("c600.gpon.last_offline_reason", 8, "AuthFail", "auth_fail"),
    ("c600.gpon.last_offline_reason", 12, "Reboot", "admin_action"), ("c600.gpon.last_offline_reason", 13, "Shutdown", "admin_action"),
    ("c300.epon.last_offline_reason", 1, "unknown", "unknown"), ("c300.epon.last_offline_reason", 14, "bugsell", "unknown"),
    ("c300.epon.last_offline_reason", 21, "LOS", "los"), ("c300.epon.last_offline_reason", 22, "PowerOff", "power_off"),
    ("c300.epon.last_offline_reason", 23, "timestampDrift", "signal_failure"),
])
def test_each_offline_reason_normalizes(table, code, label, reason):
    result = normalize_offline_reason(table, code)
    assert (result.code, result.label, result.reason) == (code, label, reason)


def test_both_models_gpon_reasons_agree_on_meaning_and_differ_only_in_spelling():
    c300, c600 = OFFLINE_REASON_TABLES["c300.gpon.last_offline_reason"], OFFLINE_REASON_TABLES["c600.gpon.last_offline_reason"]
    assert c300.keys() == c600.keys() == set(range(1, 14))
    assert all(c300[c][1] == c600[c][1] and c300[c][0].lower() == c600[c][0].lower() for c in c300)


def test_an_unlisted_reason_is_unknown_with_the_raw_code_visible():
    reason = normalize_offline_reason("c600.gpon.last_offline_reason", 99)
    assert (reason.label, reason.reason) == ("Code 99", "unknown")
    assert normalize_offline_reason("c600.gpon.last_offline_reason", None) is None


@pytest.mark.parametrize("model, code, status, reason", [
    ("c300", 0, "registering", None), ("c300", 1, "offline", "los"), ("c300", 2, "registering", None), ("c300", 3, "online", None),
    ("c300", 4, "offline", "power_off"), ("c300", 5, "offline", "auth_fail"), ("c300", 6, "offline", "unknown"),
    ("c600", 1, "registering", None), ("c600", 2, "offline", "los"), ("c600", 3, "registering", None), ("c600", 4, "online", None),
    ("c600", 5, "offline", "power_off"), ("c600", 6, "offline", "auth_fail"), ("c600", 7, "offline", "unknown"),
])
def test_each_phase_state_normalizes_per_model(model, code, status, reason):
    state = normalize_phase_state(model, code)
    assert (state.status, state.reason) == (status, reason)


def test_phase_codes_are_off_by_one_between_models_and_never_read_across():
    assert normalize_phase_state("c300", 3).status == "online" and normalize_phase_state("c600", 3).status == "registering"
    assert normalize_phase_state("c600", 4).status == "online" and normalize_phase_state("c300", 4).status == "offline"
    unknown = normalize_phase_state("c300", 7)
    assert (unknown.label, unknown.status, unknown.reason) == ("Code 7", "unknown", "unknown")
    assert normalize_phase_state("c600", None) is None
    for table in PHASE_STATE_TABLES.values():
        for label, status, reason in table.values():
            assert status in PHASE_STATUSES and (reason is None) == (status != "offline")
            assert reason is None or reason in COMMON_REASONS


def test_the_labels_are_the_legacy_value_maps_own_words():
    """Parity with configs/oids/zte/ZTE-C300_fw*.yml and ZTE-C600.yml, copied here so a later edit to one side is caught."""
    c600_phase = {1: "Logging", 2: "LOS", 3: "SyncMib", 4: "Online", 5: "PowerOff", 6: "AuthFailed", 7: "Offline"}
    c300_phase = {0: "logging", 1: "LOS", 2: "syncMib", 3: "Online", 4: "PowerOff", 5: "authFailed", 6: "Offline"}
    assert {c: v[0] for c, v in PHASE_STATE_TABLES["c600"].items()} == c600_phase
    assert {c: v[0] for c, v in PHASE_STATE_TABLES["c300"].items()} == c300_phase
