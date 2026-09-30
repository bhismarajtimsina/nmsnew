INSERT INTO `system_components` (name, `key`, namespace, enabled, built_in, configuration)
VALUES ('Allow execute some commands','macros',NULL,1,0,null);

create table c_macros
(
    id               int auto_increment
        primary key,
    name             varchar(200) charset utf8mb4            not null,
    device_model_ids json                                    not null,
    allowed_role_ids json                                    not null,
    display_for      json                                    not null,
    parameters        json                                    not null,
    template         text charset utf8mb4                    not null,
    display_output   enum ('no', 'all', 'last') default 'no' not null,
    description      text                                    not null
)
    charset = utf8mb4;



