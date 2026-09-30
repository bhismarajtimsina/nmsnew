alter table user_auth_keys
    add is_manual tinyint default 0 not null;

alter table user_auth_keys
    add description varchar(500) default '' not null;

