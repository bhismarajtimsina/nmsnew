INSERT INTO device_models
SET `key` = 'huawei_quidway_S9300_series',
    name = 'Huawei S9300 series',
    params = '{}',
    vendor = 'Huawei',
    model = 'S9300 series',
    type = 'SWITCH',
    icon = '/upload/icons/huawei.png',
    controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    pollers = '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}';

UPDATE device_models SET vendor = 'D-Link' WHERE `key` like 'dlink_%' or `key` like 'd_link_%';