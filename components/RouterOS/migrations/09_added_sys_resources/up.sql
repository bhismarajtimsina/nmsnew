UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sys_resources', 120)
WHERE JSON_EXTRACT(pollers, '$.sys_resources') IS NULL and type = 'ROUTEROS';

UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sfp_optical_strength', 300)
WHERE JSON_EXTRACT(pollers, '$.sfp_optical_strength') IS NULL
  AND `key` IN (
                'mikrotik_ccr1009',
                'mikrotik_ccr1016',
                'mikrotik_ccr1036',
                'mikrotik_crs317_1g_16s',
                'mikrotik_crs328_4c_20s_4s',
                'mikrotik_rb2011uias_2hnd',
                'mikrotik_rb3011uias',
                'mikrotik_rb4011',
                'mikrotik_rb750',
                'mikrotik_rb750gr3',
                'mikrotik_router_os'
    );