INSERT INTO support.system_schedule
    (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES
    ('events_apply_rules_reboot', NOW(), null, (SELECT id FROM system_components WHERE `key`= 'events'), '@reboot', 'sleep 60 && wca events:apply-rules', 'ENABLED', 0),
    ('events_apply_rules_cycle', NOW(), null, (SELECT id FROM system_components WHERE `key`= 'events'), '0 * * * *', 'wca events:apply-rules', 'ENABLED', 1);

