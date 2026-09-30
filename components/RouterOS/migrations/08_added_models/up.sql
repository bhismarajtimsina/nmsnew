INSERT INTO device_models (`key`, name, type, params, vendor, model, icon, controller, pollers)
VALUES ('mikrotik_router_os',
        'Mikrotik RouterOS',
        'ROUTER',
        '{}',
        'Mikrotik',
        'RouterOS',
        '/upload/icons/mikrotik-routeros.jpeg',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '{"system": 300, "counters": 60, "arp_table": 600, "bgp_sessions": 300, "sys_resources": 180, "interfaces_list": 300, "interfaces_status": 60}'
       ),
       ('mikrotik_rb3011uias',
        'Mikrotik RB3011UiAS',
        'ROUTER',
        '{}',
        'Mikrotik',
        'RB3011UiAS',
        '/upload/icons/mikrotik-routeros.jpeg',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '{"system": 300, "counters": 60, "arp_table": 600, "bgp_sessions": 300, "sys_resources": 180, "interfaces_list": 300, "interfaces_status": 60}'
       ),
       ('mikrotik_rb750',
        'Mikrotik RB750',
        'ROUTER',
        '{}',
        'Mikrotik',
        'RB750',
        '/upload/icons/mikrotik-routeros.jpeg',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '{"system": 300, "counters": 60, "arp_table": 600, "bgp_sessions": 300, "sys_resources": 180, "interfaces_list": 300, "interfaces_status": 60}'
       )
    ;
