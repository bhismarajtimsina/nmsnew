// Human-readable labels/descriptions for GET /system/configuration parameters.
// The backend only returns machine keys (param_name) — the live app pairs
// them with a static i18n dictionary that this mirrors. Any param not listed
// here (or any group not in groupTitles) falls back to an auto-formatted
// version of its key, same as the original does for its own gaps (its
// "paths" group visibly falls back to raw `sys_config.groups.paths`-style
// strings for exactly this reason).
export const groupTitles: Record<string, string> = {
  web_panel: 'Web panel',
  system: 'System',
  security: 'Security',
  switcher_core: 'Working with devices',
  analytics: 'Analytics',
  auto_topology: 'Auto topology',
  console: 'Component: Console',
  links: 'Links',
  notifications: 'Notifications',
  oxidized: 'Oxidized (Configuration backups)',
  search_device: 'Search devices',
  trapservice: 'SNMP traps',
};

export const paramMeta: Record<string, { label: string; description?: string }> = {
  DEFAULT_LANGUAGE: { label: 'Default language', description: 'Default language for new users' },
  APP_NAME: { label: 'Application name' },
  EXTERNAL_HTTP_ADDRESS: { label: 'External web address' },
  LOGS_RETURN_RESULTS_LIMIT: { label: 'Limit returning logs', description: 'Max lines returned in logs' },
  MAP_COORDINATES: { label: 'Default map coordinates', description: 'Default coordinates for map. Default - Kathmandu, Nepal' },
  MAP_TILE_LAYER: { label: 'Map tile layer', description: 'Choose map source' },
  WEB_ZOOM_MAIN: { label: 'Zoom web interface', description: 'Default value - 1.0. !Experimental, having some bugs' },
  WEB_ZOOM_IFRAME: { label: 'Zoom web interface (iframe)', description: 'Default value - 1.0. !Experimental, having some bugs' },
  SEARCH2_FILTER: { label: 'Extra Search by', description: 'Extra search by specified entity' },
  SEARCH2_ENABLED: { label: 'Extra Search status', description: 'Will be showed extra search in the header' },

  PROMETHEUS_RETENTION_TIME: { label: 'Prometheus retention time', description: 'Retention time for prometheus history' },
  LOG_LEVEL: { label: 'Log level' },
  RR_NUM_WORKERS: { label: 'Count procs', description: 'Count of threads for process API requests' },
  POLLER_IGNORE_DOWN: { label: 'Poller ignore DOWN hosts', description: 'Do not poll the device if it does not respond via ICMP' },
  POLLER_COUNT_PROCS: { label: 'Poller count procs', description: 'Number of background pollers (how many devices to poll at the same time)' },
  POLLER_DO_NOT_CLEAR_INTERFACES: {
    label: 'Do not clear interfaces',
    description: 'Not clear interfaces automatically. If option enabled - poller not remove old interfaces',
  },
  PROXY_ENABLED: { label: 'Proxy enabled', description: 'If support proxied over proxy, enable this flag' },
  PROXY_REAL_IP_HEADER: { label: 'Real IP header', description: 'Header name with real IP of user' },

  API_KEY_EXPIRATION: { label: 'API key expiration', description: 'Api key expiration in seconds. Default - 864000 (10 days)' },
  RATE_LIMITER_ENABLED: { label: 'Brute force protection', description: 'Enable brute force protection with IP blocking' },
  RATE_LIMITER_TIME: { label: 'Blocking by IP timeout', description: 'Time spent on the IP blacklist in seconds' },
  RATE_LIMITER_ATTEMPTS: {
    label: 'Incorrect login/password attempts',
    description: 'Number of unsuccessful attempts to send an IP to the blacklist (per 10 minutes)',
  },
  SECURE_CHECK_PASSWORD_STRENGTH: {
    label: 'Check password strength',
    description: 'Check minimal password strength. Minimal 8 symbols with - uppercase, lowercase, number and special symbols',
  },
  TRUSTED_HOST_NETWORK_LIST: {
    label: 'Trusted networks list',
    description:
      'Network list presented as CIDR of trusted networks. API calling in trusted networks allow send request without header X-Auth-Key(with setting default user System)',
  },

  SWC_CHECK_ICMP_PING: { label: 'Check ICMP status', description: "Check device online status(from ICMP) before start calling SNMP/Telnet" },
  SWC_ENABLE_SPLITTING: {
    label: 'Split response by interface',
    description:
      "When device called and returning about many interfaces, response will be read by as unique interface. It's allow to cache some modules",
  },
  SWC_CONSOLE_CONN_TYPE: { label: 'Connection type', description: 'Type of device console connection' },
  SWC_CONSOLE_PORT: { label: 'Console port' },
  SWC_CONSOLE_TIMEOUT_SEC: { label: 'Console timeout', description: 'Max session time in seconds' },
  SWC_CONSOLE_WAIT_BYTE_SEC: { label: 'Wait stream data timeout', description: 'Max seconds for waiting bit of data, when command executed' },
  SWC_SNMP_REPEATS: { label: 'SNMP repeats' },
  SWC_SNMP_VERSION: { label: 'SNMP version' },
  SWC_SNMP_TIMEOUT_SEC: { label: 'SNMP timeout sec' },
  SWC_SNMP_PORT: { label: 'SNMP port' },
  SWC_MIKROTIK_API_PORT: { label: 'Mikrotik API port' },
  SWC_CACHE_ACTUALIZE_TIMEOUT_SEC: {
    label: 'Actualize timeout',
    description: 'Actualize timeout in seconds. When cache is expired, cache update from device',
  },

  ANALYTICS_IGNORE_IFACES_WITH_MORE_THAN: { label: 'Ignore iface with more than' },
  ANALYTICS_SHOW_ACTIVE_MORE_THAN: { label: 'Show ifaces with active more than' },
  ANALYTICS_MIN_RX_SIGNAL: { label: 'Min ONU RX signal', description: 'Set minimal RX in dBm' },
  ANALYTICS_MAX_RX_SIGNAL: { label: 'Max ONU RX signal', description: 'Set maximum RX in dBm' },
  ANALYTICS_MIN_OLT_RX_SIGNAL: { label: 'Min OLT RX signal', description: 'Set minimal OLT RX in dBm' },
  ANALYTICS_MAX_OLT_RX_SIGNAL: { label: 'Max OLT RX signal', description: 'Set maximum OLT RX in dBm' },
  ANALYTICS_MIN_SFP_RX_SIGNAL: { label: 'Minimal RX signal on SFP', description: 'in dBm' },
  ANALYTICS_MAX_SFP_RX_SIGNAL: { label: 'Maximum RX signal on SFP', description: 'in dBm' },

  AUTO_TOPOLOGY_SCHEDULE_ENABLED: { label: 'Auto running enabled', description: 'Run rebuild topology over schedule in background' },
  AUTO_TOPOLOGY_THREADS: {
    label: 'Count of threads',
    description: 'Count of parallel processes. Must be less then RR_NUM_WORKERS (less 50%)',
  },

  CONSOLE_ENABLE_WEB: { label: 'Enable web' },
  CONSOLE_OPEN_AT: { label: 'Open console in' },
  CONSOLE_FONT_SIZE: { label: 'Font size' },

  LINKS_UTILIZATION_CALCULATE_PERIOD: {
    label: 'Utilization calculate period',
    description: 'A period in duration format (60s, 1m, 5m, 1h) for calculating link utilization',
  },
  LINKS_UTILIZATION_MAX_PRC_FOR_ALERT: {
    label: 'Percent of utilization for alert',
    description: 'Port utilization in percentage, above which an alert will be triggered',
  },

  NOTIFICATIONS_CHECK_PREVIOUS_MESSAGE: {
    label: 'Check previous notification',
    description: 'When event resolved, system can check previous notifications and ignore sending resolved message',
  },

  OXIDIZED_URL: { label: 'Oxidized URL', description: 'Url to oxidize instance. Do not change it, if you use build in' },
  OXIDIZED_THREADS: {
    label: 'Threads number',
    description: 'Max threads for working with devices. Recommended - threads = count CPU cores',
  },
  OXIDIZED_INTERVAL: { label: 'Backup interval', description: 'Interval to backup in seconds' },
  OXIDIZED_TIMEOUT: { label: 'Timeout', description: 'Timeout for working with device in seconds' },

  SEARCH_DEVICE_SOURCES: { label: 'Search sources' },
  SEARCH_DEVICE_CHECK_ALL_SOURCES: { label: 'Check all sources' },
  SEARCH_DEVICE_HISTORY_ONLY_ACTIVE: { label: 'Get only active' },
  SEARCH_DEVICE_IGNORE_TAG_PORTS: { label: 'Ignore tag ports' },
  SEARCH_DEVICE_REQUEST_CONCURRENCY: { label: 'Search concurrency' },

  TRAP_SERVICE_ENABLED: { label: 'SNMP traps enabled' },
  TRAP_SERVICE_SNMP_PORT: { label: 'SNMP traps port', description: 'Default - 162' },
  TRAP_SERVICE_COUNT_HANDLERS: { label: 'Count handlers', description: 'Count workers (recommended x2 per core)' },
  TRAP_SERVICE_IGNORE_UNKNOWN_TRAPS: {
    label: 'Ignore unknown traps',
    description: 'Default - disabled. When option is enabled, system will save trap without parsing(as raw data)',
  },
  TRAP_SERVICE_CHECK_COMMUNITY: {
    label: 'Check trap community',
    description: "When checking enabled, trap without correct community can't be saved",
  },
};

// Same graceful fallback the original itself uses when a key/group has no
// translation: format the raw key into something readable rather than
// showing nothing.
export function paramLabel(name: string): string {
  if (paramMeta[name]) return paramMeta[name].label;
  return name
    .toLowerCase()
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}
export function paramDescription(name: string): string {
  return paramMeta[name]?.description || '';
}
export function groupTitle(key: string): string {
  return groupTitles[key] || key;
}
