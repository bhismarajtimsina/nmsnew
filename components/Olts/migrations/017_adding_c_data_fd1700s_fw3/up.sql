INSERT IGNORE INTO device_models
SET `key` = 'c_data_fd1700s_fw3',
    name = 'C-Data FD1700 series (FW3)',
    params = '{"show_optical_info_from_history": true}',
    vendor = 'C-Data',
    model = 'FD1700 series (FW3)',
    type = 'OLT',
    icon = '/upload/icons/cdata.png',
    controller = '\\WCC\\Olts\\Controllers\\Controller',
    pollers = '{"system": 300, "counters": 180, "optical_strength": 600,  "fdb_table": 1800, "ont_ident": 600, "sys_resources": 180, "interfaces_list": 300, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}';
