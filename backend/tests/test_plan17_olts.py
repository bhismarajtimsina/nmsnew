"""Plan 17: C-Data, V-Solution and GCOM OLT logic, the per-model capability table with its explicit `unsupported`
state, and the legacy action-name guard. Pure functions, no device. Expected values come from the legacy formulas and
value maps; they pin what production computes today (or the documented correction of it), not a device-confirmed
truth."""
import pytest

from app.registry.oid import Entry, entry_problems
from app.vendors import cdata_olt, gcom_olt, vsol_olt
from app.vendors.capabilities import CAPABILITIES, MODEL_CAPABILITIES, capability_result, capability_state
from app.vendors.common import COMMON_REASONS, parse_number
from app.vendors.legacy_actions import is_legacy_action, legacy_definition, name_tokens

# --- capabilities ---


def test_a_missing_capability_is_unsupported_not_an_error_and_carries_no_data():
    result = capability_result("gcom_el5610_series_old", "ont_optical", data=[{"rx": -20}])
    assert (result.state, result.data) == ("unsupported", None)
    assert capability_result("gcom_el5610_series", "ont_optical", data=[1]).data == [1]


@pytest.mark.parametrize("model, capability, state", [
    ("gcom_el5610_series", "ont_optical", "supported"), ("gcom_el5610_series_old", "ont_optical", "unsupported"),
    ("gcom_el5610_16p", "pon_optical", "supported"), ("v_solution_v1600g", "ont_reasons", "unsupported"),
    ("v_solution_v1600g1b", "ont_reasons", "unsupported"), ("v_solution_v1600d16", "ont_reasons", "supported"),
    ("c_data_fd1104sn", "pon_optical", "supported"), ("c_data_fd1604", "pon_optical", "unsupported"),
    ("c_data_fd1601", "sfp_optical", "supported"), ("c_data_fd1604", "sfp_optical", "unsupported"),
    ("c_data_fd1604_fw3", "sfp_optical", "supported"), ("c_data_fd1608_fw3", "sfp_optical", "unsupported"),
    ("c_data_fd1608_fw3", "uni_status", "supported"), ("c_data_fd5008_fd5016", "ont_status", "unsupported"),
])
def test_capabilities_follow_the_legacy_model_files(model, capability, state):
    assert capability_state(model, capability) == state


def test_unknown_models_and_capabilities_are_configuration_errors():
    with pytest.raises(KeyError):
        capability_state("c_data_fd9999", "ont_status")
    with pytest.raises(ValueError):
        capability_state("c_data_fd1604", "teleport")
    assert all(caps <= set(CAPABILITIES) for caps in MODEL_CAPABILITIES.values())


def test_every_c_data_model_with_ont_optical_has_an_optical_family_and_no_other_does():
    cdata = {m for m in MODEL_CAPABILITIES if m.startswith("c_data_")}
    assert {m for m in cdata if "ont_optical" in MODEL_CAPABILITIES[m]} == set(cdata_olt.FAMILY_BY_MODEL)


# --- legacy action names ---

@pytest.mark.parametrize("name", [
    "ont.action.reboot", "ont.action.resetOnu", "ont.action.delete", "ont.action.clear_counters", "olt.save",
    "zx.olt.saveConfig", "gpon.ont.action.restoreFactory", "ont.gpon.controlReRegister", "ont.epon.stat.reset",
    "ont.gpon.config.ip.resetDHCP", "dlink.DevCtrlSystemReboot", "dlink.CableDiagAction", "cable_diag.action",
    "uni.control.set.adminStatus", "uni.control.set.ponNum", "action.ont.reset.ponNo", "action.ont.description.str",
    "ont.actions.port", "ont.setUni.onuNum", "card.reset.index", "epon.ont.ctrlActiveStatus", "loopdetect.enable",
])
def test_legacy_action_names_are_flagged(name):
    assert is_legacy_action(name)


@pytest.mark.parametrize("name", [
    "gpon.ont.LastOfflineReason", "ont.gpon.lastDownCause", "ont.epon.regTable.downCause", "ont.opticalRx",
    "ont.optical.rxPower", "pon.portOperStatus", "ont.lastDeregReason", "gpon.ont.phaseState", "zx.slot.OperStatus",
    "ont.lastReg", "if.Name", "resources.cpuIdle", "ont.opStatus", "dhcp.snooping.bindingType",
])
def test_reason_status_and_reading_names_are_not_flagged(name):
    assert not is_legacy_action(name)


def test_the_snmpset_comment_flags_a_name_that_reads_innocently():
    assert is_legacy_action("pon.portAdminStatus", "#SNMPSET") and not is_legacy_action("pon.portAdminStatus")


