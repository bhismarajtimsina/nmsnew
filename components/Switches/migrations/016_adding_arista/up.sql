
INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'arista_default', 'Arista', '{}', 'Arista', 'Default', 'SWITCH', '/upload/icons/arista.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}');
