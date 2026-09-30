-- The ISP is not a restricted account. It is a focused one.
--
-- Note for the next person: the migration runner splits this file on every
-- semicolon, quoted or not, so a semicolon inside a comment or a string
-- literal breaks the statement in half. Keep them out of both.
--
-- The first cut of this role gave ISP support read access to the transport and
-- held back anything that changes a device. That was wrong. The ISP may do
-- anything, at any time, on any device — every screen, every subscriber, every
-- control. What separates their console from the operator's is not permission,
-- it is what gets shown first.
--
-- Two things stay outside this role, and only because they change the product
-- rather than the network: managing users and roles, and system configuration.
-- Moving those in later is a one-line change.
UPDATE user_roles SET permissions = JSON_ARRAY(
    -- the app itself
    'system_info', 'system_status', 'user_self_control', 'global_search',
    'dashboard_edit', 'dashboard_global_edit',
    -- devices, in full
    'device_show', 'device_management', 'device_access_management',
    'device_groups_management', 'device_iface_management', 'device_iface_manage_marks',
    -- the transport
    'switches_info', 'links_view', 'links_edit', 'paths_view', 'paths_edit',
    'router_info', 'router_os_info',
    -- subscribers, in full
    'olts_info', 'unregistered_onts', 'unregistered_onts_preview',
    'unregistered_onts_result_output', 'unregistered_onts_config',
    -- every control on a switch
    'switches_set_port_description', 'switches_set_port_admin_state',
    'switches_set_port_admin_speed', 'switches_clear_counters',
    'switches_reboot_device', 'switches_save_config',
    -- every control on an OLT and its ONTs
    'olts_ctrl_reboot', 'olts_ctrl_reset', 'olts_ctrl_disable', 'olts_ctrl_dereg',
    'olts_ctrl_description', 'olts_ctrl_port', 'olts_ctrl_uni_ports',
    'olts_ctrl_clear_counters', 'olts_ctrl_clear_pon',
    -- finding and proving
    'sd_search_mac', 'sd_search_ip', 'sd_search_ip_with_port',
    'fdb_history_by_interface', 'live_traffic_info', 'prom_chart_info', 'analytics',
    'diag_icmp_ping', 'diag_traceroute', 'diag_arp_ping', 'diag_interface',
    -- alarms, including changing the rules
    'events_show', 'events_resolve', 'events_see_all', 'events_configuration',
    'notifications_full_access', 'notifications_view_history',
    'notifications_configure_contacts', 'notifications_configure_self_contacts',
    'notifications_send_global_notify',
    -- the console, macros and config backup
    'external_apps_console', 'external_apps_console_view_logs',
    'external_apps_console_with_auto_auth', 'external_apps_oxidized',
    'external_apps_grafana', 'external_apps_prometheus', 'external_apps_alertmanager',
    'macros_execute', 'macros_edit',
    -- operations
    'poller_info_by_device', 'run_poller_by_device', 'log_poller',
    'log_switcher_core_actions', 'system_logs_actions',
    'schedule_reports', 'schedule_configuration',
    'sensor_devices_view', 'sensor_devices_configure', 'sensor_devices_allow_switch_modes',
    'attachments_view', 'attachments_upload', 'attachments_delete',
    'view_qr_codes', 'view_mass_qr_codes'
),
description = 'Full control of the network, focused on the transport. Sees device, link and interface faults first. Subscriber optical alarms are available but never lead.'
WHERE name = 'ISP support';

-- Which alarms lead each console.
--
--   primary    opens the shift, and notifies
--   secondary  on the screen, in a second panel, no notification
--   muted      not on the dashboard and not in the default event view, but one
--              filter away and always searchable
--
-- The same alarm sits differently on each side, which is the whole point: a
-- PON port dropping its subscribers is the reseller's outage and the ISP's
-- cause, and an ONT's optical drift is the reseller's work and noise to the
-- ISP. Measured before this change, 943 of the 993 open events were subscriber
-- optical and 50 were transport, so without this the ISP's own work is 5 per
-- cent of their screen.
SET @isp := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'c_events_alertmanager_rules'
               AND COLUMN_NAME = 'isp_focus');
SET @ddl := IF(@isp = 0,
    "ALTER TABLE c_events_alertmanager_rules
        ADD COLUMN isp_focus ENUM('primary','secondary','muted') NOT NULL DEFAULT 'secondary' COMMENT 'How this alarm ranks on the ISP console' AFTER audience,
        ADD COLUMN reseller_focus ENUM('primary','secondary','muted') NOT NULL DEFAULT 'muted' COMMENT 'How this alarm ranks on the reseller console' AFTER isp_focus",
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Device down, link down, interface down. Named directly as the ISP's work.
UPDATE c_events_alertmanager_rules SET isp_focus = 'primary' WHERE alert_name IN (
    'pinger_host_down', 'interface_is_down', 'favorite_interface_down',
    'path_down', 'path_group_outage', 'bgp_session_down'
);

-- Real, and worth a second panel, but not what a shift opens on.
UPDATE c_events_alertmanager_rules SET isp_focus = 'secondary' WHERE alert_name IN (
    'iface_increase_in_errors', 'iface_increase_out_errors', 'high_link_utilization',
    'path_degraded', 'path_group_unprotected', 'bgp_session_restarted',
    'bad_optical_sfp_rx', 'sys_cpu_highload', 'system_not_enough_pollers',
    'sensors_no_power_supply', 'sensors_low_battery_level', 'sensor_test_alert',
    'pon_mass_onts_down'
);

-- A subscriber's light is not the ISP's alarm. One filter away, never leading.
UPDATE c_events_alertmanager_rules SET isp_focus = 'muted' WHERE alert_name IN (
    'bad_optical_level_rx', 'bad_optical_level_olt_rx',
    'rx_signal_deteriorated', 'olt_rx_signal_deteriorated',
    'pon_box_status', 'pon_ports_is_full'
);

-- The reseller's side of the same table.
UPDATE c_events_alertmanager_rules SET reseller_focus = 'primary' WHERE alert_name IN (
    'pon_mass_onts_down',
    'bad_optical_level_rx', 'bad_optical_level_olt_rx',
    'rx_signal_deteriorated', 'olt_rx_signal_deteriorated'
);
UPDATE c_events_alertmanager_rules SET reseller_focus = 'secondary' WHERE alert_name IN (
    'pinger_host_down', 'pon_box_status', 'pon_ports_is_full'
);
