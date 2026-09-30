INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Userside API integration', 'userside_integration', false, '{}');

INSERT INTO  system_schedule
(`key`, created_at, component_id, crontab, command, state)
VALUES
    (
        'userside_integration_sync',
        NOW(),
        (SELECT id FROM system_components WHERE `key` = 'userside_integration'),
        '10 6-22 * * *',
        'wca userside_integration:sync',
        'DISABLED'
    );
