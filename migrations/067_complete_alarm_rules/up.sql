-- The three alarms the plan called for and the system did not have.
--
-- Written against the real metric set and each expression checked as PromQL
-- before being inserted. All three are correctly quiet right now, which is not
-- the same as broken: 66 physical ports are being watched, device memory sits
-- at 39 per cent, and no link has been discovered yet.
--
-- Note for whoever edits this file: the migration runner splits on every
-- semicolon, quoted or not, so keep them out of comments and string literals.

-- 1. Link down. Named as ISP primary and previously impossible to express.
-- The link exporter only wrote its gauges when a link had utilization to
-- report, so a down link emitted nothing at all and PromQL cannot name a
-- series that is absent. CalculateLinkUtilization now emits link_status on
-- every run for every link, 1 when both ends report up and 0 otherwise, which
-- makes this rule a one-liner.
INSERT INTO c_events_alertmanager_rules
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity,
     annotation_summary, annotation_description, enabled, internal, audience, isp_focus, reseller_focus)
SELECT NOW(), NOW(), 'links', 'link_down', 'link_status == 0', '2m', 'warning',
    'Link down',
    'Link between {{ $labels.src_device_name }} {{ $labels.src_iface_name }} and {{ $labels.dest_device_name }} {{ $labels.dest_iface_name }} is down',
    1, 0, 'isp', 'primary', 'muted'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM c_events_alertmanager_rules WHERE alert_name = 'link_down');

-- 2. Several ports on one device going down together. A notification was
-- already configured for this name and no rule existed to raise it, so the
-- configuration pointed at nothing. Three or more physical ports lost inside
-- fifteen minutes is a card, a power event or an upstream failure rather than
-- three coincidences. Modelled on pon_mass_onts_down, which counts the same
-- shape for ONTs on a PON port.
INSERT INTO c_events_alertmanager_rules
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity,
     annotation_summary, annotation_description, enabled, internal, audience, isp_focus, reseller_focus)
SELECT NOW(), NOW(), 'interfaces', 'mass_interfaces_down',
    'round(delta(count(device_interface_status{iface_type=~"ETH|FE|GE|TGE|LACP"} == 0) by (dev_id, ip)[15m:])) >= 3',
    '5m', 'warning',
    'Several ports down at once',
    'Three or more ports went down within fifteen minutes on {{ $labels.ip }}',
    1, 0, 'isp', 'primary', 'muted'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM c_events_alertmanager_rules WHERE alert_name = 'mass_interfaces_down');

-- 3. Memory, the twin of the CPU rule that already exists. The metric has been
-- collected all along and events under this name are already in the history,
-- so the rule that raised them is gone rather than never written. Same
-- threshold and shape as sys_cpu_highload.
INSERT INTO c_events_alertmanager_rules
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity,
     annotation_summary, annotation_description, enabled, internal, audience, isp_focus, reseller_focus)
SELECT NOW(), NOW(), 'device_resources', 'high_memory_load', 'device_resources_memory_util > 80', '10m', 'warning',
    'High memory load',
    'Memory use on {{ $labels.ip }} has been above 80 per cent for ten minutes',
    1, 0, 'isp', 'secondary', 'muted'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM c_events_alertmanager_rules WHERE alert_name = 'high_memory_load');

-- A hold before anyone is told, matching the one set on interface_is_down.
-- Nothing is delivered today because no contact is configured, but these are
-- the settings that matter the moment one is.
INSERT INTO c_notifications_events_config (enabled, created_at, event_name, delay_before_send, send_resolved, check_uplink)
SELECT 1, NOW(), 'link_down', 120, 1, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM c_notifications_events_config WHERE event_name = 'link_down');

INSERT INTO c_notifications_events_config (enabled, created_at, event_name, delay_before_send, send_resolved, check_uplink)
SELECT 1, NOW(), 'high_memory_load', 300, 1, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM c_notifications_events_config WHERE event_name = 'high_memory_load');
