INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Autodiscovery devices', 'autodiscovery', 1, '{}');

create table c_autodiscovery_networks
(
    id              int auto_increment
        primary key,
    device_group_id int         not null,
    created_at      datetime    not null,
    cidr            varchar(50) not null,
    access_id       int         not null,
    last_scan       datetime    null,
    constraint c_autodiscovery_networks_cidr_uindex
        unique (cidr),
    constraint c_autodiscovery_networks_device_accesses_id_fk
        foreign key (access_id) references device_accesses (id)
            on update cascade on delete cascade,
    constraint c_autodiscovery_networks_device_groups_id_fk
        foreign key (device_group_id) references device_groups (id)
            on update cascade on delete cascade
);

INSERT INTO  system_schedule
(`key`, created_at, component_id, crontab, command, state)
VALUES
(
    'autodiscovery_search_devices',
    NOW(),
    (SELECT id FROM system_components WHERE `key` = 'autodiscovery'),
    '*/30 6-23 * * *',
    'wca autodiscovery:scan',
    'ENABLED'
);