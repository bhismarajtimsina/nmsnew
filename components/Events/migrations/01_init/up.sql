INSERT INTO system_components(name, `key`, enabled, configuration)
VALUES ('Events integration', 'events', 1, null);

create table c_events
(
    id                    int auto_increment
        primary key,
    created_at            datetime                                                        not null,
    updated_at            datetime                                                        not null,
    creator_id            int                                                             null,
    name                  varchar(50)                                                     not null,
    labels                json                                                            not null,
    description           varchar(150)                                                    null,
    resolved_at           datetime                                                        null,
    resolved_by_id        int                                                             null,
    autoresolve_after_min int                                           default 0         not null,
    is_autoresolved       tinyint                                                         null,
    device_id             int                                                             null,
    `key`                 varchar(100)                                                    not null,
    severity              enum ('CRITICAL', 'WARNING', 'INFO', 'DEBUG') default 'WARNING' not null,
    constraint c_events_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade,
    constraint c_events_users_id_fk
        foreign key (creator_id) references users (id)
            on update set null on delete set null,
    constraint c_events_users_id_fk_2
        foreign key (resolved_by_id) references users (id)
            on update set null on delete set null
);


INSERT INTO users (id, login, name, created_at, updated_at, role_id, password, status, language,
                   settings)
VALUES (-5, 'alertmanager', 'Alertmanager', NOW(), NOW(), -1, null, 'ENABLED', 'en',
        null);





