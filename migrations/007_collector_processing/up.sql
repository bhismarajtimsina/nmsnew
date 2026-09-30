create table collector_processing
(
    id int auto_increment,
    start_at datetime not null,
    stop_at datetime null,
    device_id int null,
    status enum('SUCCESS', 'FAILED') null,
    errors json null,
    constraint collector_processing_pk
        primary key (id),
    constraint collector_processing_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);

