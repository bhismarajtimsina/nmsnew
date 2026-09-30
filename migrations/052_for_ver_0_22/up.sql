alter table device_models
    modify type enum ('SWITCH', 'OLT', 'ONU', 'ROUTER', 'SENSOR', 'UNKNOWN') default 'SWITCH' not null;

INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ('icmp_power_control', 'ICMP power control', '{}', 'Unknown', 'Unknown', 'UNKNOWN',
        '/upload/icons/high_voltage.png', null, '{}');

INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ('default_icmp', 'ICMP pinged device', '{}', 'Unknown', 'Unknown', 'UNKNOWN', '/upload/icons/router.png', null,
        '{}');

INSERT INTO system_schedule (`key`, created_at, latest, component_id, crontab, command, state, editable)
VALUES ('clear_expired_sessions', NOW(), null, null, '1 0 * * *', 'wca logs:clear expired-sessions 30', 'ENABLED', 1);