def test_camel_case_and_acronyms_split_into_words():
    assert name_tokens("ont.gpon.config.ip.resetDHCP") == ["ont", "gpon", "config", "ip", "reset", "dhcp"]
    assert name_tokens("dlink.DevCtrlSystemReboot") == ["dlink", "dev", "ctrl", "system", "reboot"]
    assert name_tokens("statusActionX1") == ["status", "action", "x", "1"]


def test_a_legacy_action_can_never_enter_a_polling_profile_and_a_reading_can():
    action = legacy_definition("ont.action.reboot", ".1.3.6.1.4.1.17409.2.3.4.1.1.17")
    reading = legacy_definition("ont.opticalRx", ".1.3.6.1.4.1.17409.2.3.4.2.1.4")
    assert (action.access, action.safety_level, action.numeric_oid) == ("read-write", "dangerous", "1.3.6.1.4.1.17409.2.3.4.1.1.17")
    assert entry_problems(Entry(action)) == ["writable or dangerous OID cannot be polled"]
    assert entry_problems(Entry(reading)) == []


# --- shared parsing ---

@pytest.mark.parametrize("raw, value", [("-19.10", -19.1), (" 3.3 ", 3.3), (2, 2.0), ("", None), ("abc", None),
                                        ("nan", None), ("inf", None), (None, None), (True, None)])
def test_string_readings_parse_and_garbage_is_no_reading_not_zero(raw, value):
    assert parse_number(raw) == value


# --- C-Data ---

@pytest.mark.parametrize("model, field, raw, expected", [
    ("c_data_fd1104sn", "rx", 1000, -10.0), ("c_data_fd1104sn", "rx", 1, -40.0), ("c_data_fd1104sn", "tx", 20000, 3.01),
    ("c_data_fd1104sn", "voltage", 33000, 3.3),
    ("c_data_fd1208s", "rx", -2150, -21.5), ("c_data_fd1208s", "voltage", 330000, 3.3), ("c_data_fd1208s", "temp", 4520, 45.2),
    ("c_data_fd1604_fw3", "olt_rx", -2600, -26.0), ("c_data_fd1616", "voltage", 330000, 3.3),
    ("c_data_fd1700s_fw3", "voltage", 330, 3.3), ("c_data_fd1700s_fw3", "rx", -2150, -21.5),
    ("c_data_fd1700s_fw3", "olt_rx", -2600, -26.0), ("c_data_fd1700s_fw3", "temp", 4520, 45.2),
    ("c_data_fd1700s_fw3", "tx", -3999, -39.99), ("c_data_fd1700s_fw3", "rx", -10000, -100.0),
    ("c_data_fd1700s_fw3", "olt_rx", -10000, -100.0), ("c_data_fd1700s_fw3", "tx", -10000, -100.0),
])
def test_c_data_optical_follows_the_per_family_override_table(model, field, raw, expected):
    assert cdata_olt.scale_optical(model, field, raw) == expected


def test_the_same_raw_voltage_means_three_different_things_across_families():
    assert [cdata_olt.scale_optical(m, "voltage", 330) for m in ("c_data_fd1104sn", "c_data_fd1208s", "c_data_fd1700s_fw3")] == [0.03, 0.0, 3.3]


@pytest.mark.parametrize("model, field, raw", [
    ("c_data_fd1104sn", "rx", 0), ("c_data_fd1104sn", "rx", 0.5), ("c_data_fd1104sn", "rx", -5), ("c_data_fd1104sn", "tx", None),
    ("c_data_fd1700s_fw3", "rx", -1), ("c_data_fd1700s_fw3", "rx", -4000), ("c_data_fd1700s_fw3", "rx", -10001),
    ("c_data_fd1700s_fw3", "olt_rx", -1), ("c_data_fd1700s_fw3", "olt_rx", 0), ("c_data_fd1700s_fw3", "olt_rx", -10001),
    ("c_data_fd1700s_fw3", "tx", -4000), ("c_data_fd1700s_fw3", "tx", -10001), ("c_data_fd1700s_fw3", "voltage", 0),
    ("c_data_fd1700s_fw3", "temp", -9900), ("c_data_fd1208s", "rx", False),
])
def test_c_data_no_reading_values_are_no_reading(model, field, raw):
    assert cdata_olt.scale_optical(model, field, raw) is None


def test_fd17_olt_rx_checks_blank_olt_rx_and_leave_the_onus_own_rx_alone():
    """Legacy blanked rx when olt_rx was -0.01 or below -100, and kept the bad olt_rx on screen."""
    assert cdata_olt.scale_optical("c_data_fd1700s_fw3", "olt_rx", -1) is None
    assert cdata_olt.scale_optical("c_data_fd1700s_fw3", "rx", -2150) == -21.5


