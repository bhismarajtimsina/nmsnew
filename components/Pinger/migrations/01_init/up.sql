INSERT INTO system_components(name, `key`,  enabled,   configuration)
VALUES ('ICMP pinger integration', 'pinger', 1, null);


create table c_pinger_down_logs
(
    id     int auto_increment,
    start  datetime not null,
    stop   datetime null,
    device_id int      not null,
    constraint c_pinger_down_logs_pk
        primary key (id),
    constraint c_pinger_down_logs_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);

create table c_pinger_statuses
(
    id     int auto_increment,
    device_id      int      not null,
    last_change datetime not null,
    latency     int      not null,
    constraint c_pinger_statuses_pk
        primary key (id),
    constraint c_pinger_statuses_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);
create unique index c_pinger_statuses_device_id_uindex
    on c_pinger_statuses (device_id);

