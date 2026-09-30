alter table collector_processing modify collector enum('counters', 'system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident') null;

UPDATE device_models SET collectors = JSON_ARRAY_APPEND(collectors, '$', 'counters')
WHERE `key` like 'dlink_%' or
      `key` like 'zte_%' or
      `key` like 'c_data_%';
