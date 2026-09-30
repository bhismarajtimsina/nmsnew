INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('NoDeny API', 'nodeny_plus', 0, '{}');

INSERT INTO  system_schedule
(`key`, created_at, component_id, crontab, command, state)
VALUES
    (
        'nodeny_plus_sync_clients',
        NOW(),
        (SELECT id FROM system_components WHERE `key` = 'nodeny_plus'),
        '*/10 6-23 * * *',
        'wca nodeny_plus:sync-clients',
        'DISABLED'
    );
