UPDATE device_models SET pollers = '["interfaces_status", "counters", "interfaces_list", "bgp_sessions", "arp_table"]' WHERE `key` like 'mikrotik_%';


