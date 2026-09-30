alter table device_interfaces
    modify status enum ('Up', 'Down', 'Disabled', 'Online', 'Offline', 'Unknown', 'PowerOff', 'LOS') null;

