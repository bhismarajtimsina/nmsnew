alter table device_interfaces
    add parent_bind_key varchar(50) null;

create index device_interfaces_parent_bind_key_index
    on device_interfaces (parent_bind_key);

alter table devices
    add coordinates json null;

alter table device_interfaces
    add coordinates json null;