def test_other_families_keep_legacys_pass_through():
    assert cdata_olt.scale_optical("c_data_fd1604", "olt_rx", 0) == 0.0 and cdata_olt.scale_optical("c_data_fd1604", "rx", -4000) == -40.0


def test_fields_a_family_does_not_report_are_refused_so_the_caller_shows_them_unsupported():
    assert cdata_olt.supported_fields("c_data_fd1104sn") == {"rx", "tx", "voltage"}
    with pytest.raises(ValueError):
        cdata_olt.scale_optical("c_data_fd1104sn", "temp", 4500)
    with pytest.raises(ValueError):
        cdata_olt.scale_optical("c_data_fd1208s", "olt_rx", -2600)
    with pytest.raises(KeyError):
        cdata_olt.scale_optical("c_data_fd5008_fd5016", "rx", 1)


def test_c_data_distance():
    assert cdata_olt.scale_distance("c_data_fd1700s_fw3", 0) is None and cdata_olt.scale_distance("c_data_fd1700s_fw3", 1234) == 1234
    assert cdata_olt.scale_distance("c_data_fd1604", 0) == 0 and cdata_olt.scale_distance("c_data_fd1604", None) is None


@pytest.mark.parametrize("table, text, label, reason", [
    ("gpon.last_down_reason", "dying-gasp", "PowerOff", "power_off"), ("gpon.last_down_reason", "LOS", "LOS", "los"),
    ("gpon.last_down_reason", "losi", "LOSi", "signal_failure"), ("gpon.last_down_reason", "--", "Unknown", "unknown"),
    ("gpon.last_down_reason", "", "Unknown", "unknown"), ("gpon.last_down_reason", " LOS ", "LOS", "los"),
    ("epon.last_down_reason", "dying-gasp", "PowerOff", "power_off"), ("epon.last_down_reason", "losi", "LOS", "signal_failure"),
    ("epon.last_down_reason", "", "Unknown", "unknown"),
    ("gpon.last_down_reason", "los", "los", "unknown"),  # case-sensitive, as legacy: unlisted text stays visible
])
def test_c_data_text_reasons_normalize(table, text, label, reason):
    result = cdata_olt.normalize_down_reason(table, text)
    assert (result.label, result.reason) == (label, reason)


def test_every_c_data_reason_is_a_common_reason_and_none_stays_none():
    assert all(r in COMMON_REASONS for t in cdata_olt.DOWN_REASON_TABLES.values() for _, r in t.values())
    assert cdata_olt.normalize_down_reason("gpon.last_down_reason", None) is None


# --- V-Solution ---

E3 = vsol_olt.PonPort("epon", 0, 3)
G3 = vsol_olt.PonPort("gpon", 0, 3)


@pytest.mark.parametrize("name, item", [
    ("EPON0/3", E3), ("EPON0/3:12", vsol_olt.Onu(E3, 12)), ("EPON3ONU12", vsol_olt.Onu(E3, 12)),
    ("GPON0/3:128", vsol_olt.Onu(G3, 128)), ("GPON3ONU1 description words", vsol_olt.Onu(G3, 1)), (" GPON0/3 ", G3),
])
def test_vsol_interface_names_parse(name, item):
    assert vsol_olt.parse_if_name(name) == item


@pytest.mark.parametrize("name", ["EPON1/3", "EPON1/3:12", "EPON0/3:0", "EPON0/3:65", "GPON0/3:129", "GE0/6", "epon0/3", ""])
def test_vsol_names_that_do_not_fit_are_refused(name):
    assert vsol_olt.parse_if_name(name) is None


def test_vsol_rows_map_through_the_olts_own_onu_names():
    onu = vsol_olt.Onu(E3, 12)
    names = {onu.snmp_index: onu}
    root = "1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7"
    assert vsol_olt.onu_from_oid(f"{root}.3.12", root, names) == onu
    assert vsol_olt.onu_from_oid(f"{root}.3.13", root, names) is None
    assert vsol_olt.onu_from_oid(f"{root}.0.3.12", root, names) is None
    assert vsol_olt.onu_from_oid(f"{root}9.3.12", root, names) is None


def test_vsol_legacy_ids_match_legacys_own_examples():
    assert vsol_olt.legacy_numeric_id(vsol_olt.PonPort("epon", 0, 6)) == 10006000
    assert vsol_olt.legacy_numeric_id(vsol_olt.Onu(vsol_olt.PonPort("epon", 0, 6), 4)) == 10006004


@pytest.mark.parametrize("tech, field, raw, expected", [
    ("epon", "rx", "0.0123 mW (-19.10 dBm)", -19.1), ("epon", "tx", " 1.9953 mW (3.00 dBm) ", 3.0),
    ("epon", "temp", "45.2 C", 45.2), ("epon", "voltage", "3.31 V", 3.31), ("gpon", "rx", "-21.5", -21.5),
    ("gpon", "temp", "40 C", 40.0),
])
def test_vsol_optical_strings_parse(tech, field, raw, expected):
    assert vsol_olt.scale_optical(tech, field, raw) == expected


