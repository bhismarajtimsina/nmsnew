"""Turns a stored event into queued notification rows, ported from the real `EventGenerator.php` and
`AbstractNotificationGenerator.php`. No channel is contacted here: deciding who gets notified, when, and whether a
notification pairs with a previous one is a separate step from actually sending it - the same separation the legacy
system has between generating a `c_notifications` row and a later, independent sender process
(`components/Notifications/Console/NotificationSender.php`) delivering it.

A real legacy bug intentionally not ported: `EventGenerator::getContacts` reads
`$contact->getParams()['ignore_events']`, but `NotificationContact::setParams` and
`NotificationsContactsStorage::prepareParams` only ever populate the key `'ignore_notifications'` - the per-contact
ignore-list that line reads has never actually matched anything in production. This implements the evidently
intended behavior (the ignore list works), not the shipped no-op.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta


@dataclass(frozen=True)
class EventConfig:
    enabled: bool
    delay_before_send_seconds: int
    send_resolved: bool
    ignored_device_ids: frozenset[str] = frozenset()


@dataclass(frozen=True)
class Contact:
    id: str
    severities: frozenset[str]
    ignore_event_names: frozenset[str] = frozenset()


@dataclass(frozen=True)
class NotificationDraft:
    contact_id: str
    type: str  # "alert" | "resolved"
    send_at: datetime


def wants_event(contact: Contact, *, event_name: str, severity: str) -> bool:
    if severity not in contact.severities:
        return False
    return event_name not in contact.ignore_event_names


def build_drafts(
    *, event_name: str, severity: str, device_id: str | None, resolved: bool,
    cfg: EventConfig, contacts: list[Contact], now: datetime,
) -> list[NotificationDraft]:
    """One draft per eligible contact. Callers resolve eligible contacts themselves (device-group scope, or
    `notifications.send_global` for a device-less event - `AbstractNotificationGenerator::getUsers`) before calling
    this; it only decides whether *this* event, for *this* config, reaches *these* contacts."""
    if not cfg.enabled:
        return []
    if device_id is not None and device_id in cfg.ignored_device_ids:
        return []
    if resolved and not cfg.send_resolved:
        return []

    send_at = now if resolved else now + timedelta(seconds=cfg.delay_before_send_seconds)
    kind = "resolved" if resolved else "alert"
    return [
        NotificationDraft(contact_id=c.id, type=kind, send_at=send_at)
        for c in contacts
        if wants_event(c, event_name=event_name, severity=severity)
    ]


def resolved_send_at(now: datetime, previous_alert_send_at: datetime | None) -> datetime:
    """A resolved notification is scheduled at `now` by default, but a previous alert for the same (event, contact)
    pushes it to exactly 10 seconds after that alert's own send time - even if that lands in the past, in which case
    the sender fires it on its next pass. This keeps the pair in order (the alert is never sent after its own
    resolution) and gives a threaded channel like Telegram time to have the alert's message id to reply to."""
    if previous_alert_send_at is None:
        return now
    return previous_alert_send_at + timedelta(seconds=10)


def is_send_canceled(
    *, notification_type: str, event_resolved: bool, previous_status: str | None,
    previous_exists: bool, check_previous_message: bool,
) -> bool:
    """Ported from `NotificationSender::isSendCanceled`. `check_previous_message` is
    `NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE`, off in the real production `.env` - stricter dedup that most callers
    should leave disabled unless they have decided otherwise."""
    if notification_type == "resolved" and previous_status == "canceled":
        return True
    if notification_type == "alert" and event_resolved:
        return True
    if check_previous_message and notification_type == "resolved":
        if not previous_exists:
            return True
        if previous_status != "sent":
            return True
    return False
