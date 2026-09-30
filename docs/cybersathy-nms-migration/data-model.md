# Target Data Model

The catalogue of PostgreSQL + TimescaleDB objects CyberSathy-NMS needs, which plan creates each, and where the data comes from. Alembic migrations are the source of truth; this file is the map.

## Conventions

- Primary key `id uuid default gen_random_uuid()`. Migrated tables also carry `legacy_id bigint unique null` (D-02).
- Timestamps are `timestamptz` in UTC, named `created_at`, `updated_at`, `occurred_at`.
- Network types are native: `inet` for IPs, `macaddr` for MACs. Not strings.
- Enumerations are `text` with a `CHECK` constraint, so adding a value is a one-line migration.
- Every table that holds scoped data has an owning scope path (device, group, OLT, PON, ONU, customer, interface, link) so the backend scope layer can join on it (Plan 4).
- Secrets are stored as `*_enc` ciphertext with a `key_id`. Never returned by the API.
- Each migration has a working `downgrade()` that never drops shared extensions.

## Existing (migrations 0001 and 0002)

| Table | Notes |
|---|---|
| `roles`, `permissions`, `role_permissions` | Add `is_dangerous` to `permissions` (Plan 4) |
| `users` | Replace `auth_key_hash` with `password_hash`, `totp_secret_enc`, `totp_enabled`, `strict_ip_enabled`, `allowed_ips` (Plan 36) |
| `user_sessions` | Make `auth_key_hash` unique, add `last_activity_at`, `2fa_verified` (Plan 3, 36) |
| `system_settings` | Kept |
| `device_groups`, `device_models`, `device_access_profiles`, `devices`, `interfaces` | Add `legacy_id`, `polling_owner`, `macaddr`/`inet` types, `key_id` on ciphertext (Plan 2, 9, 10) |
| `audit_logs` | Convert to a hypertable on `occurred_at`. Redaction rules apply to `before` and `after` |
| `user_device_group_scopes`, `user_device_scopes`, `user_interface_scopes` | Add `CHECK (scope_level in (...))` |

## New tables by plan

