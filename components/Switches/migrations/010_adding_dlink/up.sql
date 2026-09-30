INSERT IGNORE  INTO device_models
SET `key` = 'dlink_dgs_3120_24sc_bx',
    name = 'D-Link DGS-3120-24SC/Bx',
    params = '{}',
    vendor = 'D-Link',
    model = 'DGS-3120-24SC/Bx',
    type = 'SWITCH',
    icon = '/upload/icons/dlink.png',
    controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    pollers = '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120}';

