INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('MikBill integration', 'mikbill_integration', false, '{}');

INSERT INTO  system_schedule
(`key`, created_at, component_id, crontab, command, state)
VALUES
    (
        'mikbill_sync_clients',
        NOW(),
        (SELECT id FROM system_components WHERE `key` = 'mikbill_integration'),
        '10 6-22 * * *',
        'wca mikbill_integration:sync-clients',
        'DISABLED'
    );
