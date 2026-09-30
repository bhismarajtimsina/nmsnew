INSERT INTO device_models
SET `key` = 'ati_switch_8000',
    name = 'ATI AT-8000 switch',
    params = '{}',
    vendor = 'ATI',
    model = 'AT-8000 switch',
    type = 'SWITCH',
    icon = '/upload/icons/at.png',
    controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    pollers = '{"system": 300, "counters": 120,  "interfaces_list": 550, "interfaces_status": 120, "fdb_table": 1800}';


INSERT INTO device_models
SET `key` = 'hp_procurve_j9079',
    name = 'HP PROCURVE J9079A',
    params = '{}',
    vendor = 'HP',
    model = 'J9079A',
    type = 'SWITCH',
    icon = '/upload/icons/hp.png',
    controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    pollers = '{"system": 300, "counters": 120,  "interfaces_list": 550, "interfaces_status": 120}';


INSERT INTO device_models
SET `key` = 'applynet_sw812',
    name = 'ApplyNet AN-SW812',
    params = '{}',
    vendor = 'ApplyNet',
    model = 'AN-SW812',
    type = 'SWITCH',
    icon = '/upload/icons/applynet.png',
    controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    pollers = '{"system": 300, "counters": 120,  "interfaces_list": 550, "interfaces_status": 120}';