@pytest.mark.parametrize("tech, field, raw", [
    ("epon", "rx", "-19.10"), ("epon", "rx", "0.0123 mW (N/A dBm)"), ("epon", "rx", None), ("epon", "temp", "  "),
    ("epon", "voltage", None), ("gpon", "rx", ""),
])
def test_vsol_unparsable_optical_is_no_reading(tech, field, raw):
    assert vsol_olt.scale_optical(tech, field, raw) is None


def test_vsol_unknown_fields_and_distance():
    with pytest.raises(ValueError):
        vsol_olt.scale_optical("epon", "bias", "1")
    with pytest.raises(ValueError):
        vsol_olt.scale_optical("xgspon", "rx", "1")
    assert vsol_olt.scale_distance("0") is None and vsol_olt.scale_distance("1520") == 1520 and vsol_olt.scale_distance(None) is None


@pytest.mark.parametrize("family, code, status, reason", [
    ("v1600d", 0, "offline", "unknown"), ("v1600d", 1, "online", None),
    ("v1600g", 0, "registering", None), ("v1600g", 2, "registering", None), ("v1600g", 3, "online", None),
    ("v1600g", 4, "offline", "power_off"), ("v1600g", 5, "offline", "auth_fail"), ("v1600g", 6, "offline", "unknown"),
    ("v1600g", 7, "offline", "admin_action"), ("v1600g", 8, "offline", "unknown"),
    ("v1600g", 1, "unknown", "unknown"),  # the code legacy's map skips
])
def test_vsol_statuses_normalize(family, code, status, reason):
    result = vsol_olt.normalize_status(family, code)
    assert (result.status, result.reason) == (status, reason)


def test_vsol_reasons_and_status_tables_use_common_reasons():
    assert vsol_olt.normalize_down_reason("v1600d.last_dereg_reason", 0).reason == "los"
    assert vsol_olt.normalize_down_reason("v1600d.last_dereg_reason", 1).reason == "power_off"
    assert vsol_olt.normalize_down_reason("v1600d.last_dereg_reason", 7).reason == "unknown"
    assert vsol_olt.normalize_status("v1600g", None) is None
    for table in vsol_olt.STATUS_TABLES.values():
        for _, status, reason in table.values():
            assert (reason is None) == (status != "offline") and (reason is None or reason in COMMON_REASONS)


# --- GCOM ---

GROOT = "1.3.6.1.4.1.13464.1.13.3.3.1.8"


def test_gcom_rows_parse_slot_port_and_onu():
    assert gcom_olt.onu_from_oid(f"{GROOT}.0.12.5", GROOT) == gcom_olt.Onu(0, 12, 5)
    assert gcom_olt.Onu(0, 12, 5).name == "0/12:5"


@pytest.mark.parametrize("oid", [f"{GROOT}.0.12", f"{GROOT}.0.12.5.1", f"{GROOT}.0.12.0", f"{GROOT}.0.12.65",
                                 f"{GROOT}.0.0.5", f"{GROOT}9.0.12.5", f"{GROOT}.0.x.5"])
def test_gcom_rows_that_do_not_fit_are_refused(oid):
    assert gcom_olt.onu_from_oid(oid, GROOT) is None


def test_gcoms_legacy_id_collides_so_the_identity_is_the_triple():
    a, b = gcom_olt.Onu(0, 10, 5), gcom_olt.Onu(1, 0, 5)
    assert gcom_olt.legacy_numeric_id(a) == gcom_olt.legacy_numeric_id(b) == 1010005
    assert a != b


def test_gcom_optical_status_and_registration():
    assert gcom_olt.scale_optical("rx", "-22.4") == -22.4 and gcom_olt.scale_optical("rx", "") is None
    assert gcom_olt.scale_optical("voltage", "abc") is None and gcom_olt.GCOM_OPTICAL_UNITS_CONFIRMED is False
    with pytest.raises(ValueError):
        gcom_olt.scale_optical("bias", "1")
    assert gcom_olt.scale_distance("1520") == 1520 and gcom_olt.scale_distance(None) is None
    assert gcom_olt.normalize_status(1) == ("Up", "online") and gcom_olt.normalize_status(0) == ("Down", "offline")
    assert gcom_olt.normalize_status(5) == ("Code 5", "unknown") and gcom_olt.normalize_status(None) is None
    assert gcom_olt.last_registration("-") is None and gcom_olt.last_registration(" ") is None
    assert gcom_olt.last_registration("2026-09-30 10:00:00") == "2026-09-30 10:00:00"
