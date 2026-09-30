DELETE FROM device_models WHERE `key` in ('zte_c610_121', 'zte_zxpon_olt_series');

INSERT IGNORE INTO device_models (`key`,name, params,vendor,model,icon,controller,pollers, type)  VALUES
('zte_zxpon_olt_series', 'ZTE C600 series',
 '{"show_optical_info_from_history": true}',
 'ZTE',
 'C600 series',
 '/upload/icons/ZTE_logo.png',
 '\\WCC\\Olts\\Controllers\\Controller',
 '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600,"interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180}',
 'OLT'
 ),
('zte_c600_fw_12', 'ZTE C600 (FW 1.2)',
 '{"show_optical_info_from_history": true}',
 'ZTE',
 'C600',
 '/upload/icons/zte_c600.png',
 '\\WCC\\Olts\\Controllers\\Controller',
 '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600,"interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180}',
 'OLT'
 ),
('zte_c610_fw_12', 'ZTE C610 (FW 1.2)',
 '{"show_optical_info_from_history": true}',
 'ZTE',
 'C610',
 '/upload/icons/zte_c610.png',
 '\\WCC\\Olts\\Controllers\\Controller',
 '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600,   "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180}',
 'OLT'
);