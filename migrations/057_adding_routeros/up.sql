alter table device_models
    modify type enum ('SWITCH', 'OLT', 'ONU', 'ROUTER', 'SENSOR', 'UNKNOWN', 'SWITCHL3', 'ROUTEROS') default 'SWITCH' not null;
