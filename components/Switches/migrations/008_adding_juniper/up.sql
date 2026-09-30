INSERT INTO device_models
SET `key` = 'juniper_qfx51xx_series',
    name = 'Juniper QFX5100 series',
    params = '{}',
    vendor = 'Juniper',
    model = 'QFX5100 series',
    type = 'SWITCH',
    icon = '/upload/icons/juniper.webp',
    controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    pollers = '{"system": 300, "counters": 120,  "interfaces_list": 550, "interfaces_status": 120}';
