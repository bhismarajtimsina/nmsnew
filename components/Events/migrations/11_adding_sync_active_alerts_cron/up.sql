INSERT INTO system_schedule (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES ('events_sync_active_alerts', DEFAULT, null, (SELECT id FROM system_components WHERE `key` = 'events'), '*/10 * * * *', 'sleep 300 && wca events:sync-active-alerts', 'ENABLED', 1);
