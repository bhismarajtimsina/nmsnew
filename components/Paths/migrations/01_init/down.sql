DELETE FROM c_events_alertmanager_rules WHERE group_name = 'paths';
DELETE FROM system_schedule WHERE `key` = 'paths_state_calculator';

drop table if exists c_path_states;
drop table if exists c_path_segments;
drop table if exists c_paths;

DELETE FROM system_components WHERE `key` = 'paths';
