# Permission Mapping: Legacy Route Keys to CyberSathy Permission Codes

> Generated from `config/rules.yml` and `components/*/rules.yml` (99 legacy entries, 92 distinct keys). Consumed by Plan 4 (role import) and Plan 32 (data migration).

## Why this exists

Legacy access control is a set of **route-regex rules**: each key in a `rules.yml` file owns a list of `METHOD:/path` patterns, and `PermissionCheckMiddleware` checks the caller's role against whichever rule matches the request. The seed in `backend/app/scripts/seed_admin.py` currently defines only 17 coarse codes. Migrating roles without this table would either widen or silently remove access.

## Rules for the migration

1. **Every legacy key maps to exactly one new code**, or is explicitly dropped. Keys that appear in more than one component (`device_show`, `device_management`, `diag_interface`, `external_apps_oxidized`) merge into one code.

2. **A role's new permission set is the union of the codes its legacy keys map to.** The import writes a report of anything added or removed per role; a non-empty diff blocks the import until a human signs it off.

3. **Codes flagged Dangerous** need the fine-grained code, the coarse `dangerous_actions.execute` gate, an in-scope target, and a confirmation token (Plan 26). Holding only the coarse gate is not enough.

4. **Route-to-permission binding moves from regex to code.** Every FastAPI endpoint declares its permission via a dependency, and a CI test fails if any route under `/api/v1` has none (Plan 33).

5. **Seed codes stay.** The 17 existing seed codes remain valid aliases (see the second table) so the running foundation keeps working.

## Mapping

