INSERT IGNORE INTO `c_events_alertmanager_rules`
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity, annotation_summary, annotation_description)
VALUES
    (NOW(),NOW(),'interfaces','pon_box_status','pon_box_status == 0','1m','info','PON box down','PON box with name {{ $labels.pon_iface }} on device {{ $labels.ip }}, port {{ $labels.pon_iface }} is DOWN'),
    (NOW(),NOW(),'router','bgp_session_down','router_bgp_session_state_established == 0 and router_bgp_session_state_disabled == 0','1m','warning','BGP Session Down','BGP session with AS num {{ $labels.remote_as }} ({{ $labels.remote_address }}) on router {{ $labels.router_ip }} DOWN'),
    (NOW(),NOW(),'router','bgp_session_restarted','router_bgp_session_uptime < 300 and router_bgp_session_state_established == 1','1m','warning','BGP Session reloaded','BGP session with AS num {{ $labels.remote_as }} ({{ $labels.remote_address }}) on router {{ $labels.router_ip }} RELOADED'),
    (NOW(),NOW(),'pinger','pinger_host_down','pinger_host_status <= 0','1m','warning','Device is down by ICMP','Device {{ $labels.ip }} is DOWN');

UPDATE c_events_alertmanager_rules
SET expression = 'device_resources_cpu_util > 80'
WHERE alert_name = 'sys_cpu_highload';
