INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Working with routers (L3 devices)', 'routers', 1, null);

INSERT IGNORE INTO device_models
SET `key` = 'juniper_jnp204_series',
    name = 'Juniper JNP204 series',
    params = '{}',
    vendor = 'Juniper',
    model = 'JNP204 series',
    type = 'ROUTER',
    icon = '/upload/icons/juniper.webp',
    controller = '\\WCC\\Routers\\Controllers\\RouterController',
    pollers = '{"system": 300, "counters": 120, "interfaces_list": 1800, "interfaces_status": 120}';

INSERT IGNORE INTO device_models
SET `key` = 'extreme_xos',
    name = 'Extreme XOS',
    params = '{}',
    vendor = 'Extreme',
    model = 'XOS',
    type = 'ROUTER',
    icon = '/upload/icons/extreme.png',
    controller = '\\WCC\\Routers\\Controllers\\RouterController',
    pollers = '{"system": 300, "counters": 120, "interfaces_list": 1800, "interfaces_status": 120}';


UPDATE device_models SET type = 'ROUTEROS' WHERE vendor = 'Mikrotik' and type = 'ROUTER';
UPDATE device_models SET type = 'ROUTER', controller = '\\WCC\\Routers\\Controllers\\RouterController' WHERE `key` = 'juniper_jnp204_series';


