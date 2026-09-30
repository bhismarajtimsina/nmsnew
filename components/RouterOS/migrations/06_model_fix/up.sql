UPDATE device_models SET `type` = 'ROUTER' WHERE `key` like 'mikrotik_%';


UPDATE device_models SET icon = CONCAT('/upload/icons/', icon) WHERE icon not like '%upload%';

