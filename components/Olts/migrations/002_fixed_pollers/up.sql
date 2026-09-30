UPDATE device_models t
SET t.pollers = '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600,  "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180}'
WHERE t.key like 'zte_c%';

UPDATE device_models t
SET t.pollers = '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600,  "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}'
WHERE t.`key` like 'huawei_ma%';
