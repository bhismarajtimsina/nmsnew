UPDATE device_models
SET pollers = '{
  "system": 300,
  "counters": 60,
  "interfaces_list": 300,
  "interfaces_status": 60,
  "sys_resources": 180,
  "arp_table": 600,
  "bgp_sessions": 300
}'
WHERE `key` like 'mikrotik_%';
