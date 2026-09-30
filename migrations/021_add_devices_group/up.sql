create table device_groups
(
    id          int auto_increment,
    created_at  datetime     not null,
    name        varchar(150) not null,
    description varchar(500) not null,
    constraint device_groups_pk
        primary key (id)
);

create table user_devices_groups
(
    id         int auto_increment,
    created_at datetime not null,
    group_id   int      not null,
    user_id    int      not null,
    constraint user_devices_groups_pk
        primary key (id),
    constraint user_devices_groups_device_groups_id_fk
        foreign key (group_id) references device_groups (id)
            on update cascade on delete cascade,
    constraint user_devices_groups_users_id_fk
        foreign key (user_id) references users (id)
            on update cascade on delete cascade
);

create unique index user_devices_groups_user_id_group_id_uindex
    on user_devices_groups (user_id, group_id);

INSERT INTO device_groups VALUES (-1, NOW(), 'SYSTEM_GROUP', 'DEFAULT_SYSTEM_GROUP');
alter table devices
    add group_id int default -1 not null;

alter table devices
    add constraint devices_device_groups_id_fk
        foreign key (group_id) references device_groups (id)
            on update cascade on delete cascade;
