INSERT INTO system_schedule (`key`, created_at, latest, component_id, crontab, command, state, editable)
SELECT 'openapi_doc', NOW(), null, null, '@reboot', 'wca openapi:generate', 1, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_schedule s WHERE s.`key` = 'openapi_doc');
