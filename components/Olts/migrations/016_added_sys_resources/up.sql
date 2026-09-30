UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sys_resources', 120)
WHERE JSON_EXTRACT(pollers, '$.sys_resources') IS NULL and type = 'OLT';

UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sfp_optical_strength', 300)
WHERE JSON_EXTRACT(pollers, '$.sfp_optical_strength') IS NULL
  AND `key` IN (
                'bdcom_p3310b',
                'bdcom_p3310c',
                'bdcom_p3310d',
                'bdcom_p3608b',
                'bdcom_p3612_2te',
                'bdcom_p3616_2te',
                'bdcom_p36xx_series',
                'c_data_fd1601_fw3',
                'c_data_fd1604_fw3',
                'c_data_fd1608_fw3',
                'c_data_fd1616_fw3',
                'huawei_ma5603t',
                'huawei_ma5608t',
                'huawei_ma5680t',
                'huawei_ma5683t',
                'huawei_ma5801',
                'huawei_smart_ax',
                'zte_c600_fw_12',
                'zte_c610_fw_12',
                'zte_zxpon_olt_series'
    );
