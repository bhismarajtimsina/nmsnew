ALTER TABLE `system_schedule_reports` CHANGE `output` `output` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL;
alter table system_schedule_reports
    modify output longtext null;

alter table poller_processing
    modify poller enum ('sfp_optical_strength', 'pon_port_loading', 'sys_resources', 'counters', 'system', 'interfaces_list', 'fdb_table', 'interfaces_status', 'optical_strength', 'ont_ident', 'bgp_sessions', 'arp_table', 'sensors_data', 'ont_vendor_info', 'cards_status') null;

