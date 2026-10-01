/**
 * Real route map for the WCA (Support API) panel, reconstructed from the
 * live app's own Vue Router (extracted via its rendered <a-menu> links) and
 * cross-checked against the OpenAPI spec. Every route currently points at
 * PlaceholderPage.vue — swap `component` for a real view as each page gets
 * built. `meta.apiPaths` is a development aid only (shown on the
 * placeholder), not used by the app itself.
 */
import { authBackend } from '@/auth/session';

const PlaceholderPage = () => import('@/views/PlaceholderPage.vue');

export interface WcaRoute {
  path: string;
  name: string;
  title: string;
  apiPaths?: string[];
  component?: () => Promise<any>;
  props?: Record<string, any>;
}

const wcaRouteDefs: WcaRoute[] = [
  // Dashboard
  {
    path: '',
    name: 'dashboard',
    title: 'Dashboard',
    apiPaths: [
      'GET /dashboard/widget/system-stat',
      'GET /dashboard/widget/ont-statuses',
      'GET /dashboard/widget/error-calling-by-device',
      'GET /dashboard/latest-system-actions',
      'GET /component/events/severity-stat',
    ],
    component: () => import('@/views/DashboardPage.vue'),
  },

  // Devices
  {
    path: 'devices/list',
    name: 'devices-list',
    title: 'Devices',
    apiPaths: ['GET /dev-dashboard/devices', 'GET /dev-dashboard/groups', 'GET /dev-dashboard/models'],
    component: () => import('@/views/devices/DevicesListPage.vue'),
  },
  {
    path: 'devices/:id',
    name: 'device-detail',
    title: 'Device',
    apiPaths: [
      'GET /device/{id}',
      'GET /switcher-core/device/system/{id}',
      'GET /component/switches/resources/{id}',
      'GET /device/{id}/compare-model',
      'GET /device-interface/by-device/{id}',
      'POST /component/events',
      'PUT /component/events/{id}/resolve',
      'GET /component/links/view/tree',
      'GET /component/links/by-device/{id}',
      'DELETE /component/links/{id}',
      'GET /component/qr-generator/qr-code-base64/device/{id}',
      'GET /component/oxidized/data/status/{id}',
      'GET /component/oxidized/data/config/{id}',
    ],
    // The new login gets the read-only page on the new API (Plan 25); the legacy build keeps the full legacy page.
    component: () =>
      authBackend() === 'cybersathy' ? import('@/views/devices/DeviceDetailNewPage.vue') : import('@/views/devices/DeviceDetailPage.vue'),
  },
  {
    path: 'devices/:id/interfaces/:interface',
    name: 'device-interface-detail',
    title: 'Interface',
    apiPaths: [
      'GET /component/olts/interfaces/ont/{device}/{interface}',
      'GET /component/olts/interfaces/physical/{device}',
      'GET /device/{id}',
      'GET /switcher-core/device/system/{id}',
      'GET /device-interface/by-device/{id}',
      'GET/PUT /interface-marks/favorite/{id}',
      'PUT /component/olts_control/ont/description|reboot|reset|dereg|disable|uni-control/{device}/{interface}',
      'PUT /component/olts_control/olt/interface/description/{device}/{interface}',
      'PUT /component/olts_control/olt/reset-port/{device}/{interface}',
      'GET /component/qr-generator/qr-code-base64/interface/{id}',
      'PUT /device-interface/{id}',
      'GET /device-interface/history/{id}',
      'GET /interface-marks/marks/{id}',
      'GET /interface-marks/existed-tags',
      'PUT /interface-marks/tags/{id}',
      'POST /component/events',
    ],
    component: () => import('@/views/devices/InterfaceDetailPage.vue'),
  },
  {
    path: 'management/device',
    name: 'device-management',
    title: 'Device management',
    apiPaths: ['GET/DELETE /device', 'GET /device-group'],
    component: () => import('@/views/devices/DeviceManagementListPage.vue'),
  },
  {
    path: 'management/device/new',
    name: 'device-management-create',
    title: 'Add new device',
    apiPaths: ['POST /device', 'GET /device-access', 'GET /device-model', 'GET /device-group'],
    component: () => import('@/views/devices/DeviceFormPage.vue'),
  },
  {
    path: 'management/device/:id',
    name: 'device-management-edit',
    title: 'Edit device',
    apiPaths: ['GET/PUT/DELETE /device/{id}', 'GET /device-access', 'GET /device-model', 'GET /device-group'],
    component: () => import('@/views/devices/DeviceFormPage.vue'),
  },
  {
    path: 'management/device-access',
    name: 'device-access',
    title: 'Access management',
    apiPaths: ['GET/POST/PUT/DELETE /device-access'],
    // The new login gets the SNMP-only profiles page with write-only secrets (Plan 25); legacy keeps its own page.
    component: () =>
      authBackend() === 'cybersathy' ? import('@/views/devices/DeviceAccessNewPage.vue') : import('@/views/devices/DeviceAccessPage.vue'),
  },
  {
    path: 'management/device-group',
    name: 'device-group',
    title: 'Device groups',
    apiPaths: ['GET/POST/PUT/DELETE /device-group'],
    component: () => import('@/views/devices/DeviceGroupsPage.vue'),
  },
  {
    path: 'management/device-model',
    name: 'device-model',
    title: 'Device models',
    apiPaths: ['GET /device-model'],
    component: () => import('@/views/devices/DeviceModelsPage.vue'),
  },
  {
    path: 'management/device-model/:id',
    name: 'device-model-edit',
    title: 'Edit model',
    apiPaths: ['GET/PUT /device-model/{id}'],
    component: () => import('@/views/devices/DeviceModelFormPage.vue'),
  },

  // Interfaces
  {
    path: 'interfaces/ont-list',
    name: 'ont-list',
    title: 'ONT list',
    apiPaths: ['PUT /component/analytics/table/ont-list', 'GET /device/options'],
    component: () => import('@/views/interfaces/OntListPage.vue'),
  },
  {
    path: 'interfaces/favorite',
    name: 'favorite-interfaces',
    title: 'Favorite interfaces',
    apiPaths: ['PUT /interface-marks/favorite/list', 'PUT /interface-marks/favorite/{id}'],
    component: () => import('@/views/interfaces/FavoriteInterfacesPage.vue'),
  },
  {
    path: 'interfaces/tags',
    name: 'tagged-interfaces',
    title: 'Tagged interfaces',
    apiPaths: ['PUT /interface-marks/tagged/list', 'GET /interface-marks/existed-tags'],
    component: () => import('@/views/interfaces/TaggedInterfacesPage.vue'),
  },

  // Links & topology
  {
    path: 'links/list',
    name: 'links-list',
    title: 'Links',
    apiPaths: ['GET/PUT /component/links/view/list', 'POST/DELETE /component/links', 'GET /component/links/options/configuration'],
    component: () => import('@/views/links/LinksListPage.vue'),
  },
  {
    path: 'links/topology-graph',
    name: 'topology-graph',
    title: 'Topology (graph view)',
    apiPaths: ['GET /component/links/topology-tree'],
    component: () => import('@/views/links/TopologyGraphPage.vue'),
  },
  {
    path: 'links/topology-tree',
    name: 'topology-tree',
    title: 'Topology (tree view)',
    apiPaths: ['GET /component/links/view/tree'],
    component: () => import('@/views/links/TopologyTreePage.vue'),
  },

  // Geo
  {
    path: 'map',
    name: 'map',
    title: 'Map',
    apiPaths: ['PUT /maps/devices', 'PUT /maps/onts', 'PUT /maps/device-links', 'GET /maps/groups'],
    component: () => import('@/views/MapPage.vue'),
  },
  {
    path: 'nearby',
    name: 'nearby',
    title: 'Nearby objects',
    apiPaths: ['GET/POST /portal/nearest-elements'],
    component: () => import('@/views/NearbyObjectsPage.vue'),
  },

  // Events
  {
    path: 'logs/events',
    name: 'events',
    title: 'Events',
    apiPaths: ['GET/POST /component/events', 'PUT /component/events/{id}/resolve', 'PUT /component/events/resolve-all'],
    component: () => import('@/views/logs/EventsPage.vue'),
  },

  // Analytics
  {
    path: 'analytics/increasing-errors',
    name: 'analytics-increasing-errors',
    title: 'Increasing errors',
    apiPaths: ['POST /component/analytics/charts/increasing-errors', 'PUT /component/analytics/table/increasing-errors', 'GET /component/analytics/parameters/device-groups'],
    component: () => import('@/views/analytics/IncreasingErrorsPage.vue'),
  },
  {
    path: 'analytics/ont-statuses',
    name: 'analytics-ont-statuses',
    title: 'ONT statuses',
    apiPaths: ['POST /component/analytics/charts/ont-statuses', 'PUT /component/analytics/table/ont-statuses-history', 'GET /component/analytics/parameters/device-list'],
    component: () => import('@/views/analytics/OntStatusesAnalyticsPage.vue'),
  },
  {
    path: 'analytics/duplicated-mac-addresses',
    name: 'analytics-duplicated-mac',
    title: 'Duplicated MACs',
    apiPaths: ['PUT /component/analytics/table/duplicated-mac-addresses', 'GET /device-group'],
    component: () => import('@/views/analytics/DuplicatedMacPage.vue'),
  },
  {
    path: 'analytics/ont-level-strength',
    name: 'analytics-ont-level-strength',
    title: 'ONT signal strength',
    apiPaths: ['POST /component/analytics/bars/ont-levels', 'PUT /component/analytics/table/signal-strength', 'GET /component/analytics/parameters/device-list'],
    component: () => import('@/views/analytics/OntLevelStrengthPage.vue'),
  },
  {
    path: 'analytics/duplicated-onts',
    name: 'analytics-duplicated-onts',
    title: 'Duplicated ONTs',
    apiPaths: ['PUT /component/analytics/table/duplicated-onts', 'GET /device-group'],
    component: () => import('@/views/analytics/DuplicatedOntsPage.vue'),
  },
  {
    path: 'analytics/device-statuses',
    name: 'analytics-device-statuses',
    title: 'Device statuses',
    apiPaths: ['POST /component/analytics/charts/device-statuses', 'PUT /component/analytics/table/device-statuses'],
    component: () => import('@/views/analytics/DeviceStatusesAnalyticsPage.vue'),
  },

  // Logs
  {
    path: 'logs/console',
    name: 'logs-console',
    title: 'Console logs',
    apiPaths: ['POST /component/console/logs', 'GET /component/console/logs/{id}'],
    component: () => import('@/views/logs/ConsoleLogsPage.vue'),
  },
  {
    path: 'logs/actions',
    name: 'logs-actions',
    title: 'Actions logs',
    apiPaths: ['POST /logs/actions', 'GET /logs/actions-list', 'GET /user-list'],
    component: () => import('@/views/logs/ActionsLogsPage.vue'),
  },
  {
    path: 'logs/device-calling',
    name: 'logs-device-calling',
    title: 'SwitcherCore logs',
    apiPaths: ['POST /logs/switcher-core/actions', 'GET /logs/switcher-core/supported-modules'],
    component: () => import('@/views/logs/DeviceCallingLogsPage.vue'),
  },
  {
    path: 'logs/traps',
    name: 'logs-traps',
    title: 'SNMP traps',
    apiPaths: ['POST /component/trapservice/history', 'GET /component/trapservice/history/params/object-names'],
    component: () => import('@/views/logs/SnmpTrapsPage.vue'),
  },
  {
    path: 'logs/poller',
    name: 'logs-poller',
    title: 'Poller logs',
    apiPaths: ['POST /logs/poller/pollers', 'GET /logs/poller/existed-pollers'],
    component: () => import('@/views/logs/PollerLogsPage.vue'),
  },
  {
    path: 'logs/schedule-reports',
    name: 'logs-schedule-reports',
    title: 'Schedule reports',
    apiPaths: ['POST /logs/schedule/reports', 'GET /logs/schedule/keys'],
    component: () => import('@/views/logs/ScheduleReportsPage.vue'),
  },

  // User management
  {
    path: 'management/user',
    name: 'users',
    title: 'Users',
    apiPaths: ['GET/DELETE /user', 'GET /user/{id}'],
    // The new login gets users on the new API, edited in place (Plan 25); legacy keeps its list and form pages.
    component: () =>
      authBackend() === 'cybersathy' ? import('@/views/users/UsersNewPage.vue') : import('@/views/users/UsersListPage.vue'),
  },
  {
    path: 'management/user/new',
    name: 'users-create',
    title: 'Add new user',
    apiPaths: ['POST /user', 'GET /user-role', 'GET /device-group', 'GET /public/defaults'],
    // Under the new login users are edited in place on the list page, so the legacy form is never served.
    component: () =>
      authBackend() === 'cybersathy' ? import('@/views/users/UsersNewPage.vue') : import('@/views/users/UserFormPage.vue'),
  },
  {
    path: 'management/user/:id',
    name: 'users-edit',
    title: 'Edit user',
    apiPaths: [
      'GET/PUT/DELETE /user/{id}',
      'GET /user-role',
      'GET /device-group',
      'GET /public/defaults',
      'DELETE /user-session-close/{id}',
      'PUT /user/{id}/generate-auth-key',
    ],
    // Under the new login users are edited in place on the list page, so the legacy form is never served.
    component: () =>
      authBackend() === 'cybersathy' ? import('@/views/users/UsersNewPage.vue') : import('@/views/users/UserFormPage.vue'),
  },
  {
    path: 'management/user-role',
    name: 'user-roles',
    title: "User's roles",
    apiPaths: ['GET/DELETE /user-role'],
    component: () => import('@/views/users/RolesListPage.vue'),
  },
  {
    path: 'management/user-role/new',
    name: 'user-roles-create',
    title: 'Create new role',
    apiPaths: ['POST /user-role', 'GET /user-role-permissions'],
    component: () => import('@/views/users/RoleFormPage.vue'),
  },
  {
    path: 'management/user-role/:id',
    name: 'user-roles-edit',
    title: 'Edit role',
    apiPaths: ['GET/PUT/DELETE /user-role/{id}', 'GET /user-role-permissions'],
    component: () => import('@/views/users/RoleFormPage.vue'),
  },

  // Configuration
  {
    path: 'config/autodiscovery',
    name: 'autodiscovery',
    title: 'Autodiscovery',
    apiPaths: ['GET/PUT /component/autodiscovery/all', 'GET /device-access', 'GET /device-group'],
    component: () => import('@/views/config/AutodiscoveryPage.vue'),
  },
  {
    path: 'config/macros',
    name: 'macros',
    title: 'Macros',
    apiPaths: ['GET/POST/PUT/DELETE /component/macros/control', 'GET /device-model', 'GET /user-role'],
    component: () => import('@/views/config/MacrosListPage.vue'),
    props: { variant: 'macros' },
  },
  {
    path: 'config/macros/create',
    name: 'macros-create',
    title: 'Add New Macro',
    apiPaths: ['POST /component/macros/control', 'POST /component/macros/control/variables', 'POST /component/macros/control/preview'],
    component: () => import('@/views/config/MacroFormPage.vue'),
    props: { variant: 'macros' },
  },
  {
    path: 'config/macros/:id/edit',
    name: 'macros-edit',
    title: 'Edit Macro',
    apiPaths: [
      'GET/PUT/DELETE /component/macros/control/{id}',
      'POST /component/macros/control/variables',
      'POST /component/macros/control/preview',
    ],
    component: () => import('@/views/config/MacroFormPage.vue'),
    props: { variant: 'macros' },
  },
  {
    path: 'config/onts-registration',
    name: 'onts-registration',
    title: 'ONTs registration',
    apiPaths: ['GET/POST/PUT/DELETE /component/onts_registration/control', 'GET /device-model'],
    component: () => import('@/views/config/MacrosListPage.vue'),
    props: { variant: 'onts-registration' },
  },
  {
    path: 'config/onts-registration/create',
    name: 'onts-registration-create',
    title: 'Add macro',
    apiPaths: [
      'POST /component/onts_registration/control',
      'POST /component/onts_registration/control/variables',
      'POST /component/onts_registration/control/preview',
    ],
    component: () => import('@/views/config/MacroFormPage.vue'),
    props: { variant: 'onts-registration' },
  },
  {
    path: 'config/onts-registration/:id/edit',
    name: 'onts-registration-edit',
    title: 'Edit macro',
    apiPaths: [
      'GET/PUT/DELETE /component/onts_registration/control/{id}',
      'POST /component/onts_registration/control/variables',
      'POST /component/onts_registration/control/preview',
    ],
    component: () => import('@/views/config/MacroFormPage.vue'),
    props: { variant: 'onts-registration' },
  },
  {
    path: 'config/notifications',
    name: 'notifications-config',
    title: 'Notifications configuration',
    apiPaths: ['GET/PUT /component/notifications/config/channel/{telegram|email}'],
    component: () => import('@/views/config/NotificationsConfigPage.vue'),
  },
  {
    path: 'config/events/configuration',
    name: 'events-config',
    title: 'Event configuration',
    apiPaths: ['GET/PUT /component/events/alertmanager'],
    component: () => import('@/views/config/EventsConfigPage.vue'),
  },
  {
    path: 'config/system/configuration',
    name: 'system-config',
    title: 'System configuration',
    apiPaths: [
      'GET /system/configuration',
      'PUT /system/configuration',
      'GET /system/component',
      'PUT /system/component/{key}',
      'GET /system/schedule',
      'PUT /system/schedule/{id}',
    ],
    component: () => import('@/views/system/SystemConfigPage.vue'),
  },

  // QR printing
  {
    path: 'qr-printing/devices',
    name: 'qr-devices',
    title: 'Printing QR for devices',
    apiPaths: ['GET /device', 'GET /component/qr-generator/qr-code-base64/device/{id}'],
    component: () => import('@/views/qr/QrDevicesPage.vue'),
  },
  {
    path: 'qr-printing/interfaces',
    name: 'qr-interfaces',
    title: 'Printing QR for interfaces',
    apiPaths: ['GET /device/options', 'GET /device-interface', 'GET /component/qr-generator/qr-code-base64/interface/{id}'],
    component: () => import('@/views/qr/QrInterfacesPage.vue'),
  },

  // Own account — reachable from the header user menu, not the sidebar
  {
    path: 'account/settings',
    name: 'account-settings',
    title: 'My account',
    apiPaths: ['GET /user/self', 'PUT /user/self', 'GET /user/self/2fa', 'DELETE /user-session-close/{id}'],
    component: () => import('@/views/account/AccountSettings.vue'),
  },
];

const adminRoutes = wcaRouteDefs.map((r) => ({
  path: r.path,
  name: r.name,
  component: r.component || PlaceholderPage,
  props: r.props || false,
  meta: { title: r.title, apiPaths: r.apiPaths || [] },
}));

export { wcaRouteDefs };
export default adminRoutes;
