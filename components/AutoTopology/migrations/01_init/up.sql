INSERT INTO system_components(name, `key`,  enabled,   configuration)
VALUES ('Auto links (network tree)', 'auto_topology', 1, null);

INSERT INTO  system_schedule
(`key`, created_at, component_id, crontab, command, state)
VALUES
    (
        'auto_topology_scan',
        NOW(),
        (SELECT id FROM system_components WHERE `key` = 'auto_topology'),
        '0 */3 * * *',
        'wca auto_topology:scan --schedule',
        'ENABLED'
    );

INSERT INTO  system_schedule
(`key`, created_at, component_id, crontab, command, state)
VALUES
    (
        'auto_topology_clearing',
        NOW(),
        (SELECT id FROM system_components WHERE `key` = 'auto_topology'),
        '0 0 * * *',
        'wca auto_topology:clear-not-actual-links',
        'ENABLED'
    );
