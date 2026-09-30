alter table device_interfaces
    modify type enum ('GE', 'ETH', 'PON', 'SFP', 'ONU', 'UNI', '1G-SFP', '10G-SFP', 'UNKNOWN', 'VLAN') null;

