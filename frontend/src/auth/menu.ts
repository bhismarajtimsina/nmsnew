/**
 * Which sidebar entries a user sees (Plan 25).
 *
 * Each menu key lists the permissions that open its page; holding any one of them shows the entry. A submenu is shown
 * when at least one of its entries is. A key with no rule is hidden, so a page added to the menu without a rule here
 * stays out of sight instead of appearing to everyone (src/auth/menu.test.ts fails on such a key).
 *
 * This is convenience, not security: the API checks every permission itself. In the legacy build (the default) the
 * menu is not filtered at all and looks exactly as before; legacy enforces access on the server.
 */
/** `null`: the page has no counterpart in the new API and is hidden from everyone there. */
export const MENU_RULES: Readonly<Record<string, readonly string[] | null>> = {
  dashboard: ['portal.view'],
  'devices-list': ['devices.view'],
  'topology-graph': ['links.view'],
  'ont-list': ['onus.view', 'olts.view'],
  'favorite-interfaces': ['interfaces.view'],
  'tagged-interfaces': ['interfaces.view'],
  'links-list': ['links.view'],
  'topology-tree': ['links.view'],
  map: ['devices.view'],
  nearby: ['devices.view'],
  events: ['events.view'],
  'analytics-increasing-errors': ['analytics.view'],
  'analytics-ont-statuses': ['analytics.view'],
  'analytics-duplicated-mac': ['analytics.view'],
  'analytics-ont-level-strength': ['analytics.view'],
  'analytics-duplicated-onts': ['analytics.view'],
  'analytics-device-statuses': ['analytics.view'],
  'logs-console': ['console.logs.view'],
  'logs-actions': ['logs.actions.view'],
  'logs-device-calling': ['logs.device_calls.view'],
  'logs-traps': ['traps.view'],
  'logs-poller': ['logs.poller.view'],
  'logs-schedule-reports': ['system.schedule.reports.view'],
  'device-management': ['devices.manage'],
  'device-access': ['device_access.manage'],
  'device-group': ['device_groups.manage'],
  'device-model': ['device_models.view'],
  autodiscovery: ['devices.manage'],
  users: ['users.view', 'users.manage'],
  'user-roles': ['roles.view'],
  macros: ['macros.edit', 'macros.execute'],
  'onts-registration': [
    'onus.unregistered.view',
    'onus.registration.configure',
    'onus.registration.preview',
    'onus.registration.view_output',
  ],
  'notifications-config': ['notifications.admin', 'notifications.contacts.manage', 'notifications.contacts.self'],
  'events-config': ['events.configure'],
  'system-config': ['system.configure', 'system.schedule.manage'],
  oxidized: ['config_backups.view'],
  grafana: ['external_apps.grafana.view', 'external_apps.grafana.admin'],
  prometheus: ['external_apps.prometheus.view'],
  alertmanager: ['external_apps.alertmanager.view'],
  phpmyadmin: null, // leaves with MySQL (permission-mapping.md, D-12)
  'qr-devices': ['qr.view', 'qr.view_bulk'],
  'qr-interfaces': ['qr.view', 'qr.view_bulk'],
};

export const SUBMENUS: Readonly<Record<string, readonly string[]>> = {
  interfaces: ['ont-list', 'favorite-interfaces', 'tagged-interfaces'],
  links: ['links-list', 'topology-tree'],
  analytics: [
    'analytics-increasing-errors',
    'analytics-ont-statuses',
    'analytics-duplicated-mac',
    'analytics-ont-level-strength',
    'analytics-duplicated-onts',
    'analytics-device-statuses',
  ],
  logs: ['logs-console', 'logs-actions', 'logs-device-calling', 'logs-traps', 'logs-poller', 'logs-schedule-reports'],
  'device-mgmt': ['device-management', 'device-access', 'device-group', 'device-model', 'autodiscovery'],
  'user-mgmt': ['users', 'user-roles'],
  config: ['macros', 'onts-registration', 'notifications-config', 'events-config', 'system-config'],
  qr: ['qr-devices', 'qr-interfaces'],
};

/** A visibility test for menu keys and submenu keys. `filtered` is false in the legacy build, which sees everything. */
export function menuVisibility(filtered: boolean, can: (permission: string) => boolean): (key: string) => boolean {
  if (!filtered) return () => true;
  const leaf = (key: string): boolean => {
    const rule = MENU_RULES[key];
    return !!rule && rule.some(can);
  };
  return (key) => (key in SUBMENUS ? SUBMENUS[key].some(leaf) : leaf(key));
}
