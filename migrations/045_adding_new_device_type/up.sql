alter table device_models
    modify type enum ('SWITCH', 'OLT', 'ONU', 'ROUTER', 'SENSOR') default 'SWITCH' not null;

alter table poller_processing
    modify poller enum ('pon_port_loading', 'sys_resources', 'counters', 'system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident', 'bgp_sessions', 'arp_table', 'sensors_data') null;

alter table device_interfaces
    modify type enum ('FE', 'GE', 'TGE', 'ETH', 'PON', 'SFP', 'ONU', 'UNI', '1G-SFP', '10G-SFP', 'UNKNOWN', 'VLAN', 'LACP', 'BRIDGE') null;
