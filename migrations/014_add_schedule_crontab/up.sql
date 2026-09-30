create table system_schedule
(
    id int auto_increment,
    `key` varchar(100) not null,
    created_at datetime default CURRENT_TIMESTAMP not null,
    latest datetime null,
    component_id int null,
    `crontab` varchar(50) not null,
    command varchar(255) not null,
    state enum('ENABLED', 'DISABLED') default 'ENABLED' not null,
    constraint system_crontab_pk
        primary key (id)
);

create unique index system_schedule_command_period_uindex
    on system_schedule (command, crontab);
create unique index system_schedule_key_uindex
    on system_schedule (`key`);


alter table system_schedule
    add constraint system_schedule_system_components_component_id_fk
        foreign key (component_id) references system_components(id)
            on update cascade on delete cascade;

create table system_schedule_reports
(
    id int auto_increment,
    start_at datetime default CURRENT_TIMESTAMP not null,
    stop_at datetime null,
    output text null,
    error text null,
    is_successful tinyint null,
    schedule_id int not null,
    constraint system_schedule_reports_pk
        unique (id),
    constraint system_schedule_reports_system_crontab_id_fk
        foreign key (schedule_id) references system_schedule (id)
            on update cascade on delete cascade
);
