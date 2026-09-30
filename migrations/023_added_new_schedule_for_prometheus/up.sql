INSERT INTO support.system_schedule (`key`, created_at, latest, component_id, crontab, command, state)
VALUES ('prometheus_meta_exporter', NOW(), null, null, '*/2 * * * *', 'wca system:prom-metrics-exporter', 'ENABLED');
