INSERT IGNORE INTO device_models
(`key`, name, params, vendor, model, type, icon, controller, pollers) VALUES
('mikrotik_crs',
 'Mikrotik CRS',
 '{}',
 'Mikrotik',
 'CRS',
 'SWITCH',
 '/upload/icons/mikrotik.svg',
 '\\WCC\\Switches\\Controllers\\SwitchesController',
 '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}'
),
('raisecom_iscom',
 'Raisecom ISCOM',
 '{}',
 'Raisecom',
 'ISCOM',
 'SWITCH',
 '/upload/icons/raisecom.png',
 '\\WCC\\Switches\\Controllers\\SwitchesController',
 '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}'
);
