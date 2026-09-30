INSERT INTO system_schedule
    (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES ('pinger_recalc_exporter_statuses',
        NOW(),
        NOW(),
        (select id from system_components WHERE `key` = 'pinger'),
        '*/2 * * * *',
        'wca pinger:update-exporter-statuses',
        'ENABLED',
        1
);