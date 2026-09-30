INSERT IGNORE INTO c_events_alertmanager_rules (created_at, updated_at, group_name, alert_name, expression, `for`, severity, annotation_summary, annotation_description, enabled, internal) VALUES
( NOW(),
 NOW(),
 'analytics',
 'bad_optical_sfp_rx',
 'optical_rx{iface_type != "ONU"} > 5 or optical_rx{iface_type != "ONU"} < -25',
 '5m',
 'warning',
 'Bad SFP signal',
 'The RX signal level on SFP {{ $labels.iface_name }}, IP {{ $labels.ip }} has exceeded the limit - {{ humanize $value }}dBm',
 1,
 1
);
UPDATE c_events_alertmanager_rules
SET expression = 'optical_rx{iface_type="ONU"} > -10 or optical_rx{iface_type = "ONU"} < -28'
WHERE alert_name = 'bad_optical_level_rx';
UPDATE c_events_alertmanager_rules
SET expression = 'optical_olt_rx{iface_type="ONU"} > -10 or optical_olt_rx{iface_type = "ONU"} < -28'
WHERE alert_name = 'bad_optical_level_olt_rx';

