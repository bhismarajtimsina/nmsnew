# API Compatibility Map

> Inventory of the legacy `/api/v1` surface and where each endpoint goes. Feeds [Plan 41](41-legacy-api-compatibility.md). Route tables below are extracted from `app/routes.php` and `components/*/config.php` (109 core routes and 209 component routes).

## Why this matters

The new FastAPI backend uses different paths, request bodies and response shapes than the PHP API. The current Vue frontend, the external-apps proxy, and any outside integration call the old surface. Cutting over without a compatibility layer breaks every one of them at once.

## Contract differences to close

| Aspect | Legacy PHP API | Current `backend/` | Decision needed |
|---|---|---|---|
| Login | `POST /api/v1/auth` body `{login, password, twofa_pin?}` | `POST /api/v1/auth/login` body `{username, auth_key}` | Keep `/auth` as an alias; body is `{login, password, twofa_pin}` (Plan 36) |
| Logout | `DELETE /api/v1/logout` | `POST /api/v1/auth/logout` | Serve both |
| Envelope | `{statusCode, data, ...}`; login key at `data.key` | Bare model, key at `auth_key` | Legacy paths keep the envelope; new native paths may return bare models, decided in D-05 |
| Auth header | `X-Auth-Key` | `Authorization: Bearer` (D-26) | Shim accepts `X-Auth-Key` only |
| Cookie | `Token` for proxied apps | `cs_session` (HttpOnly, Secure, SameSite=Lax) | Shim accepts `Token` only; the auth gate uses `cs_session` (Plan 36) |
| IDs | Integers | UUIDs | Native paths use UUIDs; legacy paths accept integer IDs through `legacy_id` (Plan 32, Plan 41) |
| Resource names | Singular (`/device`, `/device-interface`) | Plural in plans (`/devices`) | Plural natively, singular through the shim |
| Errors | Slim error payloads | FastAPI `{detail}` | Shim maps errors to the legacy shape |
| Permissions | Route-regex rule keys | Per-endpoint dependency | [permission-mapping.md](permission-mapping.md) |

## Endpoints that reach a device

These are the legacy endpoints that trigger live SNMP or console traffic. They are the reason [safety-and-verification-policy.md](safety-and-verification-policy.md) exists: **they are never called during development, review, or CI**. The shim must keep them behind the same permission, scope and rate limit as the native versions.

- `GET /device/{id}/compare-model`
- `GET /device-interface/by-device/{id}` and any endpoint taking `from=live`
- `PUT /poller/poll/{device-id}` and `PUT /poller/poll-background/{device-id}`
- Any endpoint that runs a switcher-core module (see `src/Api/Actions/SwitcherCore`)
- Every `OltsControl`, `SwitchesControl`, `Macros` execute, `Console`, `Diagnostic` and `OntsRegistration` action route
- `SearchDevice` MAC/IP lookups (bounded in Plan 13, but still live)

## Core routes (`app/routes.php`)

| Legacy group | Routes | Target | Plan |
|---|---|---|---|
| `POST /public/auth`, `POST /public/app-auth`, `DELETE /logout` | 3 | `/api/v1/auth/*` plus legacy aliases | 3, 36 |
| `/public/defaults`, `/public/translations`, `/public/new-version` | 4 | `/api/v1/public/*` (unauthenticated, minimal) | 24, 41 |
| `/universal` | 1 | Decide in D-08 (what calls it?) | 41 |
| `/portal` (global search) | 1 | `/api/v1/portal/search` | 23 |
| `/dashboard/*` (template, widgets, latest actions) | 9 | `/api/v1/dashboards/*` with aggregation | 23 |
| `/dev-dashboard/*` | 2 | `/api/v1/dashboards/devices/*` | 23 |
| `/maps/*` | 4 | `/api/v1/maps/*` | 28 |
| `/user/*`, `/user-role/*` | 13 | `/api/v1/users`, `/api/v1/roles`, `/api/v1/access/*` | 4, 36 |
| `/poller/*` | 4 | `/api/v1/pollers/*` | 11 |
| `/device-access/*` | 6 | `/api/v1/device-access-profiles` (secrets write-only) | 2, 9, 36 |
| `/device-group/*` | 5 | `/api/v1/device-groups` | 9 |
| `/device-model/*` | 6 | `/api/v1/device-models` | 8 |
| `/device/*` (+ `/device/options`, `/device-detect`) | 8 | `/api/v1/devices` | 9 |
| `/device-interface/*` | 13 | `/api/v1/interfaces` | 10 |
| `/interface-marks/*` | 6 | `/api/v1/interfaces/marks/*` | 10 |
| `/system/*` (cross-auth, usage-stat, info, configuration, settings, schedule, components, webhook/alertmanager) | 11 | Split: 36 (cross-auth), 37 (schedule), 20 (webhook), 40 (info/usage), system_settings API | 20, 36, 37, 40 |
| `/logs/*` (actions, switcher-core, poller, schedule reports) | 9 | `/api/v1/logs/*` | 20, 37 |

