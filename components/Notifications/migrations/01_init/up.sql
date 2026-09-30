INSERT INTO system_components(name, `key`, enabled, configuration)
VALUES ('Notifications', 'notifications', 1, null);

create table c_notifications_contacts
(
    id          int auto_increment primary key,
    created_at  datetime                                                     not null,
    updated_at  datetime                                                     not null,
    user_id     int                                                          not null,
    type        enum ('EMAIL', 'PHONE', 'PHONE_FOR_TELEGRAM', 'TELEGRAM_ID') null,
    value       varchar(150)                                                 null,
    enabled     tinyint      default 1                                       not null,
    description varchar(200) default ''                                      null,
    params      json                                                         null,
    constraint c_notifications_contacts_type_value_user_id_uindex
        unique (type, value, user_id),
    constraint c_notifications_contacts_users_id_fk
        foreign key (user_id) references users (id)
            on update cascade on delete cascade
)
    auto_increment = 1;

create table c_notifications
(
    id                       int auto_increment
        primary key,
    created_at               datetime                                                   null,
    send_at                  datetime                                                   not null,
    sent_at                  datetime                                                   null,
    type                     enum ('notification', 'alert', 'resolved') default 'alert' not null,
    contact_id               int                                                        not null,
    event_id                 int                                                        null,
    action_id                int                                                        null,
    meta                     json                                                       null,
    previous_notification_id int                                                        null,
    status                   enum ('failed', 'sent', 'canceled')                        null,
    constraint c_notifications_events_c_notifications_contacts_id_fk
        foreign key (contact_id) references c_notifications_contacts (id)
            on update cascade on delete cascade,
    constraint c_notifications_events_c_notifications_events_id_fk
        foreign key (previous_notification_id) references c_notifications (id)
            on update set null on delete set null
)
    auto_increment = 1;

create index c_notifications_created_at_index
    on c_notifications (created_at);

create index c_notifications_send_at_index
    on c_notifications (send_at);

create index c_notifications_status_index
    on c_notifications (status);

create table c_notifications_actions_config
(
    id                     int auto_increment
        primary key,
    created_at             datetime          not null,
    action_name            varchar(150)      not null,
    enabled                tinyint default 1 not null,
    send_on_status_failed  tinyint default 1 not null,
    send_on_status_success tinyint default 1 not null,
    constraint c_notifications_actions_config_action_name_uindex
        unique (action_name)
)
    auto_increment = 1000;

create table c_notifications_events_config
(
    id                int auto_increment
        primary key,
    enabled           tinyint default 1 not null,
    created_at        datetime          not null,
    event_name        varchar(100)      not null,
    delay_before_send int     default 0 not null,
    send_resolved     tinyint default 1 not null,
    constraint c_notifications_events_config_event_name_uindex
        unique (event_name)
)
    auto_increment = 10000;



INSERT INTO `c_notifications_events_config`
VALUES
    (1,1,'2022-07-22 15:33:51','rx_signal_deteriorated',0,1),
    (2,1,'2022-07-22 15:33:51','pinger_host_down',60,1),
    (3,1,'2022-07-22 15:33:51','mass_interfaces_down',0,1),
    (4,1,'2022-07-22 15:33:51','interface_is_down',0,1);

INSERT INTO `c_notifications_actions_config`
VALUES (1,'2022-07-23 23:53:11','huawei_olts:onu_info',0,1,1),
       (2,'2022-07-23 23:53:11','huawei_olts:interface_info',0,1,1),
       (3,'2022-07-22 16:10:21','device-access:edited',1,1,1),
       (4,'2022-07-23 14:53:09','c_data_interfaces:interface_info',0,1,1),
       (5,'2022-07-23 14:53:09','c_data_interfaces:onu_info',0,1,1),
       (6,'2022-07-23 14:53:09','device-access:added',1,1,1),
       (7,'2022-07-23 14:53:09','device:added',1,1,1),
       (8,'2022-07-23 14:53:09','device:deleted',1,1,1),
       (9,'2022-07-23 14:53:09','device:updated',1,1,1),
       (10,'2022-07-23 14:53:09','notifications:actions-config-updated',1,1,1),
       (11,'2022-07-23 14:53:09','notifications:channel-updated',1,1,1),
       (12,'2022-07-23 14:53:09','user:logged_in',1,1,1),(51,'2022-07-23 14:53:09','user:logged_out',1,1,1),(52,'2022-07-23 14:53:09','zte_unregistered_onts:template_updated',1,1,1);

