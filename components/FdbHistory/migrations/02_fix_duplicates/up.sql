#QUERY
SET SESSION innodb_lock_wait_timeout = 600;
START TRANSACTION;
DROP TABLE IF EXISTS for_replace;
CREATE TEMPORARY table for_replace
SELECT max(device_id) device_id, interface_id, mac_address, vlan_id, min(start_at) start_at, max(stop_at) stop_at
FROM poll_fdb_history GROUP BY interface_id, mac_address, vlan_id;
DELETE FROM poll_fdb_history;
create unique index poll_fdb_history_interface_id_vlan_id_mac_address_uindex
    on poll_fdb_history (interface_id, vlan_id, mac_address);
INSERT IGNORE INTO poll_fdb_history (device_id, interface_id, mac_address, vlan_id, start_at, stop_at)
SELECT device_id, interface_id, mac_address, vlan_id, start_at, stop_at FROM for_replace;
COMMIT;
