alter table poller_processing
    modify poller enum (
        'pon_port_loading',
        'sys_resources',
        'counters',
        'system',
        'interfaces_list',
        'fdb_table',
        'interfaces_status',
        'optical_strength',
        'ont_ident',
        'bgp_sessions',
        'arp_table',
        'sensors_data',
        'ont_vendor_info',
        'cards_status'
        ) null;

