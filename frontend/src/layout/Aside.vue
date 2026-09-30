<script setup lang="ts">
import { computed, reactive, ref, toRefs, watchEffect } from 'vue';
import { useStore } from 'vuex';
import { useRoute, useRouter } from 'vue-router';
import { NavTitle } from './style';

const props = defineProps({
  toggleCollapsed: {
    type: Function,
    required: true,
  },
  events: {
    type: Object,
    required: true,
  },
});

const store = useStore();
const darkMode = computed(() => store.state.themeLayout.data);
const mode = ref('inline');
const { events } = toRefs(props);
const { onRtlChange, onLtrChange, modeChangeDark, modeChangeLight, modeChangeTopNav, modeChangeSideNav } = events.value;
// unused-but-kept demo layout toggles — no UI hooks them up yet in the real menu
void onRtlChange;
void onLtrChange;
void modeChangeDark;
void modeChangeLight;
void modeChangeTopNav;
void modeChangeSideNav;

const route = useRoute();
const state = reactive({
  selectedKeys: [] as string[],
  openKeys: [] as string[],
});

// Maps each leaf route name to the submenu key it lives under, so opening
// a page from a deep link (not just clicking through the menu) still
// expands and highlights the right section.
const parentOf: Record<string, string> = {
  'device-management': 'device-mgmt',
  'device-management-create': 'device-mgmt',
  'device-management-edit': 'device-mgmt',
  'device-access': 'device-mgmt',
  'device-group': 'device-mgmt',
  'device-model': 'device-mgmt',
  'device-model-edit': 'device-mgmt',
  autodiscovery: 'device-mgmt',
  'ont-list': 'interfaces',
  'favorite-interfaces': 'interfaces',
  'tagged-interfaces': 'interfaces',
  'links-list': 'links',
  'topology-tree': 'links',
  'analytics-increasing-errors': 'analytics',
  'analytics-ont-statuses': 'analytics',
  'analytics-duplicated-mac': 'analytics',
  'analytics-ont-level-strength': 'analytics',
  'analytics-duplicated-onts': 'analytics',
  'analytics-device-statuses': 'analytics',
  'logs-console': 'logs',
  'logs-actions': 'logs',
  'logs-device-calling': 'logs',
  'logs-traps': 'logs',
  'logs-poller': 'logs',
  'logs-schedule-reports': 'logs',
  users: 'user-mgmt',
  'users-create': 'user-mgmt',
  'users-edit': 'user-mgmt',
  'user-roles': 'user-mgmt',
  'user-roles-create': 'user-mgmt',
  'user-roles-edit': 'user-mgmt',
  macros: 'config',
  'onts-registration': 'config',
  'notifications-config': 'config',
  'events-config': 'config',
  'system-config': 'config',
  'qr-devices': 'qr',
  'qr-interfaces': 'qr',
};

watchEffect(() => {
  const name = route.name as string;
  if (!name) return;
  state.selectedKeys = [name];
  const parent = parentOf[name];
  state.openKeys = parent ? [parent] : [];
});

const onOpenChange = (keys: string[]) => {
  state.openKeys = keys.length ? [keys[keys.length - 1]] : [];
};

// nginx-proxied external services — plain links, not Vue routes
const externalApps = [
  { key: 'oxidized', label: 'Oxidized', href: '/oxidized/', icon: 'save' },
  { key: 'grafana', label: 'Grafana', href: '/grafana/', icon: 'chart-line' },
  { key: 'prometheus', label: 'Prometheus', href: '/prometheus/', icon: 'fire' },
  { key: 'alertmanager', label: 'Alertmanager', href: '/alertmanager/', icon: 'bell' },
  { key: 'phpmyadmin', label: 'phpMyAdmin', href: '/phpmyadmin/', icon: 'database' },
];
const externalAppHrefs: Record<string, string> = Object.fromEntries(externalApps.map((a) => [a.key, a.href]));

const router = useRouter();

// Every leaf item used to wrap a <router-link> around only its text label,
// with the icon sitting outside that link inside the same <li>. On touch
// devices a tap that lands on the icon/padding (easy to do — that's most of
// the row) only hit the <li>'s own antd click handling, not the link, so it
// took two taps: one to "select" the row, a second landing on the link text
// to actually navigate. Handling navigation centrally off the item's own
// key removes the nested-anchor entirely, so any tap anywhere on the row
// navigates in one touch.
const onClick = ({ key }: { key: string }) => {
  props.toggleCollapsed();
  if (key in externalAppHrefs) {
    window.open(externalAppHrefs[key], '_blank', 'noopener');
    return;
  }
  router.push({ name: key });
};
</script>

