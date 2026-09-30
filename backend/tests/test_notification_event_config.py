"""The real per-event notification config (app/registry/notification_event_config_data.py) and its seeding."""
from app.registry.notification_event_config_data import NOTIFICATION_EVENT_CONFIGS
from app.seed import seed


def test_the_real_config_count_and_a_known_delay():
    assert len(NOTIFICATION_EVENT_CONFIGS) == 7
    by_name = {name: row for name, *row in NOTIFICATION_EVENT_CONFIGS}
    assert by_name["pinger_host_down"][1] == 60  # the real production debounce for a device-down alarm
    assert by_name["interface_is_down"][1] == 0


def test_every_event_name_is_unique():
    names = [row[0] for row in NOTIFICATION_EVENT_CONFIGS]
    assert len(names) == len(set(names))


async def test_reseeding_keeps_an_operators_edit(db):
    before = await db.fetchval("select count(*) from notification_event_config")
    await db.execute("update notification_event_config set enabled = false, delay_before_send_seconds = 999 where event_name = 'interface_is_down'")
    await seed(db, admin_username="boot", admin_password="Boot-Strap-Password-1!")
    assert await db.fetchval("select count(*) from notification_event_config") == before
    row = await db.fetchrow("select enabled, delay_before_send_seconds from notification_event_config where event_name = 'interface_is_down'")
    assert row["enabled"] is False and row["delay_before_send_seconds"] == 999
