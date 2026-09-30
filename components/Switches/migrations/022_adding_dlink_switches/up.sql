INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'dlink_dgs_1210_28x_me_b1', 'D-link DGS-1210-28X/ME/B1', '{}', 'D-link', 'DGS-1210-28X/ME/B1', 'SWITCH', '/upload/icons/dlink.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 120}');
INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'dlink_dgs_1210_28_me_b2', 'D-link DGS-1210-28/ME/B2', '{}', 'D-link', 'DGS-1210-28/ME/B2', 'SWITCH', '/upload/icons/dlink.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 120}');
INSERT IGNORE INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
VALUES ( 'dlink_dgs_3000_20l', 'D-link DGS-3000-20L', '{}', 'D-link', 'DGS-3000-20L', 'SWITCH', '/upload/icons/dlink.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "interfaces_list": 1800, "interfaces_status": 120, "sys_resources": 120}');

UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sys_resources', 120)
WHERE JSON_EXTRACT(pollers, '$.sys_resources') IS NULL and type = 'SWITCH';

UPDATE  device_models
SET pollers = JSON_SET(pollers, '$.sfp_optical_strength', 300)
WHERE JSON_EXTRACT(pollers, '$.sfp_optical_strength') IS NULL
  AND `key` IN (
                'arista_default',
                'dell_general_switch',
                'dell_networking_os',
                'dlink_des_3026',
                'dlink_des_3200_10_a1',
                'dlink_des_3200_10_c1',
                'dlink_des_3200_18_c1',
                'dlink_des_3200_26_a1',
                'dlink_des_3200_26_c1',
                'dlink_des_3200_28_c1',
                'dlink_des_3200_28f_a1',
                'dlink_des_3200_28f_c1',
                'dlink_des_3200_52_c1',
                'dlink_des_3526',
                'dlink_des_3528',
                'dlink_dgs_1210_12ts_me_b1',
                'dlink_dgs_1210_20_me_b1',
                'dlink_dgs_1210_28_me_a2',
                'dlink_dgs_1210_28_me_b1',
                'dlink_dgs_1210_28_me_b2',
                'dlink_dgs_1210_28x_me_b1',
                'dlink_dgs_1210_28xs_me_b1',
                'dlink_dgs_1510_20l_me',
                'dlink_dgs_3000_10tc',
                'dlink_dgs_3000_26tc_a1',
                'dlink_dgs_3000_28l_b1',
                'dlink_dgs_3120_24sc_a2',
                'dlink_dgs_3120_24sc_bx',
                'dlink_dgs_3420_26sc_b1',
                'dlink_dgs_3420_28sc_b1',
                'dlink_dgs_3612g',
                'edgecore_ecs4120_28f',
                'edgecore_ecs4120_28fv2',
                'edgecore_ecs4510_28f',
                'edgecore_es4612',
                'mikrotik_crs',
                'raisecom_iscom',
                'raisecom_rax721'
    );