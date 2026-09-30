INSERT INTO system_components(name, `key`, enabled, configuration)
VALUES ('Transport paths', 'paths', 1, null);

-- A named transport route between two endpoint devices, e.g.
-- 'Kathmandu -> Belbari -> Pathari'. Paths sharing a group_key protect each
-- other: losing one while another stays up means the service is unprotected.
create table c_paths
(
    id            int auto_increment primary key,
    created_at    datetime     default CURRENT_TIMESTAMP not null,
    updated_at    datetime     default CURRENT_TIMESTAMP not null,
    name          varchar(255)                           not null,
    group_key     varchar(190)                           null,
    priority      int          default 100               not null,
    endpoint_a_id int                                    not null,
    endpoint_b_id int                                    not null,
    enabled       tinyint(1)   default 1                 not null,
    params        json                                   null,
    constraint c_paths_devices_id_fk
        foreign key (endpoint_a_id) references devices (id)
            on update cascade on delete cascade,
    constraint c_paths_devices_id_fk_2
        foreign key (endpoint_b_id) references devices (id)
            on update cascade on delete cascade
);

create index c_paths_group_key_index on c_paths (group_key);

-- Ordered hops. Segments reference existing c_links rows so LLDP/FDB-discovered
-- adjacency is reused rather than re-entered by hand.
create table c_path_segments
(
    id       int auto_increment primary key,
    path_id  int not null,
    position int not null,
    link_id  int not null,
    constraint c_path_segments_uniq_position
        unique (path_id, position),
    constraint c_path_segments_c_paths_id_fk
        foreign key (path_id) references c_paths (id)
            on update cascade on delete cascade,
    constraint c_path_segments_c_links_id_fk
        foreign key (link_id) references c_links (id)
            on update cascade on delete cascade
);

-- Current computed state, kept denormalised so map reads stay a single query.
create table c_path_states
(
    path_id     int primary key,
    state       enum ('up', 'degraded', 'down', 'unknown') default 'unknown' not null,
    last_change datetime                                                     null,
    updated_at  datetime default CURRENT_TIMESTAMP                           not null,
    detail      json                                                         null,
    constraint c_path_states_c_paths_id_fk
        foreign key (path_id) references c_paths (id)
            on update cascade on delete cascade
);

INSERT INTO system_schedule (
    `key`, created_at, latest, component_id, crontab, command, state, editable
) VALUES (
    'paths_state_calculator',
    NOW(),
    null,
    (SELECT id FROM system_components WHERE `key` = 'paths'),
    '*/1 * * * *',
    'wca paths:calc-state',
    'ENABLED',
    1
);

INSERT IGNORE INTO c_events_alertmanager_rules
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity,
     annotation_summary, annotation_description, enabled, internal)
VALUES
    (NOW(), NOW(), 'paths', 'path_down',
     'path_state == 0', '2m', 'critical',
     'Transport path is down',
     'Path {{ $labels.path_name }} ({{ $labels.endpoint_a_name }} -> {{ $labels.endpoint_b_name }}) is down.',
     1, 1),
    (NOW(), NOW(), 'paths', 'path_degraded',
     'path_state == 0.5', '5m', 'warning',
     'Transport path is degraded',
     'Path {{ $labels.path_name }} is up but latency on at least one hop is above the configured threshold.',
     1, 1),
    (NOW(), NOW(), 'paths', 'path_group_unprotected',
     'path_group_protected == 0 and path_group_up_count > 0', '2m', 'warning',
     'Redundancy lost - group is unprotected',
     'Group {{ $labels.group_key }} still carries traffic but has lost redundancy: {{ $labels.group_key }} has {{ $value }} protected state. A further failure will cause an outage.',
     1, 1),
    (NOW(), NOW(), 'paths', 'path_group_outage',
     'path_group_up_count == 0', '1m', 'critical',
     'All paths in group are down',
     'Every path in group {{ $labels.group_key }} is down - the destination is unreachable.',
     1, 1);
