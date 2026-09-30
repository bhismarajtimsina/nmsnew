INSERT INTO support.system_schedule (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES ('recalc_user_sessions', '2022-04-22 22:34:16', '2022-04-22 22:34:19', null, '*/2 * * * *',
        'wca system:recalc-sessions', 'ENABLED', 0);

