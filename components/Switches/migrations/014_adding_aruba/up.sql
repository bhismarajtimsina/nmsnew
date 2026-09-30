INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'hpe_switch', 'HPE switch', '{}', 'HPE', 'default switch', 'SWITCH', '/upload/icons/arubaos.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}');

INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'hp_j9850a', 'HP J9850A Switch', '{}', 'HPE', 'J9850A Switch', 'SWITCH', '/upload/icons/arubaos.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}');

INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'hpe_anw_jl675a', 'HPE ANW JL675A 6100', '{}', 'HPE', 'ANW JL675A 6100', 'SWITCH', '/upload/icons/arubaos.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}');
