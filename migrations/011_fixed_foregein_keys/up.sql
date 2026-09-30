alter table device_interfaces drop foreign key device_interfaces_devices_id_fk;

alter table device_interfaces
    add constraint device_interfaces_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade;

DELETE FROM system_actions WHERE user_id not in (SELECT id FROM users);

alter table system_actions
    add constraint system_actions_users_id_fk
        foreign key (user_id) references users (id)
            on update cascade on delete cascade;
