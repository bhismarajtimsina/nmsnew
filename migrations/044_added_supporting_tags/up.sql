create table support.device_interfaces_tags
(
    id           int auto_increment
        primary key,
    interface_id int                                       not null,
    type         enum ('FAVORITE', 'TAG') default 'FAVORITE' not null,
    value        varchar(150)                              null,
    constraint device_interfaces_tags_interface_id_value_uindex
        unique (interface_id, value),
    constraint device_interfaces_tags_device_interfaces_id_fk
        foreign key (interface_id) references support.device_interfaces (id)
            on delete cascade
);

create index device_interfaces_tags_type_index
    on support.device_interfaces_tags (type);

