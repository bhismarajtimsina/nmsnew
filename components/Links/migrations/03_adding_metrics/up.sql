INSERT INTO system_schedule (
    `key`, created_at, latest, component_id, crontab, command, state, editable
) VALUES (
             'links_prom_exporter',
             NOW(),
             null,
             (SELECT id FROM system_components WHERE `key` = 'links'),
             '*/2 * * * *',
             'wca links:calc-link-utilization',
             'ENABLED',
             1
         );

INSERT IGNORE INTO c_events_alertmanager_rules (created_at, updated_at, group_name, alert_name, expression, `for`, severity, annotation_summary, annotation_description, enabled, internal) VALUES (NOW(), NOW(), 'links', 'high_link_utilization', 'max_over_time(link_utilization_prc[15m]) > 20', '15m', 'warning', 'High link utilization', 'Link {{ $labels.src_device_ip }} -> {{ $labels.dest_device_ip }} utilization is too high in one direction - {{ humanize $value }}% ', 1, 1);
