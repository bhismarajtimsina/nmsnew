UPDATE device_models
SET pollers = JSON_SET(pollers, '$.sys_resources', 120)
WHERE JSON_EXTRACT(pollers, '$.sys_resources') IS NULL and type = 'ROUTER';
