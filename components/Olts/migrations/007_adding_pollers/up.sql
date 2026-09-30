UPDATE device_models
SET pollers = '{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600, "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180, "cards_status": 120}'
WHERE `key` in (
    'huawei_ma5603t',
    'huawei_ma5608t',
    'huawei_ma5680t',
    'huawei_ma5683t',
    'huawei_ma5801',
    'zte_c220',
    'zte_c300',
    'zte_c300_fw_1_2',
    'zte_c320',
    'zte_c320_fw_1_2',
    'zte_c600_fw_12',
    'zte_c610_fw_12',
    'zte_zxpon_olt_series'
);