<template>
  <a-menu
    :open-keys="state.openKeys"
    v-model:selectedKeys="state.selectedKeys"
    :mode="mode"
    :theme="darkMode ? 'dark' : 'light'"
    class="scroll-menu"
    @openChange="onOpenChange"
    @click="onClick"
  >
    <a-menu-item key="dashboard">
      <template #icon><unicon name="create-dashboard"></unicon></template>
      Dashboard
    </a-menu-item>

    <a-menu-item key="devices-list">
      <template #icon><unicon name="server-network"></unicon></template>
      Devices
    </a-menu-item>

    <a-menu-item key="topology-graph">
      <template #icon><unicon name="graph-bar"></unicon></template>
      Topology graph
    </a-menu-item>

    <a-sub-menu key="interfaces">
      <template #icon><unicon name="wifi"></unicon></template>
      <template #title>Interfaces</template>
      <a-menu-item key="ont-list">
        <template #icon><unicon name="signal-alt-3"></unicon></template>
        ONT list
      </a-menu-item>
      <a-menu-item key="favorite-interfaces">
        <template #icon><unicon name="star"></unicon></template>
        Favorite list
      </a-menu-item>
      <a-menu-item key="tagged-interfaces">
        <template #icon><unicon name="tag-alt"></unicon></template>
        Tags
      </a-menu-item>
    </a-sub-menu>

    <a-sub-menu key="links">
      <template #icon><unicon name="share-alt"></unicon></template>
      <template #title>Links</template>
      <a-menu-item key="links-list">
        <template #icon><unicon name="link-alt"></unicon></template>
        Links list
      </a-menu-item>
      <a-menu-item key="topology-tree">
        <template #icon><unicon name="sitemap"></unicon></template>
        Topology (tree view)
      </a-menu-item>
    </a-sub-menu>

    <a-menu-item key="map">
      <template #icon><unicon name="map"></unicon></template>
      Map
    </a-menu-item>

    <a-menu-item key="nearby">
      <template #icon><unicon name="location-point"></unicon></template>
      Nearby objects
    </a-menu-item>

    <a-menu-item key="events">
      <template #icon><unicon name="bell"></unicon></template>
      Events
    </a-menu-item>

    <a-sub-menu key="analytics">
      <template #icon><unicon name="chart-line"></unicon></template>
      <template #title>Analytics</template>
      <a-menu-item key="analytics-increasing-errors">
        <template #icon><unicon name="arrow-growth"></unicon></template>
        Increasing errors
      </a-menu-item>
      <a-menu-item key="analytics-ont-statuses">
        <template #icon><unicon name="signal-alt-3"></unicon></template>
        ONT statuses
      </a-menu-item>
      <a-menu-item key="analytics-duplicated-mac">
        <template #icon><unicon name="copy"></unicon></template>
        Duplicated MACs
      </a-menu-item>
      <a-menu-item key="analytics-ont-level-strength">
        <template #icon><unicon name="signal-alt"></unicon></template>
        Strength level ONTs
      </a-menu-item>
      <a-menu-item key="analytics-duplicated-onts">
        <template #icon><unicon name="copy-alt"></unicon></template>
        Duplicated ONTs
      </a-menu-item>
      <a-menu-item key="analytics-device-statuses">
        <template #icon><unicon name="server"></unicon></template>
        Device statuses
      </a-menu-item>
    </a-sub-menu>

    <a-sub-menu key="logs">
      <template #icon><unicon name="document-layout-left"></unicon></template>
      <template #title>Logs</template>
      <a-menu-item key="logs-console">
        <template #icon><unicon name="window-section"></unicon></template>
        Console logs
      </a-menu-item>
      <a-menu-item key="logs-actions">
        <template #icon><unicon name="history"></unicon></template>
        Actions
      </a-menu-item>
      <a-menu-item key="logs-device-calling">
        <template #icon><unicon name="exchange"></unicon></template>
        Device calling logs
      </a-menu-item>
      <a-menu-item key="logs-traps">
        <template #icon><unicon name="bell"></unicon></template>
        SNMP traps
      </a-menu-item>
      <a-menu-item key="logs-poller">
        <template #icon><unicon name="sync"></unicon></template>
        Poller logs
      </a-menu-item>
      <a-menu-item key="logs-schedule-reports">
        <template #icon><unicon name="calendar-alt"></unicon></template>
        Schedule reports
      </a-menu-item>
    </a-sub-menu>

    <NavTitle class="ninjadash-sidebar-nav-title">Management</NavTitle>

    <a-sub-menu key="device-mgmt">
      <template #icon><unicon name="server"></unicon></template>
      <template #title>Device management</template>
      <a-menu-item key="device-management">
        <template #icon><unicon name="edit"></unicon></template>
        Device management
      </a-menu-item>
      <a-menu-item key="device-access">
        <template #icon><unicon name="lock"></unicon></template>
        Accesses
      </a-menu-item>
      <a-menu-item key="device-group">
        <template #icon><unicon name="layer-group"></unicon></template>
        Groups
      </a-menu-item>
      <a-menu-item key="device-model">
        <template #icon><unicon name="box"></unicon></template>
        Models
      </a-menu-item>
      <a-menu-item key="autodiscovery">
        <template #icon><unicon name="search"></unicon></template>
        Autodiscovery
      </a-menu-item>
    </a-sub-menu>

    <a-sub-menu key="user-mgmt">
      <template #icon><unicon name="users-alt"></unicon></template>
      <template #title>Users</template>
      <a-menu-item key="users">
        <template #icon><unicon name="user"></unicon></template>
        Users
      </a-menu-item>
      <a-menu-item key="user-roles">
        <template #icon><unicon name="shield-check"></unicon></template>
        Roles
      </a-menu-item>
    </a-sub-menu>

    <a-sub-menu key="config">
      <template #icon><unicon name="setting"></unicon></template>
      <template #title>Configuration</template>
      <a-menu-item key="macros">
        <template #icon><unicon name="brackets-curly"></unicon></template>
        Macros
      </a-menu-item>
      <a-menu-item key="onts-registration">
        <template #icon><unicon name="clipboard-notes"></unicon></template>
        ONTs registration
      </a-menu-item>
      <a-menu-item key="notifications-config">
        <template #icon><unicon name="bell"></unicon></template>
        Notifications
      </a-menu-item>
      <a-menu-item key="events-config">
        <template #icon><unicon name="exclamation-triangle"></unicon></template>
        Event configuration
      </a-menu-item>
      <a-menu-item key="system-config">
        <template #icon><unicon name="setting"></unicon></template>
        System configuration
      </a-menu-item>
    </a-sub-menu>

    <NavTitle class="ninjadash-sidebar-nav-title">External apps</NavTitle>
    <a-menu-item v-for="app in externalApps" :key="app.key">
      <template #icon><unicon :name="app.icon"></unicon></template>
      {{ app.label }}
    </a-menu-item>

    <NavTitle class="ninjadash-sidebar-nav-title">QR printing</NavTitle>
    <a-sub-menu key="qr">
      <template #icon><unicon name="qrcode-scan"></unicon></template>
      <template #title>QR printing</template>
      <a-menu-item key="qr-devices">
        <template #icon><unicon name="server"></unicon></template>
        Devices
      </a-menu-item>
      <a-menu-item key="qr-interfaces">
        <template #icon><unicon name="wifi"></unicon></template>
        Interfaces
      </a-menu-item>
    </a-sub-menu>
  </a-menu>
