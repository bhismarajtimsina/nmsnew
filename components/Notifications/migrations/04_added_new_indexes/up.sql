create index c_notifications_ignore_devices_type_index
    on c_notifications_ignore_devices (type);
create index c_notifications_type_index
    on c_notifications (type);
create index c_notifications_event_id_index
    on c_notifications (event_id);
alter table c_notifications
    modify status enum ('in_process', 'failed', 'sent', 'canceled') null;

