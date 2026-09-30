INSERT INTO system_schedule (
`key`, created_at, latest, component_id, crontab, command, state, editable
) VALUES (
          'analytics_prom_exporter',
          NOW(),
          null,
          (SELECT id FROM system_components WHERE `key` = 'analytics'),
          '*/2 * * * *',
          'wca analytics:duplicated-mac-addresses && wca analytics:duplicated-ont-idents',
          'ENABLED',
          1
);
