alter table device_accesses
    modify name varchar(255) not null;

alter table device_accesses
    modify public_community varchar(255) not null;

alter table device_accesses
    modify private_community varchar(255) null;

alter table device_accesses
    modify login varchar(255) null;

alter table device_accesses
    modify password varchar(255) not null;

