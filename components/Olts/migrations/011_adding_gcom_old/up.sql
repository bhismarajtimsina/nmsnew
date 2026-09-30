INSERT IGNORE INTO device_models
SET `key` = 'gcom_el5610_series_old',
    name = 'GCOM EPON series (OLD)',
    params = '{"show_optical_info_from_history": true}',
    vendor = 'GCOM',
    model = 'EPON series (OLD)',
    type = 'OLT',
    icon = '/upload/icons/gcom_logo.png',
    controller = '\\WCC\\Olts\\Controllers\\Controller',
    pollers = '{"system": 300, "counters": 180, "ont_ident": 600, "sys_resources": 180, "interfaces_list": 300, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}';
