INSERT INTO system_schedule (created_at,`key`,  latest, component_id, crontab, command, state)
VALUES
       (NOW(),'collect_data', null, null, '*/5 * * * *', 'wca collector:collect --use-proc' , 'ENABLED'),
       (NOW(),'clear_switcher_core_logs', null, null, '30 */6 * * *', 'wca logs:clear switcher-core 7' , 'ENABLED'),
       (NOW(),'clear_action_logs', null, null, '0 0 * * *', 'wca logs:clear actions 30' , 'ENABLED'),
       (NOW(),'clear_collector_logs', null, null, '0 */6 * * *', 'wca logs:clear collector 7' , 'ENABLED'),
       (NOW(),'clear_crontab_report_logs', null, null, '20 */6 * * *', 'wca logs:clear crontab-reports 7' , 'ENABLED');
