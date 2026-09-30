"""Pure notification-generation decisions: no database, no channel. Ported from the real EventGenerator.php /
NotificationSender.php - see app/notifications/generate.py's docstring for the one real legacy bug deliberately not
reproduced (the per-contact ignore-list key mismatch)."""
from datetime import datetime, timedelta, timezone

from app.notifications.generate import Contact, EventConfig, build_drafts, is_send_canceled, resolved_send_at, wants_event

NOW = datetime(2026, 1, 1, tzinfo=timezone.utc)


def contact(id="c1", severities=("info", "warning", "critical"), ignore=()):
    return Contact(id=id, severities=frozenset(severities), ignore_event_names=frozenset(ignore))


def cfg(**overrides):
    base = dict(enabled=True, delay_before_send_seconds=0, send_resolved=True, ignored_device_ids=frozenset())
    base.update(overrides)
    return EventConfig(**base)


def test_a_contact_only_wants_severities_it_subscribed_to():
    c = contact(severities=["critical"])
    assert wants_event(c, event_name="x", severity="critical") is True
    assert wants_event(c, event_name="x", severity="warning") is False


def test_a_contact_ignoring_an_event_name_is_skipped_regardless_of_severity():
    c = contact(ignore=["interface_is_down"])
    assert wants_event(c, event_name="interface_is_down", severity="critical") is False


def test_a_disabled_event_config_notifies_nobody():
    drafts = build_drafts(event_name="x", severity="warning", device_id=None, resolved=False,
                          cfg=cfg(enabled=False), contacts=[contact()], now=NOW)
    assert drafts == []


def test_an_ignored_device_notifies_nobody_for_that_event():
    drafts = build_drafts(event_name="x", severity="warning", device_id="dev-1", resolved=False,
                          cfg=cfg(ignored_device_ids=frozenset({"dev-1"})), contacts=[contact()], now=NOW)
    assert drafts == []


def test_a_device_less_event_is_unaffected_by_the_ignored_devices_list():
    drafts = build_drafts(event_name="x", severity="warning", device_id=None, resolved=False,
                          cfg=cfg(ignored_device_ids=frozenset({"dev-1"})), contacts=[contact()], now=NOW)
    assert len(drafts) == 1


def test_resolved_is_skipped_entirely_when_send_resolved_is_off():
    drafts = build_drafts(event_name="x", severity="warning", device_id=None, resolved=True,
                          cfg=cfg(send_resolved=False), contacts=[contact()], now=NOW)
    assert drafts == []


def test_an_alert_is_delayed_by_the_configured_seconds_a_resolved_notification_is_not():
    alert = build_drafts(event_name="x", severity="warning", device_id=None, resolved=False,
                         cfg=cfg(delay_before_send_seconds=60), contacts=[contact()], now=NOW)
    assert alert[0].send_at == NOW + timedelta(seconds=60) and alert[0].type == "alert"

    resolved = build_drafts(event_name="x", severity="warning", device_id=None, resolved=True,
                            cfg=cfg(delay_before_send_seconds=60), contacts=[contact()], now=NOW)
    assert resolved[0].send_at == NOW and resolved[0].type == "resolved"


def test_one_draft_per_eligible_contact_only():
    contacts = [contact("c1", severities=["critical"]), contact("c2", severities=["warning"])]
    drafts = build_drafts(event_name="x", severity="warning", device_id=None, resolved=False, cfg=cfg(), contacts=contacts, now=NOW)
    assert {d.contact_id for d in drafts} == {"c2"}


def test_resolved_send_at_defaults_to_now_with_no_previous_alert():
    assert resolved_send_at(NOW, None) == NOW


def test_resolved_send_at_is_always_ten_seconds_after_the_previous_alert_even_if_that_is_in_the_past():
    previous = NOW - timedelta(minutes=5)
    assert resolved_send_at(NOW, previous) == previous + timedelta(seconds=10)


def test_a_resolved_notification_is_canceled_if_its_alert_was_canceled():
    assert is_send_canceled(notification_type="resolved", event_resolved=True, previous_status="canceled",
                            previous_exists=True, check_previous_message=False) is True


def test_an_alert_is_canceled_if_the_event_already_resolved_before_send_time():
    assert is_send_canceled(notification_type="alert", event_resolved=True, previous_status=None,
                            previous_exists=False, check_previous_message=False) is True


def test_an_alert_is_not_canceled_while_its_event_is_still_open():
    assert is_send_canceled(notification_type="alert", event_resolved=False, previous_status=None,
                            previous_exists=False, check_previous_message=False) is False


def test_check_previous_message_off_lets_an_orphaned_resolved_through():
    # The real production default (NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE='' in .env).
    assert is_send_canceled(notification_type="resolved", event_resolved=True, previous_status=None,
                            previous_exists=False, check_previous_message=False) is False


def test_check_previous_message_on_cancels_a_resolved_with_no_previous_alert():
    assert is_send_canceled(notification_type="resolved", event_resolved=True, previous_status=None,
                            previous_exists=False, check_previous_message=True) is True


def test_check_previous_message_on_cancels_a_resolved_whose_alert_never_actually_sent():
    assert is_send_canceled(notification_type="resolved", event_resolved=True, previous_status="failed",
                            previous_exists=True, check_previous_message=True) is True


def test_check_previous_message_on_allows_a_resolved_whose_alert_was_sent():
    assert is_send_canceled(notification_type="resolved", event_resolved=True, previous_status="sent",
                            previous_exists=True, check_previous_message=True) is False
