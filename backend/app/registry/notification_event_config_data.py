"""Real per-event notification config, from the legacy migrations
(components/Notifications/migrations/01_init/up.sql and 02_added_new_notifications/up.sql).

The first four rows carry their real legacy id from 01_init's explicit INSERT. The last three were added by
02_added_new_notifications's `INSERT IGNORE INTO ... (created_at, event_name)`, with no explicit id - the real
legacy id is whatever MySQL's auto_increment assigned live, not recoverable from the migration text alone, and left
None rather than guessed.
"""
from __future__ import annotations

# (event_name, enabled, delay_before_send_seconds, send_resolved, check_uplink, legacy_id)
NOTIFICATION_EVENT_CONFIGS: list[tuple[str, bool, int, bool, bool, int | None]] = [
    ("rx_signal_deteriorated", True, 0, True, False, 1),
    ("pinger_host_down", True, 60, True, False, 2),
    ("mass_interfaces_down", True, 0, True, False, 3),
    ("interface_is_down", True, 0, True, False, 4),
    ("bgp_session_restarted", True, 0, True, False, None),
    ("bgp_session_down", True, 0, True, False, None),
    ("pon_box_status", True, 0, True, False, None),
]
