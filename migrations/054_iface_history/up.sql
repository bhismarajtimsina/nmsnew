create table device_interfaces_history
(
    id           int auto_increment,
    interface_id int      not null,
    up           datetime not null,
    down         datetime null,
    down_reason  varchar(50) null,
    constraint device_interfaces_history_pk
        primary key (id),
    constraint device_interfaces_history_device_interfaces_id_fk
        foreign key (interface_id) references device_interfaces (id)
            on delete cascade
);

alter table device_interfaces
    add status_changed datetime default CURRENT_TIMESTAMP() null;

UPDATE device_interfaces SET status_changed = updated_at;

INSERT INTO device_interfaces_history (interface_id, up, down, down_reason)
SELECT id, updated_at, null, null FROM device_interfaces WHERE status in ('Up', 'Online');


DELETE FROM system_components WHERE `key` in (
    'huawei_onts_registration', 'zte_unregistered_onts'
    );