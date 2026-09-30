
#QUERY
create table c_notifications_ignore_devices
(
    id              int auto_increment
        primary key,
    created_at      datetime default CURRENT_TIMESTAMP not null,
    device_id       int                                not null,
    notification_id int                                not null,
    type            enum ('action', 'event')           not null,
    constraint c_notifications_ignore_devices_devices_id_fk
        foreign key (device_id) references devices (id)
            on update cascade on delete cascade
);

#QUERY
create unique index c_notifications_ignore_devices_notification_id_type_uindex
    on c_notifications_ignore_devices (notification_id, type, device_id);


#QUERY
CREATE TRIGGER c_notify_ignore_devices_actions
    BEFORE DELETE
    ON c_notifications_actions_config FOR EACH ROW
BEGIN
    DELETE FROM c_notifications_ignore_devices WHERE type = 'action' and notification_id = OLD.id;
END;

#QUERY
CREATE TRIGGER c_notify_ignore_devices_events
    BEFORE DELETE
    ON c_notifications_events_config FOR EACH ROW
BEGIN
    DELETE FROM c_notifications_ignore_devices WHERE type = 'event' and notification_id = OLD.id;
END;

#QUERY
INSERT IGNORE INTO c_notifications_events_config (created_at, event_name)
VALUES
    (NOW(), 'bgp_session_restarted'),
    (NOW(), 'bgp_session_down'),
    (NOW(), 'pon_box_status');
