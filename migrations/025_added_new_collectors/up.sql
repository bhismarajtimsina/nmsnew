create table collector_bgp_sessions
(
    id             int auto_increment,
    created_at     datetime     null,
    updated_at     datetime     null,
    device_id      int          not null,
    name           varchar(100) null,
    instance       varchar(100) null,
    started_at     int          null,
    remote_address varchar(100) null,
    local_address  varchar(100) null,
    remote_id      varchar(100) null,
    remote_as      int          null,
    state          varchar(50)  null,
    disabled       tinyint      null,
    constraint collector_bgp_sessions_pk
        primary key (id),
    constraint collector_bgp_sessions_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);

create table collector_arp_history
(
    id         int auto_increment,
    created_at datetime                                        null,
    device_id  int                                             not null,
    ip         varchar(100)                                    not null,
    mac        varchar(100)                                    not null,
    interface  varchar(100)                                    not null,
    dynamic    enum ('UNKNOWN', 'YES', 'NO') default 'UNKNOWN' null,
    vlan_id    int                                             null,
    status     varchar(50)                                     null,
    constraint collector_arps_pk
        primary key (id),
    constraint collector_arps_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);

alter table device_interfaces
    modify type enum ('ETH', 'PON', 'SFP', 'ONU', 'UNI', '1G-SFP', '10G-SFP', 'UNKNOWN', 'VLAN') null;

alter table collector_arp_history
    change created_at start_at datetime not null;

alter table collector_arp_history
    add stop_at datetime null after start_at;

alter table collector_arp_history
    add comment varchar(100) null;

alter table collector_processing
    modify collector enum ('counters', 'system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident', 'bgp_sessions', 'arp_table') null;

