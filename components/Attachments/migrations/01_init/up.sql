INSERT INTO system_components (name, `key`,  enabled,   configuration)
VALUES ('Attachments', 'attachments', 0, '{}');

create table support.c_attachments
(
    id          int auto_increment
        primary key,
    created_at  datetime default CURRENT_TIMESTAMP                                           not null,
    user_id     int                                                                          null,
    object_type enum ('devices', 'users', 'interfaces', 'pon_boxes', 'nodes', 'utels_boxes') not null,
    object_id   int                                                                          not null,
    uuid        varchar(36)                                                                  null,
    extension   varchar(50)                                                                  null,
    extra       json                                                                         null,
    constraint c_attachments_users_id_fk
        foreign key (user_id) references support.users (id)
            on update set null on delete set null
);

create index c_attachments_uuid_index
    on support.c_attachments (uuid);