## Component routes (`components/*/config.php`)

Component routes mount at `/api/v1/component/<name>/...` in the legacy app. The pattern column is relative to that mount.

| Component | Method | Legacy pattern | Target family | Plan |
|---|---|---|---|---|
| Analytics | GET | `/component/analytics/parameters/device-list` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET | `/component/analytics/parameters/device-groups` | `/api/v1/analytics` | 30, 23 |
| Analytics | POST | `/component/analytics/charts/ont-statuses` | `/api/v1/analytics` | 30, 23 |
| Analytics | POST | `/component/analytics/charts/device-statuses` | `/api/v1/analytics` | 30, 23 |
| Analytics | POST | `/component/analytics/charts/increasing-errors` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET | `/component/analytics/table/optical-drift` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/increasing-errors` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/ont-statuses-history` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/signal-strength` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/device-statuses` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/duplicated-mac-addresses` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/duplicated-onts` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT | `/component/analytics/table/ont-list` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,PUT,POST | `/component/analytics/bars/ont-levels` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET,POST | `/component/analytics/errors/increasing-chart/by-interface/{interface_id}` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET | `/component/analytics/errors/increasing-data/by-interface/{interface_id}` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET | `/component/analytics/errors/increasing-data/by-device/{device_id}` | `/api/v1/analytics` | 30, 23 |
| Analytics | GET | `/component/analytics/widgets/bad-signals` | `/api/v1/analytics` | 30, 23 |
| Attachments | GET | `/component/attachments/list/{type}/{id}` | `/api/v1/attachments` | 39 |
| Attachments | POST | `/component/attachments/upload/{type}/{id}` | `/api/v1/attachments` | 39 |
| Attachments | GET | `/component/attachments/meta/{uuid}` | `/api/v1/attachments` | 39 |
| Attachments | GET | `/component/attachments/object/{uuid}` | `/api/v1/attachments` | 39 |
| Attachments | GET | `/component/attachments/thumb/{uuid}` | `/api/v1/attachments` | 39 |
| Attachments | DELETE | `/component/attachments/object/{uuid}` | `/api/v1/attachments` | 39 |
| AutoDiscovery | GET | `/component/autodiscovery/all` | `/api/v1/discovery` | 9 |
| AutoDiscovery | PUT | `/component/autodiscovery/all` | `/api/v1/discovery` | 9 |
| AutoTopology | POST | `/component/auto_topology/update` | `/api/v1/topology/auto` | 27 |
| AutoTopology | GET | `/component/auto_topology/devices-list` | `/api/v1/topology/auto` | 27 |
| Console | GET | `/component/console/configuration` | `/api/v1/console` | 38 |
| Console | POST | `/component/console/logs` | `/api/v1/console` | 38 |
| Console | GET | `/component/console/logs/{id}` | `/api/v1/console` | 38 |
| Diagnostic | POST | `/component/diagnostic/arp-ping` | `/api/v1/diagnostics` | 38 |
| Diagnostic | GET | `/component/diagnostic/interface/{iface_id}/diag` | `/api/v1/diagnostics` | 38 |
| Diagnostic | GET | `/component/diagnostic/interface/{iface_id}/diag/html` | `/api/v1/diagnostics` | 38 |
| Diagnostic | GET | `/component/diagnostic/interface/{iface_id}/links` | `/api/v1/diagnostics` | 38 |
| Events | GET | `/component/events/params/names` | `/api/v1/events` | 20 |
| Events | GET,POST | `/component/events` | `/api/v1/events` | 20 |
| Events | PUT | `/component/events/{id}/resolve` | `/api/v1/events` | 20 |
| Events | PUT | `/component/events/resolve-all` | `/api/v1/events` | 20 |
| Events | GET,POST | `/component/events/incidents` | `/api/v1/events` | 20 |
| Events | GET | `/component/events/severity-stat` | `/api/v1/events` | 20 |
| Events | GET | `/component/events/count-by-name` | `/api/v1/events` | 20 |
| Events | GET | `/component/events/alertmanager` | `/api/v1/events` | 20 |
| Events | PUT | `/component/events/alertmanager` | `/api/v1/events` | 20 |
| Events | PUT | `/component/events/alertmanager/validate-expression` | `/api/v1/events` | 20 |
| Events | PUT | `/component/events/alertmanager/validate-rule` | `/api/v1/events` | 20 |
| FdbHistory | GET | `/component/fdb_history/{device}[/{interface}]` | `/api/v1/fdb-history` | 13, 18 |
| FdbHistory | POST | `/component/fdb_history/filter` | `/api/v1/fdb-history` | 13, 18 |
| Links | GET,PUT | `/component/links/view/list` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/view/tree` | `/api/v1/links` | 27 |
| Links | GET | `/component/links` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/topology-tree` | `/api/v1/links` | 27 |
| Links | PUT | `/component/links/external-neighbor-name` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/{id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/upward/{device_id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/lldp-neighbors/{device_id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/by-device/{device_id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/by-device/{device_id}/{interface_id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/by-interface/{interface_id}` | `/api/v1/links` | 27 |
| Links | POST | `/component/links` | `/api/v1/links` | 27 |
| Links | PUT | `/component/links/{id}` | `/api/v1/links` | 27 |
| Links | DELETE | `/component/links/{id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/options/devices` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/options/interfaces/{iface_id}` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/options/configuration` | `/api/v1/links` | 27 |
| Links | GET | `/component/links/widgets/high-utilization` | `/api/v1/links` | 27 |
| LiveTraffic | GET | `/component/live_traffic/view` | `/api/v1/live-traffic` | 31 |
| Macros | GET | `/component/macros/control` | `/api/v1/macros` | 38 |
| Macros | POST | `/component/macros/control/preview` | `/api/v1/macros` | 38 |
| Macros | POST | `/component/macros/control/variables` | `/api/v1/macros` | 38 |
| Macros | GET | `/component/macros/control/{id}` | `/api/v1/macros` | 38 |
| Macros | PUT | `/component/macros/control/{id}` | `/api/v1/macros` | 38 |
| Macros | PUT | `/component/macros/control/clone/{id}` | `/api/v1/macros` | 38 |
| Macros | POST | `/component/macros/control` | `/api/v1/macros` | 38 |
| Macros | DELETE | `/component/macros/control/{id}` | `/api/v1/macros` | 38 |
| Macros | POST | `/component/macros/execute` | `/api/v1/macros` | 38 |
| Macros | GET | `/component/macros/execute/progress/{id}` | `/api/v1/macros` | 38 |
| Macros | POST | `/component/macros/variables` | `/api/v1/macros` | 38 |
| Macros | GET | `/component/macros/macro/{id}` | `/api/v1/macros` | 38 |
| Macros | GET | `/component/macros/list` | `/api/v1/macros` | 38 |
| NoDenyPlus | GET | `/component/nodeny_plus/billing/render-diag-card` | `/api/v1/integrations/nodeny` | 39 |
| NoDenyPlus | GET | `/component/nodeny_plus/billing/diagnostic` | `/api/v1/integrations/nodeny` | 39 |
| Notifications | GET | `/component/notifications/contacts/by-user/{user}` | `/api/v1/notifications` | 29 |
| Notifications | PUT | `/component/notifications/contacts/by-user/{user}` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/config/channel/defaults/{channel}` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/config/channel/{channel}` | `/api/v1/notifications` | 29 |
| Notifications | PUT | `/component/notifications/config/channel/{channel}` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/configured-channels` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/config/actions` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/config/events` | `/api/v1/notifications` | 29 |
| Notifications | PUT | `/component/notifications/config/actions` | `/api/v1/notifications` | 29 |
| Notifications | PUT | `/component/notifications/config/events` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/config/names/events` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/config/names/actions` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/devices` | `/api/v1/notifications` | 29 |
| Notifications | GET | `/component/notifications/history/{by}/{id}` | `/api/v1/notifications` | 29 |
| Olts | GET | `/component/olts/tab-stats/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/resources/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/system/resources/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/system/supported-modules/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/cards/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/cards/status/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/interfaces/onts/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/interfaces/ont/{device}/{interface}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/interfaces/pon-ports/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/interfaces/physical/{device}` | `/api/v1/olts` | 14-17 |
| Olts | GET | `/component/olts/interfaces/parse/{device}/{interface}` | `/api/v1/olts` | 14-17 |
| OltsControl | PUT | `/component/olts_control/ont/dereg/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/ont/clear-pon/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/ont/reboot/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/ont/reset/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/olt/reset-port/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/olt/interface/description/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/ont/disable/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/ont/description/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OltsControl | PUT | `/component/olts_control/ont/uni-control/{device}/{interface}` | `/api/v1/olts/actions` | 38 |
| OntsRegistration | GET | `/component/onts_registration/control` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | POST | `/component/onts_registration/control/preview` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | POST | `/component/onts_registration/control/variables` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | GET | `/component/onts_registration/control/{id}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | PUT | `/component/onts_registration/control/{id}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | PUT | `/component/onts_registration/control/clone/{id}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | POST | `/component/onts_registration/control` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | DELETE | `/component/onts_registration/control/{id}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | POST | `/component/onts_registration/execute` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | GET | `/component/onts_registration/execute/progress/{id}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | POST | `/component/onts_registration/variables` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | GET | `/component/onts_registration/by-device/{device_id}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | GET | `/component/onts_registration/by-ident/{device_id}/{ident}` | `/api/v1/onus/registration` | 38 |
| OntsRegistration | GET | `/component/onts_registration/unregistered[/{device_id}]` | `/api/v1/onus/registration` | 38 |
| Oxidized | GET | `/component/oxidized/internal/devices-list` | `/api/v1/config-backups` | 39 |
| Oxidized | GET | `/component/oxidized/is-oxidized-supported/{device_id}` | `/api/v1/config-backups` | 39 |
| Oxidized | GET | `/component/oxidized/data/config/{device_id}` | `/api/v1/config-backups` | 39 |
| Oxidized | GET | `/component/oxidized/data/status/{device_id}` | `/api/v1/config-backups` | 39 |
| Oxidized | GET | `/component/oxidized/data/get-links/{device_id}` | `/api/v1/config-backups` | 39 |
| Paths | GET | `/component/paths/map` | `/api/v1/paths` | 27 |
| Paths | GET | `/component/paths/groups` | `/api/v1/paths` | 27 |
| Paths | GET | `/component/paths` | `/api/v1/paths` | 27 |
| Paths | POST | `/component/paths` | `/api/v1/paths` | 27 |
| Paths | GET | `/component/paths/{id}` | `/api/v1/paths` | 27 |
| Paths | PUT | `/component/paths/{id}` | `/api/v1/paths` | 27 |
| Paths | DELETE | `/component/paths/{id}` | `/api/v1/paths` | 27 |
| Paths | PUT | `/component/paths/{id}/segments` | `/api/v1/paths` | 27 |
| Pinger | GET | `/component/pinger/pinger` | `/api/v1/pinger` | 12 |
| Pinger | POST | `/component/pinger/pinger` | `/api/v1/pinger` | 12 |
| Pinger | GET | `/component/pinger/device-status-stat` | `/api/v1/pinger` | 12 |
| Pinger | GET | `/component/pinger/logs/{device_id}` | `/api/v1/pinger` | 12 |
| Pinger | GET | `/component/pinger/status/{device_id}` | `/api/v1/pinger` | 12 |
| Pinger | GET | `/component/pinger/statuses` | `/api/v1/pinger` | 12 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-optical-signals-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-optical-temp-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-optical-voltage-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-traffic-counter-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-errors-counter-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-cpu-load-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-memory-load-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-temperature-series` | `/api/v1/charts` | 31 |
| PrometheusWrapper | POST | `/component/prometheus_wrapper/chart-disk-load-series` | `/api/v1/charts` | 31 |
| QrGenerator | GET | `/component/qr-generator/qr-code-base64/{type}/{id}` | `/api/v1/qr` | 39 |
| RouterOS | GET | `/component/router_os/resources/{id}` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/resources` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/address-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/arp-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/dhcp-servers-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/bgp-sessions-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/leases-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/simple-queue-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/interface-vlans-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/device/{id}/interfaces-list` | `/api/v1/routers/routeros` | 19 |
| RouterOS | GET | `/component/router_os/tab-stats/{id}` | `/api/v1/routers/routeros` | 19 |
| Routers | GET | `/component/routers/tab-stats/{device}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/interfaces/{device}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/interfaces/parse/{device}/{interface}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/resources/{device}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/vlans/{device}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/arps/{device}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/direct-routes/{device}` | `/api/v1/routers` | 19 |
| Routers | GET | `/component/routers/fdb/{device}` | `/api/v1/routers` | 19 |
| SearchDevice | POST | `/component/search_device/search-mac` | `/api/v1/search` | 13, 18 |
| SearchDevice | POST | `/component/search_device/search-ip` | `/api/v1/search` | 13, 18 |
| SearchDevice | POST | `/component/search_device/search-ip-with-fdb` | `/api/v1/search` | 13, 18 |
| SensorDevices | GET | `/component/sensor_devices/tab-stats/{device_id}` | `/api/v1/sensors` | 39 |
| SensorDevices | GET | `/component/sensor_devices/{device_id}/status` | `/api/v1/sensors` | 39 |
| SensorDevices | POST | `/component/sensor_devices/{device_id}/get-series/{type}/{id}` | `/api/v1/sensors` | 39 |
| SensorDevices | PUT | `/component/sensor_devices/{device_id}/control/{type}/{id}` | `/api/v1/sensors` | 39 |
| SensorDevices | PUT | `/component/sensor_devices/{device_id}/toggle/{type}/{id}` | `/api/v1/sensors` | 39 |
| SensorDevices | PUT | `/component/sensor_devices/{device_id}/config/{type}/{id}` | `/api/v1/sensors` | 39 |
| SensorDevices | PUT | `/component/sensor_devices/{device_id}/disable-module/{type}` | `/api/v1/sensors` | 39 |
| SensorDevices | GET | `/component/sensor_devices/{device_id}/disable-modules` | `/api/v1/sensors` | 39 |
| Switches | GET | `/component/switches/tab-stats/{device}` | `/api/v1/switches` | 13, 18 |
| Switches | GET | `/component/switches/interfaces/{device}` | `/api/v1/switches` | 13, 18 |
| Switches | GET | `/component/switches/interfaces/{device}/cable_diag` | `/api/v1/switches` | 13, 18 |
| Switches | GET | `/component/switches/interfaces/{device}/sfp_diag` | `/api/v1/switches` | 13, 18 |
| Switches | GET | `/component/switches/interfaces/parse/{device}/{interface}` | `/api/v1/switches` | 13, 18 |
| Switches | GET | `/component/switches/resources/{device}` | `/api/v1/switches` | 13, 18 |
| Switches | GET | `/component/switches/vlans/{device}` | `/api/v1/switches` | 13, 18 |
| Switches | PUT | `/component/switches/system/{device}/reboot` | `/api/v1/switches` | 13, 18 |
| Switches | PUT | `/component/switches/system/{device}/save_config` | `/api/v1/switches` | 13, 18 |
| Switches | PUT | `/component/switches/system/{device}/clear_counters` | `/api/v1/switches` | 13, 18 |
| Switches | PUT | `/component/switches/interface/{device}/{interface}` | `/api/v1/switches` | 13, 18 |
| SwitchesControl | PUT | `/component/switches_control/system/{device}/reboot` | `/api/v1/switches/actions` | 38 |
| SwitchesControl | PUT | `/component/switches_control/system/{device}/save_config` | `/api/v1/switches/actions` | 38 |
| SwitchesControl | PUT | `/component/switches_control/system/{device}/clear_counters` | `/api/v1/switches/actions` | 38 |
| SwitchesControl | PUT | `/component/switches_control/system/{device}/{interface}/clear_counters` | `/api/v1/switches/actions` | 38 |
| SwitchesControl | PUT | `/component/switches_control/interface/{device}/{interface}` | `/api/v1/switches/actions` | 38 |
| TrapService | GET | `/component/trapservice/history/params/object-names` | `/api/v1/traps` | 21 |
| TrapService | GET | `/component/trapservice/history/stat/by-objects` | `/api/v1/traps` | 21 |
| TrapService | GET | `/component/trapservice/history/stat/by-device` | `/api/v1/traps` | 21 |
| TrapService | GET,POST | `/component/trapservice/history` | `/api/v1/traps` | 21 |

## Known external consumers

A read-only integration outside this repository calls these legacy paths with a long-lived `X-Auth-Key` token from `generate-auth-key`. They must keep working, with the same JSON shape, until that consumer is migrated:

- `GET /device`, `GET /device/{id}`
- `GET /component/olts/interfaces/pon-ports/{device}`
- `GET /component/olts/interfaces/onts/{device}`
- `GET /component/olts/cards/{device}` and `/component/olts/cards/status/{device}`
- `GET /device-interface/search?ont_ident=`

The consumer only reads cached data (`from=cache`) and never issues actions. The shim must preserve both the `from` parameter and the cache-first behavior, and the service token must survive: see Plan 36 (service tokens do not expire on the 24-hour session schedule).

## Compatibility rules

1. The shim is generated from this table and tested by **contract tests**: recorded legacy responses replayed against the shim, compared field by field. No live device is involved.

2. Every shim route logs its use with the caller identity. The deprecation date for a route is set only after 30 days of zero use.

3. The shim adds no permission the native route lacks. It never widens scope.

4. New features are native-only. Nothing new is added to the legacy surface.
