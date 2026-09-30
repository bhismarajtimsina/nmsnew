alter table poller_processing
    modify poller enum ('pon_port_loading', 'sys_resources', 'counters', 'system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident', 'bgp_sessions', 'arp_table') null;

alter table device_accesses
    change community public_community varchar(100) not null;

alter table device_accesses
    add private_community varchar(100) null after public_community;

update device_accesses set private_community  = public_community;


alter table device_interfaces
    modify type enum ('FE', 'GE', 'TGE', 'ETH', 'PON', 'SFP', 'ONU', 'UNI', '1G-SFP', '10G-SFP', 'UNKNOWN', 'VLAN') null;

