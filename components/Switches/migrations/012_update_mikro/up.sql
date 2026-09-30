UPDATE device_models
SET controller = '\\WCC\\Switches\\Controllers\\SwitchesController',
    type = 'SWITCH',
    pollers = '{
      "system": 300,
      "counters": 120,
      "fdb_table": 600,
      "interfaces_list": 1800,
      "interfaces_status": 120
    }'
WHERE model like '%CRS%';
