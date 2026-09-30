INSERT INTO device_models
 SET `key` = 'v_solution_v1600g',
     name = 'V-Solution V1600G series',
     params = '{"show_optical_info_from_history": true}',
     vendor = 'V-Solution',
     model = 'V1600G',
     type = 'OLT',
     icon = '/upload/icons/v-sol-general.png',
     controller = '\\WCC\\Olts\\Controllers\\Controller',
     pollers = '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600, "sys_resources": 180, "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}';

INSERT INTO device_models
 SET `key` = 'v_solution_v1600g1b',
     name = 'V-Solution V1600G1B',
     params = '{"show_optical_info_from_history": true}',
     vendor = 'V-Solution',
     model = 'V1600G1B',
     type = 'OLT',
     icon = '/upload/icons/v-sol-general.png',
     controller = '\\WCC\\Olts\\Controllers\\Controller',
     pollers = '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600, "sys_resources": 180, "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}';
