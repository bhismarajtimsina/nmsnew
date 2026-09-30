
INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'dcn_default', 'DCN', '{}', 'DCN', 'Default', 'SWITCH', '/upload/icons/dcn.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}');
