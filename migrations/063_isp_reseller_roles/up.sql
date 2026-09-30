-- Two roles for the two audiences, drawn from the permission keys the app
-- already enforces per API route. Both are additive: no user is assigned to
-- either, so nobody's access changes until someone is moved onto one.
--
-- Neither role holds `events_see_all`. That is deliberate and load-bearing:
-- without it, the events, device, interface and trap-log queries all fall back
-- to filtering by the caller's own device groups, which is what makes one
-- reseller invisible to another.

-- ISP support: the transport, plus read access to subscriber data so a fault
-- can be followed all the way down. No provisioning, no device configuration,
-- no console.
INSERT INTO user_roles (name, display, permissions, description, params)
SELECT 'ISP support', 1, JSON_ARRAY(
    -- getting in, and the shell of the app
    'system_info', 'user_self_control', 'global_search', 'dashboard_edit',
    -- the transport itself
    'device_show', 'switches_info', 'links_view', 'paths_view',
    'poller_info_by_device', 'prom_chart_info', 'live_traffic_info',
    'device_iface_manage_marks',
    -- finding things
    'sd_search_mac', 'sd_search_ip', 'sd_search_ip_with_port',
    'fdb_history_by_interface', 'analytics',
    -- proving things
    'diag_icmp_ping', 'diag_traceroute', 'diag_arp_ping', 'diag_interface',
    -- subscriber data, read only, so a transport fault can be traced to who it hit
    'olts_info',
    -- alarms they own
    'events_show', 'events_resolve',
    'notifications_view_history', 'notifications_configure_self_contacts',
    -- labelling their own ports — counters are harmless and useful when chasing errors
    'switches_set_port_description', 'switches_clear_counters',
    -- reading equipment labels in the field
    'view_qr_codes'
), 'Watches the transport: switches, links, ports and paths. Reads subscriber data to trace a fault, but never provisions or configures.', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE name = 'ISP support');

-- Reseller: their own subscribers on their own PON ports. Everything here is
-- scoped further by the device groups the account is given.
INSERT INTO user_roles (name, display, permissions, description, params)
SELECT 'Reseller', 1, JSON_ARRAY(
    'system_info', 'user_self_control', 'dashboard_edit',
    -- their equipment and the subscribers on it
    'device_show', 'olts_info', 'poller_info_by_device', 'prom_chart_info', 'analytics',
    -- activating a new customer
    'unregistered_onts', 'unregistered_onts_preview', 'unregistered_onts_result_output',
    -- the two actions that affect exactly one subscriber
    'olts_ctrl_reboot', 'olts_ctrl_description',
    -- their own alarms and how they are told
    'events_show', 'events_resolve',
    'notifications_view_history', 'notifications_configure_self_contacts',
    -- labels for an install
    'view_qr_codes', 'view_mass_qr_codes'
), 'Sells and supports subscribers on their own PON ports. Sees no switches, links or other resellers.', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE name = 'Reseller');
