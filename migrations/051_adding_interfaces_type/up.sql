alter table device_interfaces
    modify type enum ('FE',
        'GE',
        'TGE',
        'ETH',
        'PON',
        'SFP',
        'ONU',
        'UNI',
        '1G-SFP',
        '10G-SFP',
        'UNKNOWN',
        'VLAN',
        'LACP',
        'BRIDGE',
        'Loopback',
        'Virtual',
        'IP',
        'EPON'
        ) null;

