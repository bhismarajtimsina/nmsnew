INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'swos_default', 'SwOS Default', '{}', 'Mikrotik', 'SwOS', 'SWITCH', '/upload/icons/mikrotik.svg', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}');
