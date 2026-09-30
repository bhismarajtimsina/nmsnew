UPDATE  device_models t
SET  pollers = '{"system": 300, "counters": 120, "fdb_table": 600,   "interfaces_list": 1800, "interfaces_status": 120}'
WHERE `key` = 'dlink_general_switch';
