alter table device_models
    add controller varchar(200) null;

alter table device_models
    add collectors json null;
