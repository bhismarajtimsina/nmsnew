alter table device_interfaces
    add status enum ('Up', 'Down', 'Disabled', 'Online', 'Offline', 'Unknown') null;
