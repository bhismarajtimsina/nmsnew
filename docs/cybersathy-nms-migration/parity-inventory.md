# Parity Inventory

Cutover is allowed only when every row below is `Migrated`, `Kept as-is`, or `Dropped` with a reason. This file is the checklist the cutover go/no-go reads.

Sources: `components/`, `docker-compose.yml`, `src/Infrastructure/Poller/Pollers`, `config/console.yml`, `migrations/` and `components/*/migrations`, `docker/mysql/init.sql`.

## 1. Runtime services (`docker-compose.yml`)

Legacy names are shown only to identify what is being replaced. The new stack contains none of them ([own-components.md](own-components.md)).


| Legacy service | Role | Disposition | Plan |
|---|---|---|---|
| `wca` (RoadRunner + PHP API) | API | **Dropped**, replaced by `cybersathy-api` | 1, 41 |
| `wca-nginx` | Reverse proxy | Migrated to `cybersathy-nginx` | 1 |
| `wca-db` (MySQL) | Database | **Dropped**, replaced by PostgreSQL + TimescaleDB | 2, 32 |
| `wca-db-admin` (phpMyAdmin) | DB UI | **Dropped**, see D-12 | 39 |
| `wca-memcached` | Cache | **Dropped**, Redis is the only cache | 12 |
| `wca-redis` | Queue and cache | Migrated to `cybersathy-redis` with persistence | 1, 12 |
| `wca-ws` (PHP/Swoole WebSocket) | Realtime | Migrated into the API | 22 |
| `wca-schedule-executor` | Cron | Replaced by `scheduler` service | 37 |
| `wca-icmp-pinger` (Go) | ICMP up/down | **Replaced** by our own `pinger` worker (D-20) | 12 |
| `wca-traplistener` | SNMP traps | **Replaced** by our own `trap-receiver` worker; takes over the listener's address at cutover (D-19) | 21, 34 |
| `wca-eap` (Go external-apps proxy) | Auth proxy for Grafana etc. | **Replaced** by Nginx `auth_request` against the API (D-21) | 36, 39 |
| `wca-ttyd` | Web terminal | **Replaced** by our own `console-gateway` with an xterm.js frontend | 38 |
| `wca-oxidized` | Config backup | **Replaced** by our own `config-backup` worker | 39 |
| `wca-prometheus`, `wca-alertmanager`, `wca-grafana`, `wca-loki`, `wca-alloy` | Observability | Kept as **official upstream images** (the `meklis/*` Alertmanager fork is not used); scrape targets and dashboards rebuilt | 31 |
| `wca-snmp-exporter`, `wca-blackbox-exporter` | Metrics probes | Kept as official upstream images | 31 |
| `wca-goflow2` | NetFlow/sFlow | Upstream open-source collector, optional; decided in Plan 31 | 31 |
| `wca-cadvisor`, `wca-nodeexporter` | Host/container metrics | Replaced by official upstream cAdvisor and node-exporter images | 31, 40 |
| `wca-swagger-ui` | API docs | Replaced by FastAPI `/api/v1/docs` | 24 |

## 2. Components (`components/`)

| Component | Function | Disposition | Plan |
|---|---|---|---|
| Analytics | Signal and utilization analytics | Migrated, thresholds to `system_settings` | 23, 30 |
| Attachments | File attachments on devices | Migrated | 39 |
| AutoDiscovery | Network discovery | Migrated, **safe-discovery only** | 9 |
| AutoTopology | LLDP-based topology | Migrated | 27 |
| Console | Web console/CLI | **Replaced** by own `console-gateway` (Dangerous) | 38 |
| Diagnostic | ARP ping, ICMP ping, traceroute | Migrated, rate-limited | 38 |
| Events | Events, alarm rules, Alertmanager rule writer | Migrated (D-24) | 20 |
| FdbHistory | MAC history per interface | Migrated, bounded | 13, 18 |
| Links | Link records | Migrated | 27 |
| LiveTraffic | Live interface traffic | Migrated | 31 |
| Macros | Templated commands | Migrated (Dangerous, reviewed templates) | 38 |
| MikBillIntegration | Billing integration | Migrated | 39 |
| NoDenyPlus | Billing integration | Migrated | 39 |
| Notifications | Telegram/mail/webhook | Migrated | 29 |
| Olts | OLT read APIs | Migrated | 14–17 |
| OltsControl | OLT/ONU actions | Migrated (Dangerous) | 38 |
| OntsRegistration | ONU registration templates | Migrated (Dangerous) | 38 |
| Oxidized | Config backup | **Replaced** by own `config-backup` worker | 39 |
| Paths | Path and segment state | Migrated | 27 |
| Pinger | ICMP status and exporter | **Replaced** by own `pinger` worker | 12 |
| PrometheusWrapper | Chart proxy | Migrated | 31 |
| QrGenerator | QR labels | Migrated | 39 |
| RouterOS | MikroTik specifics | Migrated | 19 |
| Routers | Router read APIs | Migrated | 19 |
| SearchDevice | MAC/IP/port lookup | Migrated, **bounded lookup only** | 13, 18 |
| SensorDevices | Sensor and relay devices | Migrated | 39 |
| Switches | Switch read APIs | Migrated | 13, 18 |
| SwitchesControl | Switch actions | Migrated (Dangerous) | 38 |
| TrapService | Trap handling | **Replaced** by own `trap-receiver` | 21 |
| UsersideIntegration | Helpdesk integration | Migrated | 39 |

