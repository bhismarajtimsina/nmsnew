INSERT INTO `system_components` (name, `key`, namespace, enabled, built_in, configuration)
VALUES ('Allow to registration ONT','onts_registration',NULL,1,0,null);

create table c_onts_registration_macros
(
    id               int auto_increment
        primary key,
    created_at       datetime             not null,
    updated_at       datetime             not null,
    enabled          tinyint(1) default 1 not null,
    name             varchar(200)         not null,
    device_model_ids json                 not null,
    parameters       json                 not null,
    template         text                 not null
);


INSERT INTO system_schedule (created_at, latest, component_id, crontab, command, state, `key`)
VALUES (NOW(), null, (
    SELECT id FROM system_components WHERE `key` = 'onts_registration' limit 1
), '*/5 8-22 * * *',
        'wca onts_registration:get-unregistered-onts', 'ENABLED', 'check_unregistered_onts');