</template>

<style>
/*
 * The template's own Div styled-component (layout/style.ts) injects a rule
 * that zeroes the icon gutter on every nested menu item:
 *   .ant-menu-submenu-inline .ant-menu-item { padding-left: 0 !important }
 * That went unnoticed in the original demo because those items never had
 * icons. The selector below deliberately repeats a class to out-specificity
 * it regardless of stylesheet load order, and restores a real icon+label
 * layout for submenu items.
 */
.ant-layout-sider .ant-menu .ant-menu-submenu-inline .ant-menu-item.ant-menu-item.ant-menu-item {
  padding-left: 44px !important;
  position: relative;
  display: flex !important;
  align-items: center;
}

.ant-menu-sub .ant-menu-item .unicon {
  position: relative !important;
  inset: auto !important;
  margin-right: 10px;
  flex-shrink: 0;
  display: inline-flex;
}

/* Tree connector lines, file-explorer style: a vertical rail down the
   submenu with a branch stub to each item, stopping halfway on the last
   item so the rail doesn't dangle past the final branch. */
.ant-menu-sub .ant-menu-item::before {
  content: '';
  position: absolute;
  left: 20px;
  top: 0;
  height: 100%;
  border-left: 1px dashed rgba(90, 95, 125, 0.35);
}
.ant-menu-sub .ant-menu-item:last-child::before {
  height: 50%;
}
.ant-menu-sub .ant-menu-item::after {
  content: '';
  position: absolute;
  left: 20px;
  top: 50%;
  width: 10px;
  border-top: 1px dashed rgba(90, 95, 125, 0.35);
}
</style>

