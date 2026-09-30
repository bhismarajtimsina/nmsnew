alter table c_autodiscovery_networks
    drop key c_autodiscovery_networks_cidr_uindex;

alter table c_autodiscovery_networks
    add constraint c_autodiscovery_networks_cidr_uindex
        unique (cidr, access_id);