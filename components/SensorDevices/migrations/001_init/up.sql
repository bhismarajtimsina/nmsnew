INSERT IGNORE INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Sensor devices', 'sensor_devices', 1, null);
INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES
    ( 'equicom_ping3',
     'Ping3',
     '{}',
     'Equicom',
     'Ping3',
     'SENSOR',
     '/upload/icons/equicom.gif',
     '\\WCC\\SensorDevices\\Controllers\\Controller',
     '{"system": 300, "sensors_data": 300}'
    );
INSERT INTO c_events_alertmanager_rules (created_at, updated_at, group_name, alert_name, expression, `for`, severity, annotation_summary, annotation_description, enabled) VALUES (NOW(), NOW(), 'sensors', 'sensor_test_alert', 'device_sensor{name="Temperature"} > 18', '10m', 'info', 'High temperature', 'High temp - {{$value}},  on sensor {{$labels.ip}}', 1);
INSERT INTO c_events_alertmanager_rules (created_at, updated_at, group_name, alert_name, expression, `for`, severity, annotation_summary, annotation_description, enabled) VALUES (NOW(), NOW(), 'sensors', 'sensors_low_battery_level', 'device_sensor{type="analog_line", name="Battery"}  < 44  ', '10m', 'warning', 'Low battery level', 'Battery voltage {{humanize $value}} on sensor {{$labels.ip}} is very low', 1);
INSERT INTO c_events_alertmanager_rules (created_at, updated_at, group_name, alert_name, expression, `for`, severity, annotation_summary, annotation_description, enabled) VALUES (NOW(), NOW(), 'sensors', 'sensors_no_power_supply', 'device_sensor{type="power_sensor"} == 0', '5m', 'warning', 'No power supply', 'No power on sensor {{ $labels.ip }}', 1);
