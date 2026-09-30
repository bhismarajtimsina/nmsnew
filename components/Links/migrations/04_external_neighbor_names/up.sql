-- Custom display names for LLDP-discovered neighbors that aren't a real
-- Device in this system's inventory at all (another company's switch on a
-- shared uplink, most commonly) — these are synthesized read-only
-- pseudo-nodes on the topology graph (Controller::getExternalNeighbors),
-- never a row in `devices`, so there's nowhere else to persist a friendly
-- name for one. Keyed by the same "ext:<local_device_id>:<chassis_mac>"
-- id already used to identify these pseudo-nodes everywhere else.
create table c_links_external_names
(
    id         varchar(191)                       not null primary key,
    name       varchar(255)                       not null,
    created_at datetime default CURRENT_TIMESTAMP not null,
    updated_at datetime default CURRENT_TIMESTAMP not null on update CURRENT_TIMESTAMP
);
