DELETE FROM system_components WHERE `key` = 'router_os';

UPDATE device_models SET controller = null WHERE `key` like 'mikrotik_%'