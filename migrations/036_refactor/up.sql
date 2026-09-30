rename table collector_arp_history to poll_arp_history;
rename table collector_bgp_sessions to poll_bgp_sessions;
rename table collector_fdb_history to poll_fdb_history;
rename table collector_ont_ident to poll_ont_ident;
rename table collector_processing to poller_processing;

DROP TABLE IF EXISTS collector_interfaces_status_history;
DROP TABLE IF EXISTS collector_bgp_sessions_history;

alter table poller_processing
    change collector poller enum ('sys_resources', 'counters', 'system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident', 'bgp_sessions', 'arp_table') null;
alter table device_models
    change collectors pollers json null;
alter table devices
    change collectors pollers json null;

alter table users
    change group_id role_id int not null;

RENAME TABLE user_groups to user_roles;

UPDATE device_models SET icon = CONCAT('/upload/icons/', icon) WHERE icon not like '%upload%';

alter table device_interfaces add poll_enabled tinyint default 1 not null;

DELETE FROM system_schedule WHERE `key` = 'collect_data';

alter table system_actions
    add device_id int null;
