// GET /user-role-permissions returns each permission's own `key` /
// `description` / `logic_group`, but no human-readable title (or optional
// subtitle) for the *group* itself — that's only ever shown in the real
// app's own UI text, so it's hand-transcribed here from a screenshot of the
// real "Create new role" page. Any logic_group not listed falls back to its
// raw key, same graceful-degradation the real app itself shows for its own
// untranslated "permissions.groups.paths" group (kept below verbatim,
// including that literal fallback string, since that's genuinely what the
// real page displays for it).
export const groupTitles: Record<string, string> = {
  web_portal: 'Web portal',
  system: 'System',
  user_management: 'User management',
  device_management: 'Device management',
  external_apps: 'External apps',
  oxidized: 'Config backups (oxidized)',
  analytics: 'Analytics',
  console: 'Component: Console',
  diag: 'Component: Diagnostic',
  events: 'Component: Events',
  fdb_history: 'Component: FDB history',
  links: 'Component: Links',
  live_traffic: 'Live traffic',
  macros: 'Component: Macros',
  notifications: 'Component: Notifications',
  olts: 'OLTs',
  paths: 'permissions.groups.paths',
  prom_wrapper: 'Component: Prometheus',
  qr_generator: 'QR generator',
  router_os: 'Component: RouterOS',
  router: 'Router',
  sd: 'Component: Device searching',
  sensor_devices: 'Sensor devices',
  switches: 'Switches',
};

// Only these three groups show a subtitle under their heading in the real
// app; every other group's heading stands alone.
export const groupDescriptions: Record<string, string> = {
  web_portal: 'System information, Global search rules required for correctly working web portal',
  console: 'Configure permissions to connect devices over console (telnet/ssh). Autologin use credentials from support',
  prom_wrapper: 'Rules for display charts, as optical history, traffic on ONU or interface',
};

export function groupTitle(key: string): string {
  return groupTitles[key] || key;
}
