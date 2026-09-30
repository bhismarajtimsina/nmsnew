INSERT INTO device_models (`key`, name, params, vendor, model, icon, controller, pollers)
VALUES ('mikrotik_rb750gr3',
        'Mikrotik RB750Gr3',
        '{"collect_interval": 300}',
        'Mikrotik',
        'RB750Gr3',
        'mikrotik_rb750gr3.png',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '[
          "system",
          "interfaces_status",
          "counters",
          "interfaces_list",
          "bgp_sessions",
          "arp_table",
          "sys_resources"
        ]'
       ),('mikrotik_rb2011uias_2hnd',
        'Mikrotik RB2011UiAS-2HnD',
        '{"collect_interval": 300}',
        'Mikrotik',
        'RB2011UiAS-2HnD',
        'mikrotik_rb2011uias_2hnd.png',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '[
          "system",
          "interfaces_status",
          "counters",
          "interfaces_list",
          "bgp_sessions",
          "arp_table",
          "sys_resources"
        ]'
       ),('mikrotik_crs317_1g_16s',
        'Mikrotik CRS317-1G-16S+',
        '{"collect_interval": 300}',
        'Mikrotik',
        'CRS317-1G-16S+',
        'mikrotik_crs317_1g_16s.png',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '[
          "system",
          "interfaces_status",
          "counters",
          "interfaces_list",
          "bgp_sessions",
          "arp_table",
          "sys_resources"
        ]'
       ),('mikrotik_crs328_4c_20s_4s',
        'Mikrotik CRS328-4C-20S-4S+',
        '{"collect_interval": 300}',
        'Mikrotik',
        'CRS328-4C-20S-4S+',
        'mikrotik_crs328_4c_20s_4s.jpg',
        '\\WCC\\RouterOS\\Controllers\\Controller',
        '[
          "system",
          "interfaces_status",
          "counters",
          "interfaces_list",
          "bgp_sessions",
          "arp_table",
          "sys_resources"
        ]'
       );
