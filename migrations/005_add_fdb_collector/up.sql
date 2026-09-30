create table collector_fdb_history
(
    id int auto_increment,
    device_id int not null,
    interface_id int not null,
    mac_address varchar(17) not null,
    vlan_id int default 1 not null,
    start_at datetime default CURRENT_TIMESTAMP not null,
    stop_at datetime null,
    constraint collector_fdb_history_pk
        primary key (id),
    constraint collector_fdb_history_device_interfaces_id_fk
        foreign key (interface_id) references device_interfaces (id)
            on update cascade on delete cascade,
    constraint collector_fdb_history_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);

create index collector_fdb_history_mac_address_index
    on collector_fdb_history (mac_address);

