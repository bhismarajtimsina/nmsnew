-- Turn on the alarm the ISP asked for by name, without a thousand-alert storm.
--
-- `interface_is_down` was disabled, and for a good reason: as written it fires
-- on every interface, and most interfaces here are subscriber ONTs. Measured
-- against live metrics before this change, the unscoped rule would have raised
-- 263 alerts the moment it was enabled, against 50 once scoped to real switch
-- and uplink ports.
--
-- ONT down-ness is not this alarm's job. It belongs to the PON and optical
-- rules, where it is grouped per port and separated from the customer's own
-- power. So the expression now names the physical port types and leaves ONU,
-- PON and GPON alone.
--
-- The 50 that will alarm are ports administratively up but operationally
-- down. They are worth seeing: either something is broken, or ports nobody
-- uses were never shut down properly.
UPDATE c_events_alertmanager_rules
SET expression = 'device_interface_status{iface_type=~"ETH|FE|GE|TGE|LACP"} == 0 and device_interface_admin_state{iface_type=~"ETH|FE|GE|TGE|LACP"} == 1',
    `for` = '5m',
    annotation_summary = 'Port down',
    annotation_description = 'Port {{ $labels.iface_name }} on {{ $labels.ip }} is administratively up but operationally down',
    enabled = 1
WHERE alert_name = 'interface_is_down';

-- A hold before anyone is told, so a flapping port cannot page. Nothing is
-- delivered today — no contact is configured — but this is the setting that
-- matters the moment one is.
UPDATE c_notifications_events_config
SET delay_before_send = 300
WHERE event_name = 'interface_is_down' AND delay_before_send = 0;
