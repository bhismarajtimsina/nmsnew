alter table device_models
    modify type enum ('SWITCH', 'OLT', 'ONU', 'ROUTER', 'SENSOR', 'UNKNOWN', 'SWITCHL3') default 'SWITCH' not null;

