INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('SNMP trap service', 'trapservice', false, '{}');

create table c_trap_logs
(
    id           int auto_increment
        primary key,
    created_at   datetime     null,
    device_id    int          not null,
    interface_id int          null,
    object       varchar(100) null,
    name         varchar(150) null,
    description  varchar(200) null,
    modules      json         null,
    parsed       json         null,
    raw          json         null,
    errors       json         null,
    constraint c_trap_logs_device_interfaces_id_fk
        foreign key (interface_id) references device_interfaces (id)
            on delete cascade,
    constraint c_trap_logs_devices_id_fk
        foreign key (device_id) references devices (id)
            on delete cascade
)
    comment 'Log SNMP traps from devices';

create index c_trap_logs_created_at_index
    on c_trap_logs (created_at desc);

create index c_trap_logs_name_index
    on c_trap_logs (name);

create index c_trap_logs_object_index
    on c_trap_logs (object);

INSERT INTO system_schedule (
    `key`, created_at, latest, component_id, crontab, command, state, editable
) VALUES (
             'trapservice_clear_old_logs',
             NOW(),
             null,
             (SELECT id FROM system_components WHERE `key` = 'trapservice'),
             '0 */3 * * *',
             'wca trapservice:clear-old-logs 30',
             'ENABLED',
             1
         );