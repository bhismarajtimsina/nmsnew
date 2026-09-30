# Working rules for this repository

## Never touch live network devices

This deployment monitors production OLTs and switches carrying real
subscribers. Do not contact any of them, for any reason, including "just one
read to check".

Forbidden, without exception:

- `snmpwalk`, `snmpget`, `snmpbulkwalk`, and any other SNMP client
- `wca test:snmpwalk <ip> ...`
- `wca switcher-core:call <device-ip> <module> ...`
- API calls that reach a device rather than the database, such as
  `/api/v1/device/{id}/compare-model`, `/api/v1/device-interface/by-device/{id}`,
  and `/api/v1/nms/devices/{id}/status`
- telnet, ssh, or console access to a device

The user has stated this repeatedly and it is not negotiable. It applies even
when a change looks unverifiable without hardware, and even when a previous
session in the same conversation did it.

## Verify from configuration and code instead

Everything below runs offline and touches no device:

- `docker exec wca php /www/tools/bdcom-oid-coverage.php` — every model
  declares the OID names its modules read unguarded
- `python3 tools/bdcom-mib-oid-map.py <out.json> [mib-dir]` — resolves every
  object in BDCOM's MIBs to its numeric OID, for checking a declared OID is
  the one the MIB assigns
- `python3 tools/bdcom-trap-audit.py` — every trap OID against the MIBs'
  NOTIFICATION-TYPE definitions
- `yaml_parse_file()` on any config, and `ModelCollector` / `OidCollector` /
  `TrapCollector` to confirm models, OID names and traps resolve

Reading the database (`docker exec wca-db mysql ...`) and the log files under
`var/logs/` is fine — neither reaches a device.

When a change genuinely cannot be confirmed without hardware, say so plainly
in the summary and leave it unverified. An honest "not verified against a
device" is the correct outcome, not a reason to poll one.
