create table c_events_alertmanager_rules
(
    id                     int auto_increment
        primary key,
    created_at             datetime                             not null,
    updated_at             datetime                             not null,
    group_name             varchar(100)                         not null,
    alert_name             varchar(150)                         not null,
    expression             varchar(500)                         not null,
    `for`                  varchar(30)                          not null,
    severity               enum ('info', 'warning', 'critical') not null,
    annotation_summary     varchar(500)                         not null,
    annotation_description varchar(500)                         not null,
    enabled                tinyint default 1                    not null,
    constraint c_events_alertmanager_rules_alert_name_uindex
        unique (alert_name)
)
    auto_increment = 10000;

INSERT INTO `c_events_alertmanager_rules`
VALUES
    (1,'2022-07-24 00:22:21','2022-07-24 00:22:21','interfaces','iface_increase_in_errors','delta(iface_stat_in_errors[15m]) > 30','10m','warning','Errors increased','Errors increased on {{ $labels.iface_name }} on host {{ $labels.ip }} more than {{ humanize $value }} per 15minutes',1),
    (2,'2022-07-24 00:22:21','2022-07-24 00:22:21','interfaces','iface_increase_out_errors','delta(iface_stat_out_errors[15m]) > 30','30m','warning','Errors increased','Errors increased on {{ $labels.iface_name }} on host {{ $labels.ip }} more than {{ humanize $value }} per 15minutes',1),
    (3,'2022-07-24 00:22:21','2022-07-24 00:22:21','interfaces','interface_is_down','device_interface_status == 0 and device_interface_admin_state == 1','5m','info','Interface is DOWN','Interface {{ $labels.iface_name }} DOWN on host {{ $labels.ip }}',1),
    (4,'2022-07-24 00:22:21','2022-07-24 00:22:21','optical','olt_rx_signal_deteriorated','avg_over_time(optical_olt_rx[1d:1h]) - avg_over_time(optical_olt_rx[30m]) > 2','10m','warning','OLT RX signal deteriorated','OLT RX signal deteriorated on ONU {{ $labels.iface_name }}, OLT {{ $labels.ip }}',1),
    (5,'2022-07-24 00:22:21','2022-07-24 00:22:21','pon','pon_mass_onts_down','round(delta(count(label_replace(device_interface_status{iface_name=~\".*:.*\"}, \"pon_port\", \"$1\", \"iface_name\", \"^(.*):[0-9]{1,4}$\") == 0) by (dev_id, ip, pon_port)[15m:])) >= 3','10m','warning','Mass ONTs is offile on PON port','{{humanize $value}} ONTs was OFFLINE now on device {{ $labels.ip }}, on PON port {{ $labels.pon_port }}',1),
    (6,'2022-07-24 00:22:21','2022-07-24 00:22:21','optical','rx_signal_deteriorated','avg_over_time(optical_rx[1d:1h]) - avg_over_time(optical_rx[30m]) > 2','10m','warning','RX signal deteriorated','RX signal deteriorated on ONU {{ $labels.iface_name }}, OLT {{ $labels.ip }}',1),
    (7,'2022-07-24 00:22:21','2022-07-24 00:22:21','device_resources','sys_cpu_highload','device_resources_cpu_util > 80','15m','warning','CPU highload','CPU on device {{ $labels.ip }} highload, value {{humanize $value}}',1);

