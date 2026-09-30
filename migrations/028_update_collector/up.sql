UPDATE device_models SET params = '{"collect_interval":  300}';
UPDATE system_schedule SET crontab = '* * * * *' WHERE `key` = 'collect_data';