## 3. Pollers (`src/Infrastructure/Poller/Pollers`)

| Legacy poller | New profile | Plan |
|---|---|---|
| WalkSystemInfo | `safe_discovery` | 11 |
| DeviceInterfacesList | `switch_basic`, `olt_basic` | 10, 11 |
| DeviceInterfacesStatus | `switch_basic` | 10, 11 |
| CountersPoller | `switch_counters` | 11, 13, 18 |
| ResourcesPoller | `switch_basic`, `olt_basic`, `router_basic` | 11 |
| CardsStatusPoller | `olt_basic` | 14–17 |
| PonPortLoadingPoller | `olt_basic` (**add to Plan 11 list**) | 11, 14 |
| OntIdentification | `olt_onu_list` (**add**) | 11, 14 |
| OntVendorInfo | `olt_onu_list` | 14 |
| OpticalStrengthHistory | `olt_optical` | 11, 14–17 |
| SfpOpticalStrengthHistory | `switch_sfp` (**add**) | 13, 18 |
| UnregisteredOntsPoller | `olt_onu_list` | 14–17 |
| LldpInfoPoller | `switch_lldp` (**add**) | 13, 18, 27 |
| FdbHistory | `switch_fdb_bounded` (**add, off by default**) | 13, 18 |
| ArpsPoller | `router_arp` (bounded) | 19 |
| BgpSessionsPoller | `router_bgp` | 19 |
| SensorsDataPoller | `sensor_basic` (**add**) | 39 |

## 4. Legacy database tables

Core (`docker/mysql/init.sql`) and component migrations. Mapping detail in [data-model.md](data-model.md).

| Legacy table | Disposition |
|---|---|
| `users`, `user_groups`, `user_auth_keys`, `user_devices_groups` | Migrated: `users`, `roles`, `user_sessions`, `api_tokens`, scope tables |
| `devices`, `device_models`, `device_accesses`, `device_groups`, `device_interfaces` | Migrated (credentials re-encrypted) |
| `device_interfaces_history`, `collector_interfaces_status_history` | Migrated as hypertable, bounded window |
| `collector_arp_history`, `collector_bgp_sessions`, `collector_fdb_history`, `collector_ont_ident`, `collector_ont_optical_history`, `collector_processing` | Migrated, bounded window, stale rows dropped |
| `c_events`, `c_events_alertmanager_rules` | Migrated |
| `c_links`, `c_links_external_names`, `c_paths`, `c_path_segments`, `c_path_states` | Migrated |
| `c_macros`, `c_onts_registration_macros`, `c_console_history` | Migrated |
| `c_notifications`, `c_notifications_actions_config`, `c_notifications_contacts`, `c_notifications_events_config`, `c_notifications_ignore_devices` | Migrated |
| `c_pinger_statuses`, `c_pinger_down_logs` | Migrated (recent window) |
| `c_trap_logs` | Migrated (recent window) |
| `c_autodiscovery_networks` | Migrated |
| `system_schedule`, `system_schedule_reports` | Migrated into `schedule_jobs`, `schedule_runs` |
| `system_actions`, `switcher_core_actions` | Migrated to `audit_logs` and `device_call_log` (window) |
| `system_components`, `wca_migrations`, `support` | **Dropped**, bookkeeping of the old installer |

## 5. Migrations 002–070: behavior that must not be lost

| Migration group | Behavior to carry over |
|---|---|
| 002–004, 010, 013, 021 | Access params, group params, model controller, device location, user language, device groups |
| 005–008, 017, 022, 025, 028, 030–031 | Collector configuration and processing state |
| 026–027, 039, 058–061 | Editable schedule, session recalculation, schedule jobs |
| 040–042, 045, 051, 055, 057 | Parent interface, interface types, L3 and RouterOS device types |
| 043–044, 046, 049–050, 052 | Tags, vendor info, statuses, new pollers |
| 053, 061 (2FA) | TOTP 2FA fields and global disable switch |
| 054 | Interface history |
| 056 | Encrypted accesses |
| 062, 070 | BDCOM switch models and **safe** pollers |
| 063, 066 | ISP and reseller roles, full-control and focus |
| 064, 065, 067 | Alarm audience, interface-down alarm, complete alarm rules |
| 068 | Role-specific dashboards |
| 069 | "Outage sees LOS" |

Migrations 062–070 are the newest business rules. Plan 32 imports their **results** (roles, alarm rules, dashboards) and Plan 33 adds a test per rule.

## 6. Console commands (`config/console.yml`, 59 handlers)

Groups and their new home: SwitcherCore (fixture-only tools, Plan 33), Security (Plan 36), Cache (dropped with Memcached, Redis flush kept in Plan 40), Schedule (Plan 37), System (Plan 40), User (Plan 36), Devices/Access (admin CLI in Plan 9), Migrations (Alembic), Poller (Plan 11), Supervisor (dropped, containers replace it). Each group gets a `python -m app.cli` equivalent or is dropped with a reason in the plan.

## 7. Configuration

`.env` parameters map in [env-parameter-mapping.md](env-parameter-mapping.md). API routes map in [api-compatibility.md](api-compatibility.md). Permissions map in [permission-mapping.md](permission-mapping.md).
