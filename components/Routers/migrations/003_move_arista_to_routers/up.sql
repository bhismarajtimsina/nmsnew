INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'arista_default', 'Arista', '{}', 'Arista', 'Default', 'ROUTER', '/upload/icons/arista.png', '\\WCC\\Routers\\Controllers\\RouterController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}');

INSERT IGNORE  INTO device_models
SET `key` = 'dell_networking_os',
    name = 'Dell Networking OS',
    params = '{}',
    vendor = 'Dell',
    model = 'Networking OS',
    type = 'ROUTER',
    icon = '/upload/icons/dell.png',
    controller = '\\WCC\\Routers\\Controllers\\RouterController',
    pollers = '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}';

INSERT IGNORE INTO device_models
SET `key` = 'dell_emc_networking_os',
    name = 'Dell EMC Networking OS',
    params = '{}',
    vendor = 'Dell',
    model = 'EMC Networking OS',
    type = 'ROUTER',
    icon = '/upload/icons/dell.png',
    controller = '\\WCC\\Routers\\Controllers\\RouterController',
    pollers = '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}';


UPDATE device_models SET type = 'ROUTER', controller = '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}' WHERE `key` in ('arista_default', 'dell_networking_os', 'dell_emc_networking_os');

UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sfp_optical_strength', 600)
WHERE JSON_EXTRACT(pollers, '$.sfp_optical_strength') IS NULL and type = 'ROUTER';

