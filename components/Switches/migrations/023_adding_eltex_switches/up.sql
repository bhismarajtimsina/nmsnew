INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'eltex_mes14xx_mes24xx_mes3708p', 'Eltex MES14xx|MES24xx|MES3708P', '{}', 'Eltex', 'MES14xx|MES24xx|MES3708P', 'SWITCH', '/upload/icons/eltex.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}'),
( 'eltex_mes14xx', 'Eltex MES14xx', '{}', 'Eltex', 'MES14xx', 'SWITCH', '/upload/icons/eltex.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}'),
( 'eltex_mes24xx', 'Eltex MES24xx', '{}', 'Eltex', 'MES24xx', 'SWITCH', '/upload/icons/eltex.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}'),
( 'eltex_mes3708', 'Eltex MES3708', '{}', 'Eltex', 'MES3708', 'SWITCH', '/upload/icons/eltex.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 300, "sfp_optical_strength": 600}');


UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sys_resources', 300)
WHERE JSON_EXTRACT(pollers, '$.sys_resources') IS NULL
  AND `key` IN (
                'alcatel_general_switch',
                'arista_default',
                'dcn_default',
                'dell_general_switch',
                'dell_networking_os',
                'dlink_des_1210_28_me_b2',
                'dlink_des_3026',
                'dlink_des_3052g',
                'dlink_des_3200_10_c1',
                'dlink_des_3200_18_c1',
                'dlink_des_3200_26_c1',
                'dlink_des_3200_28_c1',
                'dlink_des_3200_28f_c1',
                'dlink_des_3200_52_c1',
                'dlink_des_3528',
                'dlink_dgs_1100_06_me_a1',
                'dlink_dgs_1100_10_me_a1',
                'dlink_dgs_1510_20l_me',
                'dlink_dgs_3000_10tc',
                'dlink_dgs_3000_26tc_a1',
                'dlink_dgs_3000_28l_b1',
                'dlink_dgs_3100_24tg',
                'dlink_dgs_3120_24sc_a2',
                'dlink_dgs_3120_24sc_bx',
                'dlink_dgs_3420_26sc_b1',
                'dlink_dgs_3420_28sc_b1',
                'dlink_dgs_3612g',
                'edgecore_ecs3510_28t',
                'edgecore_ecs4120_28f',
                'edgecore_ecs4120_28fv2',
                'edgecore_ecs4510_28f',
                'edgecore_es3552m',
                'edgecore_es4612',
                'edgecore_general_switch',
                'eltex_general_switch',
                'juniper_qfx51xx_series',
                'mikrotik_crs',
                'raisecom_default',
                'raisecom_iscom',
                'raisecom_iscom_2600'
    );