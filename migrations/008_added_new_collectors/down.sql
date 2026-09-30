alter table device_interfaces add column `status` enum('ONLINE','OFFLINE','DISABLED','ERROR','UNKNOWN') NOT NULL;
drop table collector_ont_ident;
drop table collector_interfaces_status_history;
drop table collector_ont_optical_history;