alter table device_interfaces drop column `status`;

create table collector_ont_ident
(
    id int auto_increment,
    created_at datetime not null,
    interface_id int not null,
    type enum('MAC', 'SERIAL') not null,
    ident varchar(50) null default '',
    constraint collector_ont_ident_pk
        primary key (id)
);
alter table collector_ont_ident
    add constraint collector_ont_ident_device_interfaces_id_fk
        foreign key (interface_id) references device_interfaces (id)
            on update cascade on delete cascade;

create index collector_ont_ident_ident_index
    on collector_ont_ident (ident);

create unique index collector_ont_ident_interface_id_device_id_uindex
    on collector_ont_ident (interface_id);


create table collector_interfaces_status_history
(
    id int auto_increment,
    created_at datetime not null,
    interface_id int not null,
    status enum('Up', 'Down', 'Online', 'Offline') null,
    admin_state enum('Enabled', 'Disabled') default 'Enabled' null,
    nway_speed enum('Down', '10-Half', '10-Full', '100-Half', '100-Full', '1G-Full', '10G-Full') null,
    constraint controller_interfaces_status_history_pk
        primary key (id),
    constraint controller_interfaces_status_history_device_interfaces_id_fk
        foreign key (interface_id) references device_interfaces (id) on update cascade on delete cascade
);

create table collector_ont_optical_history
(
    id int auto_increment,
    created_at datetime not null,
    interface_id int not null,
    rx double null,
    tx double null,
    voltage double null,
    temperature double null,
    attenuation double null,
    olt_rx double null,
    olt_tx double null,
    constraint collector_ont_optical_history_pk
        primary key (id),
    constraint collector_ont_optical_history_device_interfaces_id_fk
        foreign key (interface_id) references device_interfaces (id) on update cascade on delete cascade
);


alter table collector_processing
    add collector enum('system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident') null;
