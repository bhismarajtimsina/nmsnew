INSERT INTO  system_schedule (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES ('events_retention', DEFAULT, null, (SELECT id FROM system_components WHERE `key` = 'events'), '10 0 * * * ', 'wca events:retention 30', 'ENABLED', 1);

