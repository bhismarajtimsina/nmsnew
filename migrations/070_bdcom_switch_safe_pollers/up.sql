-- BDCOM Ethernet switches can reboot or become overloaded when the scheduler
-- immediately runs a full FDB poll after the device is added.
--
-- The BDCOM switch FDB module uses SNMP walkBulk over Q-BRIDGE
-- dot1qTpFdbPort/dot1qTpFdbStatus. That is useful on demand, but on access
-- switches with large MAC tables it can be the heaviest first poller. A newly
-- added device has no poller history, so poller:poll-service treats every
-- default poller as due immediately.
--
-- Keep lightweight health/interface polling enabled. The BDCOM switch fdb
-- module remains available only for exact MAC+VLAN lookups, while scheduled
-- fdb_table collection is removed from model defaults.

UPDATE device_models
SET pollers = JSON_REMOVE(pollers, '$.fdb_table')
WHERE vendor = 'BDcom'
  AND type = 'SWITCH'
  AND pollers IS NOT NULL
  AND JSON_VALID(pollers)
  AND JSON_EXTRACT(pollers, '$.fdb_table') IS NOT NULL;
