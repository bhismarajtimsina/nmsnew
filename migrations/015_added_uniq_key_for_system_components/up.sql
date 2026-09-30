CREATE TEMPORARY table exist_components
SELECT max(id) id FROM system_components GROUP BY `key`;
DELETE FROM system_components WHERE id not in (SELECT id FROM exist_components);
create unique index system_components_key_uindex
    on system_components (`key`);