| Legacy source | Legacy key | Legacy group | Route rules | New code | Dangerous | Note |
|---|---|---|---|---|---|---|
| core | `system_info` | web_portal | 4 | `portal.view` |  | Shell data: settings, groups, logout |
| core | `dashboard_global_edit` | web_portal | 0 | `portal.dashboard.edit_global` |  |  |
| core | `dashboard_edit` | web_portal | 1 | `portal.dashboard.edit_own` |  |  |
| core | `global_search` | web_portal | 1 | `portal.search` |  |  |
| core | `system_configuration` | system | 1 | `system.configure` |  | Also gates `/system/*` catch-all; split per endpoint |
| core | `system_cross_auth_user` | system | 1 | `tokens.cross_auth` |  | Plan 36 |
| core | `system_status` | system | 1 | `system.status.view` |  |  |
| core | `schedule_configuration` | system | 1 | `system.schedule.manage` |  | Plan 37 |
| core | `system_logs_actions` | system | 2 | `logs.actions.view` |  |  |
| core | `schedule_reports` | system | 1 | `system.schedule.reports.view` |  | Plan 37 |
| core | `log_switcher_core_actions` | system | 2 | `logs.device_calls.view` |  | Renamed: device call log |
| core | `log_poller` | system | 1 | `logs.poller.view` |  |  |
| core | `poller_info_by_device` | system | 1 | `pollers.view` |  |  |
| core | `run_poller_by_device` | system | 1 | `pollers.run` |  | Rate-limited, audited |
| core | `user_management` | user_management | 4 | `users.manage` |  |  |
| core | `user_management_config_strict_ip` | user_management | 0 | `users.strict_ip.manage` |  | Plan 36 |
| core | `user_self_control` | user_management | 4 | `users.self.manage` |  | Own profile, 2FA, password |
| core | `user_display` | user_management | 4 | `users.view` |  |  |
| core | `device_access_management` | device_management | 1 | `device_access.manage` | yes | Touches SNMP/console credentials; never returns secrets |
| core | `device_groups_management` | device_management | 1 | `device_groups.manage` |  |  |
| core | `device_show` | device_management | 14 | `devices.view` |  | Also used by Pinger, Userside routes |
| core | `device_iface_management` | device_management | 1 | `interfaces.manage` |  |  |
| core | `device_iface_manage_marks` | device_management | 1 | `interfaces.marks.manage` |  |  |
| core | `device_management` | device_management | 8 | `devices.manage` |  | Add/edit/delete; delete is dangerous |
| core | `external_apps_grafana` | external_apps | 0 | `external_apps.grafana.view` |  | Plan 39 |
| core | `external_apps_grafana_admin` | external_apps | 0 | `external_apps.grafana.admin` |  | Plan 39 |
| core | `external_apps_prometheus` | external_apps | 0 | `external_apps.prometheus.view` |  | Plan 39 |
| core | `external_apps_alertmanager` | external_apps | 0 | `external_apps.alertmanager.view` |  | Plan 39 |
| core | `external_apps_phpmyadmin` | external_apps | 0 | (dropped) |  | phpMyAdmin leaves with MySQL. Decision D-12 for a Postgres admin UI |
| core | `external_apps_oxidized` | external_apps | 0 | `config_backups.view` |  | Plan 39 |
| Analytics | `analytics` | analytics | 1 | `analytics.view` |  |  |
| Attachments | `attachments_view` | attachments | 1 | `attachments.view` |  |  |
| Attachments | `attachments_upload` | attachments | 1 | `attachments.upload` |  |  |
| Attachments | `attachments_delete` | attachments | 1 | `attachments.delete` | yes |  |
| Console | `external_apps_console` | console | 1 | `console.open` | yes | Plan 38; interactive device shell |
| Console | `external_apps_console_with_auto_auth` | console | 1 | `console.open_auto_auth` | yes | Plan 38; injects device credentials |
| Console | `external_apps_console_view_logs` | console | 2 | `console.logs.view` |  | Plan 38 |
| Diagnostic | `diag_arp_ping` | diag | 1 | `diagnostics.arp_ping` |  | Live device probe, rate-limited |
| Diagnostic | `diag_icmp_ping` | diag | 1 | `diagnostics.icmp_ping` |  | Live probe, rate-limited. Legacy `rules.yml` maps this key to the `/arp-ping` route, so in legacy it grants ARP ping, not ICMP; the new key grants only the ICMP ping (Plan 38) |
| Diagnostic | `diag_traceroute` | diag | 1 | `diagnostics.traceroute` |  | Live probe, rate-limited |
| Diagnostic | `diag_interface` | diag | 2 | `diagnostics.interface` |  | Shared by Diagnostic, MikBill, NoDeny, TrapService |
| Events | `events_show` | events | 1 | `events.view` |  |  |
| Events | `events_resolve` | events | 2 | `events.resolve` |  |  |
| Events | `events_configuration` | events | 1 | `events.configure` |  | Alarm rules; validates PromQL |
| Events | `events_see_all` | events | 0 | `events.see_all` |  | ISP/Admin visibility override; never granted to resellers |
| FdbHistory | `fdb_history_by_interface` | fdb_history | 1 | `fdb_history.view` |  |  |
| Links | `links_view` | links | 3 | `links.view` |  |  |
| Links | `links_edit` | links | 1 | `links.edit` |  |  |
| LiveTraffic | `live_traffic_info` | live_traffic | 1 | `live_traffic.view` |  |  |
| Macros | `macros_execute` | macros | 1 | `macros.execute` | yes | Plan 38 |
| Macros | `macros_edit` | macros | 1 | `macros.edit` |  | Plan 38; templates are code-like, review required |
| MikBillIntegration | `diag_interface` | diag | 1 | `diagnostics.interface` |  | Shared by Diagnostic, MikBill, NoDeny, TrapService |
| NoDenyPlus | `diag_interface` | diag | 1 | `diagnostics.interface` |  | Shared by Diagnostic, MikBill, NoDeny, TrapService |
| Notifications | `notifications_full_access` | notifications | 1 | `notifications.admin` |  |  |
| Notifications | `notifications_configure_contacts` | notifications | 3 | `notifications.contacts.manage` |  |  |
| Notifications | `notifications_send_global_notify` | notifications | 0 | `notifications.send_global` |  |  |
| Notifications | `notifications_configure_self_contacts` | notifications | 3 | `notifications.contacts.self` |  |  |
| Notifications | `notifications_view_history` | notifications | 1 | `notifications.history.view` |  |  |
| Olts | `olts_info` | olts | 1 | `olts.view` |  | Includes ONU read APIs (`onus.view`) |
| OltsControl | `olts_ctrl_dereg` | olts | 1 | `olts.onu.deregister` | yes | Plan 38 |
| OltsControl | `olts_ctrl_clear_pon` | olts | 1 | `olts.pon.clear` | yes | Plan 38 |
| OltsControl | `olts_ctrl_reboot` | olts | 1 | `olts.onu.reboot` | yes | Plan 38 |
| OltsControl | `olts_ctrl_clear_counters` | olts | 1 | `olts.counters.clear` | yes | Plan 38 |
| OltsControl | `olts_ctrl_reset` | olts | 1 | `olts.onu.reset` | yes | Plan 38 |
| OltsControl | `olts_ctrl_disable` | olts | 1 | `olts.onu.disable` | yes | Plan 38 |
| OltsControl | `olts_ctrl_description` | olts | 1 | `olts.onu.set_description` |  | Plan 38; audited |
| OltsControl | `olts_ctrl_port` | olts | 2 | `olts.port.control` | yes | Plan 38 |
| OltsControl | `olts_ctrl_uni_ports` | olts | 1 | `olts.onu.uni_ports` | yes | Plan 38 |
| OntsRegistration | `unregistered_onts` | olts | 1 | `onus.unregistered.view` |  |  |
| OntsRegistration | `unregistered_onts_preview` | olts | 1 | `onus.registration.preview` |  | Renders template; does not touch device |
| OntsRegistration | `unregistered_onts_result_output` | olts | 1 | `onus.registration.view_output` |  |  |
| OntsRegistration | `unregistered_onts_config` | olts | 1 | `onus.registration.configure` |  | Templates are code-like, review required |
| Oxidized | `external_apps_oxidized` | oxidized | 1 | `config_backups.view` |  | Plan 39 |
| Oxidized | `device_management` | device_management | 1 | `devices.manage` |  | Add/edit/delete; delete is dangerous |
| Paths | `paths_view` | paths | 3 | `paths.view` |  |  |
| Paths | `paths_edit` | paths | 1 | `paths.edit` |  |  |
| Pinger | `device_show` | device_management | 4 | `devices.view` |  | Also used by Pinger, Userside routes |
| PrometheusWrapper | `prom_chart_info` | prom_wrapper | 1 | `charts.view` |  |  |
| QrGenerator | `view_qr_codes` | qr_generator | 1 | `qr.view` |  |  |
| QrGenerator | `view_mass_qr_codes` | qr_generator | 1 | `qr.view_bulk` |  |  |
| RouterOS | `router_os_info` | router_os | 2 | `routers.routeros.view` |  |  |
| Routers | `router_info` | router | 1 | `routers.view` |  |  |
| SearchDevice | `sd_search_mac` | sd | 1 | `search.mac` |  | Bounded lookup only (Plan 13) |
| SearchDevice | `sd_search_ip` | sd | 1 | `search.ip` |  |  |
| SearchDevice | `sd_search_ip_with_port` | sd | 1 | `search.ip_port` |  |  |
| SensorDevices | `sensor_devices_view` | sensor_devices | 3 | `sensors.view` |  |  |
| SensorDevices | `sensor_devices_allow_switch_modes` | sensor_devices | 1 | `sensors.switch_mode` | yes | Toggles physical relays |
| SensorDevices | `sensor_devices_configure` | sensor_devices | 1 | `sensors.configure` |  |  |
| Switches | `switches_info` | switches | 3 | `switches.view` |  |  |
| SwitchesControl | `switches_reboot_device` | switches | 1 | `switches.reboot` | yes | Plan 38 |
| SwitchesControl | `switches_save_config` | switches | 1 | `switches.save_config` | yes | Plan 38 |
| SwitchesControl | `switches_clear_counters` | switches | 2 | `switches.counters.clear` | yes | Plan 38 |
| SwitchesControl | `switches_set_port_description` | switches | 1 | `switches.port.set_description` |  | Plan 38; audited |
| SwitchesControl | `switches_set_port_admin_state` | switches | 1 | `switches.port.set_admin_state` | yes | Plan 38 |
| SwitchesControl | `switches_set_port_admin_speed` | switches | 1 | `switches.port.set_speed` | yes | Plan 38 |
| TrapService | `diag_interface` | diag | 1 | `diagnostics.interface` |  | Shared by Diagnostic, MikBill, NoDeny, TrapService |
| UsersideIntegration | `device_show` | device_management | 1 | `devices.view` |  | Also used by Pinger, Userside routes |
| UsersideIntegration | `utels_allow_exclude_from_stat` | userside_integration | 1 | `userside.exclude_from_stat` |  | Plan 39 |
| UsersideIntegration | `utels_allow_set_comment` | userside_integration | 1 | `userside.set_comment` |  | Plan 39 |

