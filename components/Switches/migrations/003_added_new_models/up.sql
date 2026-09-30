INSERT IGNORE INTO device_models
    (`key`, name, params, vendor, model, type, icon, controller, pollers) VALUES
 ( 'cisco_general_switch_sg', 'Cisco switch', '{}', 'Cisco', 'general switch', 'SWITCH', '/upload/icons/cisco.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}'),
 ( 'ubnt_edge_switch', 'UBNT EdgeSwitch', '{}', 'UBNT', 'EdgeSwitch', 'SWITCH', '/upload/icons/ubnt-logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}'),
 ( 'tplink_jetstream_switch', 'TP-Link JetStream Switch', '{}', 'TP-Link', 'JetStream', 'SWITCH', '/upload/icons/TP-LINK.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120,  "interfaces_list": 1800, "interfaces_status": 120}'),
 ( 'tplink_smart_switch', 'TP-Link Smart Switch', '{}', 'TP-Link', 'Smart switch', 'SWITCH', '/upload/icons/TP-LINK.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}'),
 ( 'hp_arubaos_switch', 'HP Switch (ArubaOS)', '{}', 'HP', 'ArubaOS switch', 'SWITCH', '/upload/icons/arubaos.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}'),
 ( 'tplink_general_switch', 'TP-Link switch', '{}', 'TP-Link', 'general switch', 'SWITCH', '/upload/icons/TP-LINK.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120,  "interfaces_list": 1800, "interfaces_status": 120}');

