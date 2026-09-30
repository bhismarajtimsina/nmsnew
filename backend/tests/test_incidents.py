"""Incident grouping, ported from the real, production-validated GetIncidentsAction.php. Synthetic events only,
reproducing each of the four grouping kinds the real code distinguishes."""
from datetime import datetime, timedelta, timezone

from app.alerting.incidents import OpenEvent, group

T0 = datetime(2026, 9, 29, 12, 0, tzinfo=timezone.utc)


def ev(id, name, device_id, at=T0, severity="warning", **labels):
    return OpenEvent(id=id, name=name, severity=severity, device_id=device_id, labels=labels, occurred_at=at)


def test_a_device_down_alarm_folds_every_other_open_event_on_that_device_into_one_incident():
    rows = [
        ev("1", "pinger_host_down", "dev-1", ip="10.0.0.1"),
        ev("2", "sys_cpu_highload", "dev-1", severity="critical"),
        ev("3", "bad_optical_level_rx", "dev-1", iface_name="0/1:1", iface_type="ONU"),
    ]
    incidents = group(rows)
    assert len(incidents) == 1
    incident = incidents[0]
    assert incident.kind == "device" and incident.device_id == "dev-1" and incident.events == 3
    assert incident.severity == "critical"  # the worst of the three
    assert incident.alarms == {"pinger_host_down": 1, "sys_cpu_highload": 1, "bad_optical_level_rx": 1}


def test_several_onus_on_one_pon_port_are_one_incident_not_one_per_subscriber():
    rows = [ev(str(i), "bad_optical_level_rx", "olt-1", iface_name=f"0/3:{i}", iface_type="ONU") for i in range(1, 41)]
    incidents = group(rows)
    assert len(incidents) == 1 and incidents[0].kind == "pon_port" and incidents[0].where == "0/3" and incidents[0].events == 40


def test_different_pon_ports_on_the_same_device_are_separate_incidents():
    rows = [ev("1", "bad_optical_level_rx", "olt-1", iface_name="0/1:1", iface_type="ONU"),
            ev("2", "bad_optical_level_rx", "olt-1", iface_name="0/2:1", iface_type="ONU")]
    incidents = group(rows)
    assert {i.where for i in incidents} == {"0/1", "0/2"}


def test_several_alarms_on_one_physical_interface_are_one_incident():
    rows = [ev("1", "interface_is_down", "sw-1", iface_name="Gi0/1", iface_type="GE"),
            ev("2", "iface_increase_in_errors", "sw-1", iface_name="Gi0/1", iface_type="GE")]
    incidents = group(rows)
    assert len(incidents) == 1 and incidents[0].kind == "interface" and incidents[0].where == "Gi0/1" and incidents[0].events == 2


def test_events_with_no_interface_group_by_device_and_alarm_name():
    rows = [ev("1", "sys_cpu_highload", "sw-1"), ev("2", "sys_cpu_highload", "sw-1"), ev("3", "sys_cpu_highload", "sw-2")]
    incidents = group(rows)
    assert len(incidents) == 2 and {i.events for i in incidents} == {2, 1}
    assert all(i.kind == "alarm" for i in incidents)


def test_events_with_no_device_at_all_still_group_sensibly():
    rows = [ev("1", "system_not_enough_pollers", None), ev("2", "system_not_enough_pollers", None)]
    incidents = group(rows)
    assert len(incidents) == 1 and incidents[0].events == 2 and incidents[0].device_id is None


def test_incidents_are_sorted_by_severity_then_by_event_count():
    rows = [
        ev("1", "a", "d1", severity="info"),
        ev("2", "b", "d2", severity="critical"),
        *[ev(str(i), "c", "d3", severity="warning") for i in range(3, 8)],  # 5 events, warning
    ]
    incidents = group(rows)
    assert [i.severity for i in incidents] == ["critical", "warning", "info"]


def test_started_and_latest_timestamps_span_the_whole_incident():
    rows = [ev("1", "a", "d1", at=T0), ev("2", "a", "d1", at=T0 + timedelta(minutes=5)), ev("3", "a", "d1", at=T0 - timedelta(minutes=2))]
    incident = group(rows)[0]
    assert incident.started_at == T0 - timedelta(minutes=2) and incident.latest_at == T0 + timedelta(minutes=5)


def test_event_ids_are_capped_so_a_huge_incident_does_not_return_everything():
    rows = [ev(str(i), "a", "d1") for i in range(500)]
    incident = group(rows)[0]
    assert incident.events == 500 and len(incident.event_ids) == 200


def test_an_onu_labelled_interface_without_a_colon_falls_back_to_plain_interface_grouping():
    rows = [ev("1", "bad_optical_level_rx", "olt-1", iface_name="weird-name", iface_type="ONU")]
    incident = group(rows)[0]
    assert incident.kind == "interface" and incident.where == "weird-name"


def test_grouping_an_empty_list_returns_no_incidents():
    assert group([]) == []
