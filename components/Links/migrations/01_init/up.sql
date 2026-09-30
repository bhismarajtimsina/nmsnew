INSERT INTO system_components(name, `key`,  enabled,   configuration)
VALUES ('Links dependencies', 'links', 1, null);


create table c_links
(
    id             int auto_increment
        primary key,
    created_at     datetime                       default CURRENT_TIMESTAMP not null,
    updated_at     datetime                       default CURRENT_TIMESTAMP not null,
    src_device_id      int                                                      not null,
    src_iface_id       int                                                      null,
    dest_device_id int                                                      not null,
    dest_iface_id  int                                                      null,
    source         enum ('manual', 'fdb', 'lldp') default 'manual'          not null,
    constraint c_links_device_interfaces_id_fk
        foreign key (src_iface_id) references device_interfaces (id)
            on update set null on delete set null,
    constraint c_links_device_interfaces_id_fk_2
        foreign key (dest_iface_id) references device_interfaces (id)
            on update set null on delete set null,
    constraint c_links_devices_id_fk
        foreign key (src_device_id) references devices (id)
            on update cascade on delete cascade,
    constraint c_links_devices_id_fk_2
        foreign key (dest_device_id) references devices (id)
            on update cascade on delete cascade
);

