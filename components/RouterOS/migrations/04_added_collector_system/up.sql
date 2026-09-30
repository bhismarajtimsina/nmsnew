UPDATE device_models
SET pollers = '[
  "system",
  "interfaces_status",
  "counters",
  "interfaces_list",
  "bgp_sessions",
  "arp_table",
  "sys_resources"
]',
    params = '{"collect_interval": 300}'
WHERE `key` like 'mikrotik%';
