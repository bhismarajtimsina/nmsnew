-- Every BDCOM Ethernet switch the vendor library now detects gets a model row,
-- so a device can be pointed at one from the UI and API. Before this there was
-- exactly one BDCOM switch model (S5612) against a dozen product families.
--
-- Detection itself lives in
-- vendor/meklis/switcher-core/configs/models/BDcom.yml -- these rows are the
-- application-side half: controller, icon and poller intervals, all copied
-- from the S5612 row that has been running against real hardware.

INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_ies200_v25_2s8p', 'BDCOM IES200-V25-2S8P', '{}', 'BDcom', 'IES200-V25-2S8P', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_ies200_v25_2s8p');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_ies_series', 'BDCOM IES industrial series', '{}', 'BDcom', 'IES industrial series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_ies_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_legacy_switch', 'BDCOM legacy S2xxx/S3xxx switch', '{}', 'BDcom', 'legacy S2xxx/S3xxx switch', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_legacy_switch');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s1000_series', 'BDCOM S1000 series', '{}', 'BDcom', 'S1000 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s1000_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2200_b', 'BDCOM S2200-B', '{}', 'BDcom', 'S2200-B', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2200_b');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2200_series', 'BDCOM S2200 series', '{}', 'BDcom', 'S2200 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2200_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2500_c_br', 'BDCOM S2500-C-BR', '{}', 'BDcom', 'S2500-C-BR', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2500_c_br');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2500_p', 'BDCOM S2500-P', '{}', 'BDcom', 'S2500-P', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2500_p');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2500_series', 'BDCOM S2500 series', '{}', 'BDcom', 'S2500 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2500_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2510_c', 'BDCOM S2510-C', '{}', 'BDcom', 'S2510-C', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2510_c');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2528_c', 'BDCOM S2528-C', '{}', 'BDcom', 'S2528-C', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2528_c');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_24p4x', 'BDCOM S2900-24P4X', '{}', 'BDcom', 'S2900-24P4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_24p4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_24s8c4x', 'BDCOM S2900-24S8C4X', '{}', 'BDcom', 'S2900-24S8C4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_24s8c4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_24t4x', 'BDCOM S2900-24T4X', '{}', 'BDcom', 'S2900-24T4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_24t4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_2t10x', 'BDCOM S2900-2T10X', '{}', 'BDcom', 'S2900-2T10X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_2t10x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_48s6x', 'BDCOM S2900-48S6X', '{}', 'BDcom', 'S2900-48S6X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_48s6x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_48s6x_2ac', 'BDCOM S2900-48S6X-2AC', '{}', 'BDcom', 'S2900-48S6X-2AC', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_48s6x_2ac');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_48t4x', 'BDCOM S2900-48T4X', '{}', 'BDcom', 'S2900-48T4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_48t4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_48t6x', 'BDCOM S2900-48T6X', '{}', 'BDcom', 'S2900-48T6X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_48t6x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_8t4x', 'BDCOM S2900-8T4X', '{}', 'BDcom', 'S2900-8T4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_8t4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_8tg4x', 'BDCOM S2900-8TG4X', '{}', 'BDcom', 'S2900-8TG4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_8tg4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2900_series', 'BDCOM S2900 series', '{}', 'BDcom', 'S2900 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2900_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s2928_p', 'BDCOM S2928-P', '{}', 'BDcom', 'S2928-P', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s2928_p');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s3700_series', 'BDCOM S3700 series', '{}', 'BDcom', 'S3700 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s3700_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s3740', 'BDCOM S3740', '{}', 'BDcom', 'S3740', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s3740');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s3900_24t6x', 'BDCOM S3900-24T6X', '{}', 'BDcom', 'S3900-24T6X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s3900_24t6x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s3900_p', 'BDCOM S3900-P', '{}', 'BDcom', 'S3900-P', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s3900_p');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s3900_series', 'BDCOM S3900 series', '{}', 'BDcom', 'S3900 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s3900_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5600_series', 'BDCOM S5600 series', '{}', 'BDcom', 'S5600 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5600_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5612', 'BDCOM S5612', '{}', 'BDcom', 'S5612', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5612');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5612e', 'BDCOM S5612E', '{}', 'BDcom', 'S5612E', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5612e');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5612e_2ac', 'BDCOM S5612E-2AC', '{}', 'BDcom', 'S5612E-2AC', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5612e_2ac');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5700_24et6x', 'BDCOM S5700-24ET6X', '{}', 'BDcom', 'S5700-24ET6X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5700_24et6x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5700_8et4x', 'BDCOM S5700-8ET4X', '{}', 'BDcom', 'S5700-8ET4X', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5700_8et4x');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5700_p', 'BDCOM S5700-P', '{}', 'BDcom', 'S5700-P', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5700_p');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5700_series', 'BDCOM S5700 series', '{}', 'BDcom', 'S5700 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5700_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5800_24x2c', 'BDCOM S5800-24X2C', '{}', 'BDcom', 'S5800-24X2C', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5800_24x2c');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5800_32c2x_s', 'BDCOM S5800-32C2X-S', '{}', 'BDcom', 'S5800-32C2X-S', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5800_32c2x_s');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5800_48x8c_s', 'BDCOM S5800-48X8C-S', '{}', 'BDcom', 'S5800-48X8C-S', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5800_48x8c_s');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5800_48y8c_s', 'BDCOM S5800-48Y8C-S', '{}', 'BDcom', 'S5800-48Y8C-S', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5800_48y8c_s');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5800_series', 'BDCOM S5800 series', '{}', 'BDcom', 'S5800 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5800_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s5864hb', 'BDCOM S5864HB', '{}', 'BDcom', 'S5864HB', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s5864hb');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s6500_series', 'BDCOM S6500 series', '{}', 'BDcom', 'S6500 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s6500_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s6506', 'BDCOM S6506', '{}', 'BDcom', 'S6506', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s6506');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s6508', 'BDCOM S6508', '{}', 'BDcom', 'S6508', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s6508');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s8500_series', 'BDCOM S8500 series', '{}', 'BDcom', 'S8500 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s8500_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s8500e', 'BDCOM S8500E', '{}', 'BDcom', 'S8500E', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s8500e');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_s9500_series', 'BDCOM S9500 series', '{}', 'BDcom', 'S9500 series', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_s9500_series');
INSERT INTO device_models (`key`, name, params, vendor, model, type, icon, controller, pollers)
SELECT 'bdcom_switch_generic', 'BDCOM switch (generic)', '{}', 'BDcom', 'switch (generic)', 'SWITCH', '/upload/icons/bdcom_logo.png', '\\WCC\\Switches\\Controllers\\SwitchesController', '{"system": 300, "counters": 120, "fdb_table": 600, "sys_resources": 120, "interfaces_list": 1800, "interfaces_status": 120}'
WHERE NOT EXISTS (SELECT 1 FROM device_models WHERE `key` = 'bdcom_switch_generic');

-- Both of these are family/fallback entries rather than a purchasable part, so
-- their `model` column reads as a description instead of a part number.
UPDATE device_models SET model = 'legacy S2xxx/S3xxx' WHERE `key` = 'bdcom_legacy_switch';
UPDATE device_models SET model = 'Generic switch' WHERE `key` = 'bdcom_switch_generic';
