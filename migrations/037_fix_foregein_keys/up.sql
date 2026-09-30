UPDATE system_actions SET device_id = null
WHERE device_id not in (SELECT id FROM devices);

alter table system_actions
    add constraint system_actions_devices_id_fk
        foreign key (device_id) references devices (id)
            on update set null on delete set null;