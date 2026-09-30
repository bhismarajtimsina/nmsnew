INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Working with device console', 'console', false, '{}');


create table c_console_history
(
    id           int auto_increment
        primary key,
    start_at     datetime not null,
    stop_at      datetime null,
    user_id      int      null,
    device_id    int      null,
    is_autologin tinyint  not null,
    log          longtext null,
    error        json     null,
    pid          int      null,
    constraint c_console_history_devices_id_fk
        foreign key (device_id) references devices (id)
            on update set null on delete set null,
    constraint c_console_history_users_id_fk
        foreign key (user_id) references users (id)
            on update set null on delete set null
)
    collate = utf8mb4_general_ci;

create index c_console_history_start_at_index
    on c_console_history (start_at);