| Plan | Tables |
|---|---|
| 4 | `resellers`, `reseller_users` (built); `scope_olts`, `scope_pons`, `scope_onus`, `scope_customers`, `scope_links` (when those entities exist). No `visibility_cache`: scope is computed by query (D-11) |
| 5 | `vendors`, `vendor_model_families`, `capabilities`, `family_capabilities` (built) |
| 6 | `oid_definitions`, `oid_profiles`, `oid_profile_entries`, `oid_transform_rules` (built; `oid_namespaces` was not needed) |
| 7 | `mib_files`, `mib_objects` |
| 8 | Populate `device_models`; add `model_capabilities` |
| 9 | `discovery_jobs`, `discovery_results` |
| 10 | `interface_status_history` (hypertable), `interface_marks`, `interface_tags` |
| 11 | `device_poll_state` (breaker), `polling_results` (hypertable, built). Profiles are the `oid_profiles` of Plan 6. Jobs live on the stream, so there is no `polling_jobs` table |
| 12 | `worker_heartbeats`, `dead_letter_jobs`, `device_ping_status`, `device_ping_history` (hypertable) - all built |
| 13, 18 | `switch_interface_counters` (hypertable), `switch_interface_errors` (hypertable), `lldp_neighbors`, `sfp_readings` (hypertable), `fdb_history` (hypertable, bounded), `vlan_summary` |
| 14–17 | `olts`, `pon_ports`, `onus`, `onu_optical_history` (hypertable), `unregistered_onus`, `onu_down_reasons` |
| 19 | `arp_history` (hypertable), `bgp_sessions`, `bgp_history` (hypertable), `dhcp_leases_summary` |
| 20 | `events`, `events_history` (hypertable), `alarms`, `alarm_rules`, `alarm_audiences`, `maintenance_windows` |
| 21 | `trap_profiles` (built), `trap_history` (hypertable, built). No separate `unknown_traps`: an unrecognized OID is a `trap_history` row with a null `trap_profile_id`, not a second table |
| 23 | `dashboard_templates`, `user_dashboards`, `role_dashboards` |
| 26 | `action_requests`, `action_confirmations`, `action_results` |
| 27 | `links`, `link_external_names`, `paths`, `path_segments`, `path_states`, `lldp_discovery_history` |
| 28 | `locations` (lat/lon, indexed); PostGIS only if D-10 later requires |
| 29 | Built: `notification_contacts`, `notification_event_config`, `notification_event_ignored_devices`, `notifications` (hypertable - queue and history in one table, matching legacy's single `c_notifications`; no separate deliveries/failures split). No `notification_channels`: a contact's own `type` (`email`/`telegram_id`) is the channel, matching the legacy shape |
| 30 | `report_definitions`, `report_schedules`, `report_runs`, `report_artifacts` |
| 36 | `api_tokens`, `login_attempts`, `password_reset_tokens`, `totp_recovery_codes` |
| 37 | `schedule_jobs`, `schedule_runs` (built) |
| 38 | `macros`, `macro_runs`, `onu_registration_templates`, `console_sessions`, `console_history` |
| 39 | `integration_configs` (secrets encrypted), `attachments`, `sensor_devices`, `sensor_readings` (hypertable), `config_backups`, `config_backup_runs` |
| 41 | `legacy_route_usage` (counts per legacy route and caller) |

## Retention and compression (defaults, tune from capacity numbers in Plan 40)

| Hypertable | Chunk | Compress after | Keep raw | Continuous aggregate |
|---|---|---|---|---|
| `interface_status_history` | 7 days | 7 days | 1 year | daily uptime |
| `switch_interface_counters` | 1 day | 3 days | 30 days | 5-min, hourly, daily |
| `switch_interface_errors` | 1 day | 3 days | 90 days | hourly, daily |
| `onu_optical_history` | 1 day | 3 days | 90 days | hourly, daily |
| `polling_results` | 1 day | 2 days | 14 days | hourly success rate |
| `events_history` | 7 days | 14 days | 2 years | daily by type |
| `trap_history` | 7 days | 7 days | 90 days | none |
| `audit_logs` | 30 days | 30 days | 2 years, then archive | none |
| `fdb_history` | 1 day | 2 days | 30 days | none |
| `device_ping_history` | 1 day | 3 days | 30 days | hourly availability |
| `arp_history`, `bgp_history` | 7 days | 7 days | 90 days | none |

## Legacy to target mapping

| Legacy table | Target | Transform |
|---|---|---|
| `users` | `users` | Password hash imported as-is with `hash_scheme`; rehash on login. 2FA secret re-encrypted |
| `user_groups` | `roles`, `role_permissions` | Through [permission-mapping.md](permission-mapping.md); diff report required |
| `user_auth_keys` | `user_sessions` (active only) and `api_tokens` (long-lived) | Expired and revoked rows dropped |
| `user_devices_groups` | `user_device_group_scopes` | Scope level from role type |
| `device_accesses` | `device_access_profiles` | Decrypt with legacy key, re-encrypt with new key |
| `devices`, `device_groups`, `device_models` | same names | `legacy_id`, `inet` conversion, `polling_owner='legacy'` |
| `device_interfaces` | `interfaces` | Parent relations resolved in a second pass |
| `device_interfaces_history`, `collector_interfaces_status_history` | `interface_status_history` | Last N months only |
| `collector_ont_optical_history` | `onu_optical_history` | Last N months; values validated against Plan 6 scale rules |
| `collector_ont_ident` | `onus` | Identity by serial/MAC, not ifIndex |
| `c_events` | `events`, `events_history` | Open events stay open; resolved go to history |
| `c_events_alertmanager_rules` | `alarm_rules` | Expression validated before import |
| `system_schedule` | `schedule_jobs` | Commands translated to job types; unknown commands flagged, not imported |
| `system_actions`, `switcher_core_actions` | `audit_logs`, `device_call_log` | Window |
| `system_components`, `wca_migrations`, `support` | none | Dropped |

## Data quality checks (Plan 32)

Row counts per table; orphan foreign keys; duplicate management IPs; interfaces without a device; ONUs without a PON; optical values outside physical range (flag, do not import); users without a role; roles whose permission diff is non-empty.
