-- Teach the outage alarm to see a fibre fault, and separate it from a power cut.
--
-- The interface poller already encodes why an ONT is down, and has all along:
-- 1 online, 0 offline, -1 the customer's own power off, -2 loss of signal.
-- Nothing used the distinction. pon_mass_onts_down counted only status == 0,
-- so it was blind to every ONT reporting loss of signal -- which is the
-- majority of them.
--
-- Measured live before this change: 210 ONTs at status 0, which the alarm
-- could see, and 616 at loss of signal, which it could not. Four PON ports on
-- one OLT hold 272 ONTs that all changed to loss of signal within the same
-- second on 5 September, and no mass-outage alarm was ever raised for them.
--
-- The rule now counts network-down, meaning offline or loss of signal, and
-- deliberately leaves out the customer's own power. It keeps the delta shape,
-- so a port that has been in that state for days does not alarm forever --
-- only a fresh cluster does.

UPDATE c_events_alertmanager_rules
SET expression = 'round(delta(count( (label_replace(device_interface_status{iface_name=~".*:.*"}, "pon_port", "$1", "iface_name", "^(.*):[0-9]{1,4}$") == 0) or (label_replace(device_interface_status{iface_name=~".*:.*"}, "pon_port", "$1", "iface_name", "^(.*):[0-9]{1,4}$") == -2) ) by (dev_id, ip, pon_port)[15m:])) >= 3',
    annotation_summary = 'Several ONTs down on one PON port',
    annotation_description = 'Three or more ONTs on {{ $labels.pon_port }} at {{ $labels.ip }} went offline or lost signal within fifteen minutes'
WHERE alert_name = 'pon_mass_onts_down';

-- The other half of the same picture. Several ONTs losing mains together on
-- one port is a street or building power cut: worth knowing, worth telling the
-- reseller, and never worth dispatching a fibre crew for. Kept separate from
-- the outage alarm precisely so it cannot be mistaken for one.
INSERT INTO c_events_alertmanager_rules
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity,
     annotation_summary, annotation_description, enabled, internal, audience, isp_focus, reseller_focus)
SELECT NOW(), NOW(), 'pon', 'pon_mass_onts_power_loss',
    'round(delta(count( label_replace(device_interface_status{iface_name=~".*:.*"}, "pon_port", "$1", "iface_name", "^(.*):[0-9]{1,4}$") == -1 ) by (dev_id, ip, pon_port)[15m:])) >= 3',
    '10m', 'info',
    'Several ONTs lost power on one PON port',
    'Three or more ONTs on {{ $labels.pon_port }} at {{ $labels.ip }} reported their own power off within fifteen minutes',
    1, 0, 'reseller', 'muted', 'secondary'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM c_events_alertmanager_rules WHERE alert_name = 'pon_mass_onts_power_loss');
