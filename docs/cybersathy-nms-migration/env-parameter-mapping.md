# Environment Parameter Mapping

The legacy `.env` carries 119 parameters. Each one needs a home in the new system, or an explicit reason it goes away. Names come from the root `.env` file. Values are never copied into documentation; this list contains names only.

Target homes: **env** (process environment, read at start), **settings** (`system_settings` table, editable in UI), **profile** (polling or access profile row), **secret** (encrypted or in the secret store), **dropped**.

## Database and cache

| Legacy parameter | Target | Note |
|---|---|---|
| `DATABASE_URL`, `DATABASE_NAME`, `DATABASE_USER`, `DATABASE_PASSWD` | env / secret → `POSTGRES_*` | Password moves to the secret store, never committed (Plan 35) |
| `MYSQL_EXPOSE`, `MYSQL_INNODB_BUFFER_POOL_SIZE`, `MYSQL_KEY_BUFFER_SIZE`, `MYSQL_TABLE_OPEN_CACHE` | dropped | MySQL is removed |
| `MEMCACHE_ENABLED`, `MEMCACHE_SERVER`, `MEMCACHE_PORT`, `MEMCACHE_CACHING_QUERY_TIMEOUT_SEC` | dropped | Redis is the only cache |
| `REDIS_ENABLED`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | env / secret | Redis gets a password in the new stack |

## Runtime and API

| Legacy parameter | Target | Note |
|---|---|---|
| `APP_NAME`, `ENVIRONMENT`, `LOG_LEVEL`, `LOG_FILES_PATH` | env | Logs go to stdout as JSON; file logging dropped |
| `API_BASE_PATH`, `IMAGE_BASE_URL`, `EXTERNAL_HTTP_ADDRESS` | env | |
| `API_KEY_EXPIRATION` | env `SESSION_TTL_HOURS` | Already present in `backend/` |
| `API_STRICT_RULES` | dropped | Permission enforcement is always on |
| `DEFAULT_LANGUAGE` | settings | |
| `LOGS_RETURN_RESULTS_LIMIT` | settings | Pagination limit |
| `RR_NUM_WORKERS`, `RR_MAX_WORKER_MEMORY` | dropped | RoadRunner removed. Replaced by Uvicorn worker count (env) |
| `PROXY_ENABLED`, `PROXY_REAL_IP_HEADER` | env | Becomes Uvicorn `--proxy-headers` and `--forwarded-allow-ips` (defect F-06) |
| `NGINX_EXPOSE`, `PROMETHEUS_EXPOSE`, `NETFLOW_EXPOSE`, `SFLOW_EXPOSE`, `LOCAL_SUBNET` | env (compose) | |

## Security

| Legacy parameter | Target | Note |
|---|---|---|
| `TRUSTED_HOST_NETWORK_LIST` | settings | Feeds the trusted-IP logic (Plan 36) |
| `RATE_LIMITER_ENABLED`, `RATE_LIMITER_TIME`, `RATE_LIMITER_ATTEMPTS` | settings | Login and action rate limits (Plan 36) |
| `SECURE_CHECK_PASSWORD_STRENGTH` | settings | |
| `SECURE_ENCRYPT_ACCESSES` | dropped | Encryption is always on |

## Device access and polling (`SWC_*`, `POLLER_*`)

| Legacy parameter | Target | Note |
|---|---|---|
| `SWC_SNMP_PORT`, `SWC_SNMP_VERSION`, `SWC_SNMP_TIMEOUT_SEC`, `SWC_SNMP_REPEATS` | profile | `device_access_profiles` defaults |
| `SWC_CONSOLE_CONN_TYPE`, `SWC_CONSOLE_PORT`, `SWC_CONSOLE_TIMEOUT_SEC`, `SWC_CONSOLE_WAIT_BYTE_SEC` | profile | Console gateway defaults (Plan 38) |
| `SWC_MIKROTIK_API_PORT` | profile | |
| `SWC_CALL_CONCURRENCY` | settings | Becomes the per-vendor concurrency limit (Plan 11) |
| `SWC_CACHE_SYSTEM`, `SWC_CACHE_ACTUALIZE_TIMEOUT_SEC`, `SWC_CACHE_TIMEOUT_SEC` | settings | Cache-first reads (Plan 12) |
| `SWC_URL_PATH` | dropped | |
| `SWC_IGNORE_ICMP_PING`, `SWC_CHECK_ICMP_PING`, `POLLER_IGNORE_DOWN` | settings | Skip polling for down devices |
| `SWC_ENABLE_SPLITTING` | settings | |
| `POLLER_COUNT_PROCS` | env | Worker concurrency |
| `POLLER_DO_NOT_CLEAR_INTERFACES` | settings | |
| `DEVICES_LIST_DISABLE_IFACE_STAT` | settings | |
| `LOG_ALL_EVENTS` | settings | |

