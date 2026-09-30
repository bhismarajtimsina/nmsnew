DELETE FROM system_schedule WHERE component_id = (SELECT id FROM system_components WHERE `key` = 'trapservice');

DELETE FROM system_components WHERE `key` = 'trapservice';