## Existing seed codes and what they become

| Seed code (backend/app/scripts/seed_admin.py) | Becomes |
|---|---|
| `users.view` / `users.manage` | unchanged |
| `roles.view` / `roles.manage` / `permissions.view` | unchanged (legacy `user-role` API) |
| `scope.manage` | unchanged (Plan 4) |
| `devices.view` / `devices.manage` | unchanged |
| `interfaces.view` / `interfaces.manage` | unchanged |
| `olts.view` / `onus.view` / `switches.view` / `routers.view` | unchanged |
| `events.view` | unchanged |
| `system.manage` | split into `system.configure`, `system.schedule.manage`, `system.status.view` |
| `dangerous_actions.execute` | kept as the global gate for every Dangerous code |

## Counts

- Distinct new codes: **91** (plus the seed codes that already exist).
- Dangerous codes: **19**: `attachments.delete`, `console.open`, `console.open_auto_auth`, `device_access.manage`, `macros.execute`, `olts.counters.clear`, `olts.onu.deregister`, `olts.onu.disable`, `olts.onu.reboot`, `olts.onu.reset`, `olts.onu.uni_ports`, `olts.pon.clear`, `olts.port.control`, `sensors.switch_mode`, `switches.counters.clear`, `switches.port.set_admin_state`, `switches.port.set_speed`, `switches.reboot`, `switches.save_config`.

## Not covered by legacy keys (new in CyberSathy)

`reseller.*` assignment management (Plan 4), `api_tokens.manage` (Plan 36), `vendors.manage`, `oid_profiles.manage`, `mib.view`, `device_models.manage` (Plans 5–8), `reports.view`/`reports.manage` (Plan 30), `topology.view`, `maps.view` (Plans 27–28).