## Traps

| Legacy parameter | Target |
|---|---|
| `TRAP_SERVICE_ENABLED`, `TRAP_SERVICE_SNMP_PORT`, `TRAP_SERVICE_COUNT_HANDLERS` | env |
| `TRAP_SERVICE_IGNORE_UNKNOWN_TRAPS`, `TRAP_SERVICE_CHECK_COMMUNITY` | settings |

## Analytics, links, paths, topology

| Legacy parameter | Target |
|---|---|
| `ANALYTICS_IGNORE_IFACES_WITH_MORE_THAN`, `ANALYTICS_SHOW_ACTIVE_MORE_THAN`, `ANALYTICS_MIN_RX_SIGNAL`, `ANALYTICS_MAX_RX_SIGNAL`, `ANALYTICS_MIN_OLT_RX_SIGNAL`, `ANALYTICS_MAX_OLT_RX_SIGNAL`, `ANALYTICS_MIN_SFP_RX_SIGNAL`, `ANALYTICS_MAX_SFP_RX_SIGNAL` | settings (thresholds also feed alarm rules, Plan 20) |
| `AUTO_TOPOLOGY_SCHEDULE_ENABLED`, `AUTO_TOPOLOGY_THREADS` | settings |
| `LINKS_UTILIZATION_CALCULATE_PERIOD`, `LINKS_UTILIZATION_MAX_PRC_FOR_ALERT` | settings |
| `PATHS_DEGRADED_LATENCY_MS`, `PATHS_STATE_METRIC_TTL_SEC` | settings |

## Search, console, notifications, maps, web

| Legacy parameter | Target |
|---|---|
| `SEARCH_DEVICE_SOURCES`, `SEARCH_DEVICE_CHECK_ALL_SOURCES`, `SEARCH_DEVICE_HISTORY_ONLY_ACTIVE`, `SEARCH_DEVICE_IGNORE_TAG_PORTS`, `SEARCH_DEVICE_REQUEST_CONCURRENCY` | settings. Concurrency is capped by Plan 13/18 bounds |
| `CONSOLE_ENABLE_WEB`, `CONSOLE_OPEN_AT`, `CONSOLE_FONT_SIZE` | settings |
| `NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE` | settings (dedup, Plan 29) |
| `MAP_COORDINATES`, `MAP_TILE_LAYER` | settings |
| `WEB_ZOOM_MAIN`, `WEB_ZOOM_IFRAME` | settings |

## Monitoring and external apps

| Legacy parameter | Target |
|---|---|
| `PROMETHEUS_URL`, `ALERTMANAGER_URL`, `PROMETHEUS_RETENTION_TIME` | env (`ALERTMANAGER_URL` is read by the `sync_active_alerts` job; empty by default) |
| `OXIDIZED_URL`, `OXIDIZED_THREADS`, `OXIDIZED_INTERVAL`, `OXIDIZED_TIMEOUT` | settings for the own `config-backup` worker (`URL` dropped, the rest become concurrency, interval and timeout) |

## Integrations (secrets)

| Legacy parameter | Target |
|---|---|
| `USERSIDE_WEB_ADDRESS` | settings |
| `USERSIDE_API_KEY` | **secret** |
| `USERSIDE_SYNC_DATA`, `USERSIDE_ADD_NEW_DEVICES_TO_GROUP_ID`, `USERSIDE_DELETE_NOT_EXISTED_DEVICES`, `USERSIDE_UPDATE_DEVICES_FIELDS` | settings. `USERSIDE_DELETE_NOT_EXISTED_DEVICES` is Dangerous and off by default |
| `MIKBILL_API_ADDR`, `MIKBILL_GLOBAL_SEARCH_FIELD`, `MIKBILL_SET_ONT_COORDINATES` | settings |
| `MIKBILL_AUTH_KEY` | **secret** |
| `NODENY_URL`, `NODENY_COMPARISON_DESCRIPTION_REQUIRED`, `NODENY_COMPARISON_CHECK_TOPOLOGY` | settings |
| `NODENY_DATABASE_DSN`, `NODENY_DATABASE_USERNAME`, `NODENY_DATABASE_PASSWORD` | **secret** |

## Rules

1. Every legacy parameter is either mapped above or added to the list with a reason before cutover. A script (`tools/env-parity-check.py`, Plan 33) diffs `.env` names against this file and fails on unmapped names.
2. Parameters marked **secret** are never stored in `system_settings` plaintext and never shown in the UI after entry.
3. Parameters that become **settings** get a validated schema (type, range, default) so a bad value cannot disable a safety bound.
