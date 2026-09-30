INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Working with RouterOS system', 'router_os', 1, null);

INSERT INTO device_models (`key`, `name`, `vendor`, `model`, `type`, `icon`, `controller`, `pollers`)
VALUES
    ('mikrotik_ccr1036', 'Mikrotik CCR1036', 'Mikrotik', 'CCR1036', 'ROUTER', 'mikrotik_ccr1036.png', null, '[]'),
    ('mikrotik_ccr1009', 'Mikrotik CCR1009', 'Mikrotik', 'CCR1009', 'ROUTER', 'mikrotik_ccr1009.png', null, '[]'),
    ('mikrotik_ccr1016', 'Mikrotik CCR1016', 'Mikrotik', 'CCR1016', 'ROUTER', 'mikrotik_ccr1016.png', null, '[]'),
    ('mikrotik_rb4011', 'Mikrotik RB4011', 'Mikrotik', 'RB4011', 'ROUTER', 'mikrotik_rb4011.png', null, '[]');


UPDATE device_models SET
                         controller = '\\WCC\\RouterOS\\Controllers\\Controller',
                         pollers = '[
                           "system",
                           "interfaces_status",
                           "counters",
                           "interfaces_list",
                           "bgp_sessions",
                           "arp_table",
                           "sys_resources"
                         ]'
                     WHERE `key` like 'mikrotik_%';
