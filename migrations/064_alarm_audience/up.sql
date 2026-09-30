-- Say who each alarm is for.
--
-- The rules exist and fire, but nothing records which audience owns them, so
-- dashboards, notification routing and the reseller console all have to guess.
-- This is the statement of ownership those three read.
--
--   isp       the transport: switches, uplinks, links, paths, routing
--   reseller  subscribers and the PON ports they sell on
--   both      the handover cases, where each side sees a different half
--   operator  the NMS itself
-- Added conditionally so the file can be re-run — MySQL has no
-- ADD COLUMN IF NOT EXISTS, so ask information_schema and prepare either the
-- real statement or a harmless one. The no-op branch is DO rather than SELECT
-- on purpose — the migration runner uses an unbuffered PDO connection, and a
-- SELECT here leaves a result set open that makes the next statement fail.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'c_events_alertmanager_rules'
               AND COLUMN_NAME = 'audience');
SET @ddl := IF(@col = 0,
    "ALTER TABLE c_events_alertmanager_rules ADD COLUMN audience ENUM('isp','reseller','both','operator') NULL DEFAULT NULL COMMENT 'Which console owns this alarm' AFTER severity",
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE c_events_alertmanager_rules SET audience = 'isp' WHERE alert_name IN (
    'favorite_interface_down', 'interface_is_down',
    'iface_increase_in_errors', 'iface_increase_out_errors',
    'high_link_utilization',
    'path_down', 'path_degraded', 'path_group_outage', 'path_group_unprotected',
    'bad_optical_sfp_rx',
    'bgp_session_down', 'bgp_session_restarted',
    'sys_cpu_highload',
    'sensors_no_power_supply', 'sensors_low_battery_level', 'sensor_test_alert'
);

UPDATE c_events_alertmanager_rules SET audience = 'reseller' WHERE alert_name IN (
    'pon_box_status', 'pon_ports_is_full',
    'bad_optical_level_rx', 'rx_signal_deteriorated',
    'bad_optical_level_olt_rx', 'olt_rx_signal_deteriorated'
);

-- A PON port dropping every ONT at once is the handover point: the reseller
-- sees their customers go dark, the ISP sees the cause, which is usually
-- upstream of the OLT. Host-down is the same shape.
UPDATE c_events_alertmanager_rules SET audience = 'both' WHERE alert_name IN (
    'pon_mass_onts_down', 'pinger_host_down'
);

UPDATE c_events_alertmanager_rules SET audience = 'operator' WHERE alert_name IN (
    'system_not_enough_pollers'
);
