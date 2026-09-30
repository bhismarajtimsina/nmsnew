INSERT INTO system_schedule (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES ('clear_old_notifications', NOW(), null, (SELECT id FROM system_components WHERE `key` = 'notifications'), '1 0 * * *', 'wca logs:clear notifications 30', 'ENABLED',1);
