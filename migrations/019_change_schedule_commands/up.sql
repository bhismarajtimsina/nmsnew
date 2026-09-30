UPDATE system_schedule SET command = 'wca logs:clear switcher-core 1' WHERE `key` = 'clear_switcher_core_logs';
UPDATE system_schedule SET command = 'wca logs:clear collector 1' WHERE `key` = 'clear_collector_logs';
UPDATE system_schedule SET command = 'wca logs:clear crontab-reports 2' WHERE `key` = 'clear_crontab_report_logs';