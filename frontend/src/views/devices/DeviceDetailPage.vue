<!--
  The per-device monitoring dashboard (Devices list card → click a device).
  Rebuilt to match the real production OLT device dashboard field-for-field,
  reverse engineered from its compiled bundle — every device on this system
  is an OLT (BDCOM/Huawei), so this mirrors the OLT-specific dashboard shell
  in DeviceInfo-CGuBjiZ6.js and its System-info sidebar in Topology-gTZFUR5F.js
  (the live app's own device page is itself currently stuck on a
  "No Internet Connection" fallback screen, unrelated to anything here, so
  reading the compiled bundle directly was the only reliable ground truth).

  Tabs mirror the real order and default (onts_tree is the tab that opens
  first, exactly as in the original): onts_tree, card_interfaces,
  unregistered_onts, history_log, events, topology, oxidized, pinger.
  Oxidized (config backup) is gated the same way the original gates it —
  only shown when the device's own `params.oxidized_enabled` is set,
  which both real devices on this system have.

  Also wired up: each interface's own detail page (ONU and physical/PON),
  the resource-history charts on the CPU/RAM/temperature gauges, and the
  web console (with and without auto-login) action buttons.

  Intentionally NOT built (scoped out, not silently dropped):
  - attachments / pon_boxes tabs — generic file attachments and a PON-box
    map subsystem. Neither has any supporting page elsewhere in this
    rebuild yet.
  - Macros — running arbitrary console commands against a live device —
    WAS scoped out here for the same reason (materially different feature
    to sign off on than the rest of this page's read-mostly parity work),
    but is now built (see MacrosCard.vue): its own "Run macro" wizard
    mirrors the ONT registration wizard's preview-then-confirm-then-execute
    flow, gated by the backend's `macros_execute` permission.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { Main } from '../styled';
import { useDeviceCalling } from '@/composables/useDeviceCalling';
import PollersCard from '@/components/deviceDetail/PollersCard.vue';
import SupportedModulesCard from '@/components/deviceDetail/SupportedModulesCard.vue';
import DeviceCallingCard from '@/components/deviceDetail/DeviceCallingCard.vue';
import OntsTreeTab from '@/components/deviceDetail/OntsTreeTab.vue';
import CardInterfacesTab from '@/components/deviceDetail/CardInterfacesTab.vue';
import SwitchInterfacesTab from '@/components/deviceDetail/SwitchInterfacesTab.vue';
import UnregisteredOntsTab from '@/components/deviceDetail/UnregisteredOntsTab.vue';
import HistoryLogTab from '@/components/deviceDetail/HistoryLogTab.vue';
import EventsTab from '@/components/deviceDetail/EventsTab.vue';
import TopologyTab from '@/components/deviceDetail/TopologyTab.vue';
import PingerTab from '@/components/deviceDetail/PingerTab.vue';
import OxidizedTab from '@/components/deviceDetail/OxidizedTab.vue';
import MacrosCard from '@/components/deviceDetail/MacrosCard.vue';
import PrometheusChartModal from '@/components/deviceDetail/PrometheusChartModal.vue';

interface DeviceData {
  id: number;
  ip: string;
  name: string;
  description: string;
  mac: string;
  serial: string;
  model: { id: number; name: string; icon: string | null; type: string };
  ifaces_stat: { up: number; down: number; last_stat: string; splitted: Record<string, { up: number; down: number }> } | null;
  deviceIsOffline?: boolean;
  params?: { oxidized_enabled?: boolean };
}
interface SystemInfo {
  descr: string;
  uptime: string;
  name: string;
  serial_num: string;
  mac_addr: string;
  board_software_ver: string;
  board_hardware_ver: string;
  meta?: { modules: string[] };
}
interface Resources {
  cpu: { util: number } | null;
  memory: { util: number } | null;
  temperatures: { main: number | null; main_from: string } | null;
}

const route = useRoute();
const router = useRouter();
const deviceId = computed(() => Number(route.params.id));
const deviceCalling = useDeviceCalling();

const loading = ref(true);
const device = ref<DeviceData | null>(null);
const systemInfo = ref<SystemInfo | null>(null);
const systemInfoError = ref(false);
const resources = ref<Resources | null>(null);
const modelMismatch = ref(false);

async function loadDevice() {
  const { data } = await DataService.get(`/device/${deviceId.value}`);
  device.value = data.data;
}
async function loadSystemInfo(from: 'cache' | 'device' = 'cache') {
  try {
    const { data } = await DataService.get(`/switcher-core/device/system/${deviceId.value}`, { from });
    systemInfo.value = data.data;
    systemInfoError.value = false;
  } catch {
    systemInfo.value = null;
    systemInfoError.value = true;
  }
}
async function loadResources(from: 'cache' | 'device' = 'cache') {
  try {
    const { data } = await DataService.get(`/component/switches/resources/${deviceId.value}`, { from });
    resources.value = data.data;
    deviceCalling.setMeta(data.meta);
  } catch {
    resources.value = null;
  }
}
async function loadCompareModel() {
  try {
    const { data } = await DataService.get(`/device/${deviceId.value}/compare-model`);
    modelMismatch.value = data.data?.is_different === true || data.data?.is_different === 'true';
  } catch {
    modelMismatch.value = false;
  }
}

// --- web console (ttyd), with and without auto-login ---
const consoleEnabled = ref(false);
async function loadConsoleConfig() {
  try {
    const { data } = await DataService.get('/component/console/configuration');
    consoleEnabled.value = !!data.data?.web_enabled && data.data.web_enabled !== '0';
  } catch {
    consoleEnabled.value = false;
  }
}
// REVERTED: an in-app iframe modal was tried here, but confirmed live it
// broke exactly the thing it was meant to help — on a mobile browser, the
// on-screen keyboard's extra-key row (Tab/Ctrl/Esc/arrows) is a feature of
// that browser recognizing a focused text input at the TOP level of the
// page; ttyd's own hidden input loses that recognition once it's nested
// inside an iframe (a real, known iframe+virtual-keyboard limitation, not
// anything ttyd itself does differently). A genuine top-level page open —
// what this always did before — keeps that working, and mobile browsers
// open a new tab full-screen anyway, so it's not meaningfully worse for
// mobile than a modal would have been. disableLeaveAlert=1 is one of
// ttyd's own documented client query options (suppresses its "are you
// sure you want to leave" browser prompt) — harmless if the vendored
// ttyd build predates it, kept from the modal attempt.
function openConsole(autoLogin: boolean) {
  const url = `/ttyd/console/?titleFixed=${encodeURIComponent(`${device.value?.ip} | DMS`)}&disableLeaveAlert=1&arg=${deviceId.value}${autoLogin ? '' : '&arg=-l'}`;
  window.open(url, '_blank');
}

// --- resource history charts (CPU/RAM/temperature gauges) ---
const chartModalRef = ref<InstanceType<typeof PrometheusChartModal> | null>(null);
function showCpuChart() {
  chartModalRef.value?.open('CPU utilization chart', '/component/prometheus_wrapper/chart-cpu-load-series', { device_id: deviceId.value, step: '30m' });
}
function showMemoryChart() {
  chartModalRef.value?.open('RAM utilization chart', '/component/prometheus_wrapper/chart-memory-load-series', { device_id: deviceId.value, step: '30m' });
}
function showTemperatureChart() {
  chartModalRef.value?.open('Temperature chart', '/component/prometheus_wrapper/chart-temperature-series', { device_id: deviceId.value, step: '30m' });
}

const tabStats = ref<{ unregistered_onts: number; events: number; links: number }>({ unregistered_onts: 0, events: 0, links: 0 });
async function loadTabStats() {
  const path = device.value?.model?.type === 'SWITCH' ? 'switches' : 'olts';
  try {
    const { data } = await DataService.get(`/component/${path}/tab-stats/${deviceId.value}`);
    tabStats.value = data.data;
  } catch {
    /* non-fatal */
  }
}

type TabName = 'onts_tree' | 'card_interfaces' | 'switch_interfaces' | 'unregistered_onts' | 'history_log' | 'events' | 'topology' | 'oxidized' | 'pinger' | 'macros';
const activeTab = ref<TabName>('onts_tree');
// `onts_tree`/`card_interfaces` are OLT-only endpoints (`/component/olts/...`)
// — confirmed live they 500 for a plain switch (a real bug: a newly-added
// BDCOM S5612 switch hit this immediately). Every device type gets an
// appropriately-scoped set of tabs instead of assuming OLT for everything.
const tabs = computed<{ key: TabName; label: string; counterKey?: keyof typeof tabStats.value }[]>(() => {
  const isOlt = (device.value?.model?.type || 'OLT') === 'OLT';
  const isSwitch = device.value?.model?.type === 'SWITCH';
  const list: { key: TabName; label: string; counterKey?: keyof typeof tabStats.value }[] = [];
  if (isOlt) {
    list.push({ key: 'onts_tree', label: 'ONTs tree' });
    list.push({ key: 'card_interfaces', label: 'Physical ports' });
    list.push({ key: 'unregistered_onts', label: 'Unregistered ONTs', counterKey: 'unregistered_onts' });
  }
  if (isSwitch) {
    list.push({ key: 'switch_interfaces', label: 'Interfaces' });
  }
  list.push({ key: 'history_log', label: 'History log' });
  list.push({ key: 'events', label: 'Events', counterKey: 'events' });
  list.push({ key: 'topology', label: 'Topology' });
  if (device.value?.params?.oxidized_enabled) list.push({ key: 'oxidized', label: 'Config backup' });
  list.push({ key: 'pinger', label: 'Pinger' });
  list.push({ key: 'macros', label: 'Macros' });
  return list;
});
// Keep the active tab valid once `tabs` narrows down for a non-OLT device
// (its default 'onts_tree' won't be in the list at all for a switch).
watch(
  tabs,
  (list) => {
    if (!list.some((t) => t.key === activeTab.value) && list.length) activeTab.value = list[0].key;
  },
  { immediate: true },
);

const ontsTreeRef = ref<InstanceType<typeof OntsTreeTab> | null>(null);
const cardInterfacesRef = ref<InstanceType<typeof CardInterfacesTab> | null>(null);
const switchInterfacesRef = ref<InstanceType<typeof SwitchInterfacesTab> | null>(null);
const unregisteredRef = ref<InstanceType<typeof UnregisteredOntsTab> | null>(null);
const historyLogRef = ref<InstanceType<typeof HistoryLogTab> | null>(null);
const eventsRef = ref<InstanceType<typeof EventsTab> | null>(null);
const topologyRef = ref<InstanceType<typeof TopologyTab> | null>(null);
const oxidizedRef = ref<InstanceType<typeof OxidizedTab> | null>(null);
const pingerRef = ref<InstanceType<typeof PingerTab> | null>(null);
const macrosRef = ref<InstanceType<typeof MacrosCard> | null>(null);
const tabRefs = {
  onts_tree: ontsTreeRef,
  card_interfaces: cardInterfacesRef,
  switch_interfaces: switchInterfacesRef,
  unregistered_onts: unregisteredRef,
  history_log: historyLogRef,
  events: eventsRef,
  topology: topologyRef,
  oxidized: oxidizedRef,
  pinger: pingerRef,
  macros: macrosRef,
};

function onGoToTree(search: string) {
  activeTab.value = 'onts_tree';
  setTimeout(() => ontsTreeRef.value?.setSearchLine(search), 5);
}

const reloading = ref(false);
async function reloadInfo(from: 'cache' | 'device' = 'device') {
  reloading.value = true;
  try {
    await Promise.allSettled([loadDevice(), loadSystemInfo(from), loadResources(from), loadCompareModel(), loadTabStats()]);
    const activeRef = tabRefs[activeTab.value].value as any;
    if (activeRef && typeof activeRef.loadInfo === 'function') await activeRef.loadInfo(from);
    if (from === 'device') notification.success({ message: 'Device info reloaded' });
  } finally {
    reloading.value = false;
  }
}

function cpuBarClass(util: number) {
  if (util < 40) return 'is-ok';
  if (util < 60) return 'is-info';
  if (util < 80) return 'is-warn';
  return 'is-danger';
}
function goEdit() {
  router.push({ name: 'device-management-edit', params: { id: deviceId.value } });
}

const qrModalOpen = ref(false);
const qrImage = ref('');
const qrLoading = ref(false);
async function openQr() {
  qrModalOpen.value = true;
  qrLoading.value = true;
  try {
    const { data } = await DataService.get(`/component/qr-generator/qr-code-base64/device/${deviceId.value}`, { size: 400, with_logo: true, with_label: true });
    qrImage.value = data.data?.qr || '';
  } catch {
    qrImage.value = '';
  } finally {
    qrLoading.value = false;
  }
}

onMounted(async () => {
  loading.value = true;
  // loadDevice() first and awaited alone — loadTabStats() reads
  // device.value.model.type to pick the right tab-stats endpoint
  // (olts vs switches), so it needs the device to already be loaded.
  await loadDevice();
  await Promise.allSettled([loadSystemInfo(), loadResources(), loadCompareModel(), loadTabStats(), loadConsoleConfig()]);
  const tabQuery = route.query.tab as TabName | undefined;
  if (tabQuery && tabs.value.some((t) => t.key === tabQuery)) activeTab.value = tabQuery;
  loading.value = false;
});

// Real-time: the shell-level system/resources card and the tab counters
// (unregistered ONTs, events, links) are all cache reads keyed off this
// device — a poller finishing or an audited action against it means they're
// likely stale, so quietly re-pull them instead of only updating on the
// manual "Reload info" click.
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === deviceId.value) {
    loadSystemInfo('cache');
    loadResources('cache');
  }
});
const unsubAction = wsClient.subscribe('event:sys_action:added', (msg) => {
  if (msg.data?.device?.id === deviceId.value) loadTabStats();
});
onBeforeUnmount(() => {
  unsubPoller();
  unsubAction();
});
</script>

<template>
  <sdPageHeader
    :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '/devices/list', breadcrumbName: 'Devices' }, { path: '', breadcrumbName: device?.name || 'Device' }]"
    class="ninjadash-page-header-main"
  >
    <template #buttons>
      <sdButton type="light" @click="router.push({ name: 'devices-list' })"><unicon name="arrow-left"></unicon> Back</sdButton>
      <sdButton type="primary" :loading="reloading" @click="reloadInfo('device')"><unicon name="redo"></unicon> Reload info</sdButton>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <div v-else class="dd-grid">
      <div class="dd-grid__info">
        <sdCards :headless="true" style="margin-bottom: 16px">
          <div v-if="modelMismatch" class="dd-hint dd-hint--warn">
            <unicon name="exclamation-triangle"></unicon> Detected hardware doesn't match the configured model.
          </div>
          <div class="dd-sysinfo__top">
            <img v-if="device?.model?.icon" :src="device.model.icon" class="dd-sysinfo__icon" alt="" />
            <sdButton type="light" size="small" title="QR code" @click="openQr"><unicon name="qrcode-scan"></unicon> QR code</sdButton>
          </div>

          <div class="dd-sysinfo__section">
            <div class="dd-sysinfo__label">From storage</div>
            <p><unicon name="server-network"></unicon> Model: <strong>{{ device?.model?.name }}</strong></p>
            <p><unicon name="edit"></unicon> Name: <strong>{{ device?.name }}</strong></p>
            <p><unicon name="globe"></unicon> IP: <strong>{{ device?.ip }}</strong></p>
            <p v-if="device?.mac"><unicon name="bolt"></unicon> MAC: <strong>{{ device.mac }}</strong></p>
            <p v-if="device?.serial"><unicon name="bolt"></unicon> Serial: <strong>{{ device.serial }}</strong></p>
            <p v-if="device?.description"><unicon name="comment-alt-message"></unicon> {{ device.description }}</p>
          </div>

          <template v-if="systemInfo">
            <hr />
            <div class="dd-sysinfo__section">
              <div class="dd-sysinfo__label">From device</div>
              <p><unicon name="clock"></unicon> Uptime: <strong>{{ systemInfo.uptime }}</strong></p>
              <p v-if="systemInfo.board_software_ver"><unicon name="bolt"></unicon> Software ver: <strong>{{ systemInfo.board_software_ver }}</strong></p>
              <p v-if="systemInfo.board_hardware_ver"><unicon name="bolt"></unicon> Hardware ver: <strong>{{ systemInfo.board_hardware_ver }}</strong></p>
              <p v-if="systemInfo.descr" class="dd-sysinfo__descr">{{ systemInfo.descr }}</p>
            </div>
          </template>
          <div v-else-if="systemInfoError" class="dd-hint">Live system info unavailable (device may be offline or unreachable).</div>

          <template v-if="resources">
            <hr />
            <div class="dd-sysinfo__section">
              <div v-if="resources.cpu" class="dd-gauge">
                <span class="dd-gauge__label">CPU</span>
                <div class="dd-gauge__bar"><div class="dd-gauge__fill" :class="cpuBarClass(resources.cpu.util)" :style="{ width: resources.cpu.util + '%' }"></div></div>
                <span class="dd-gauge__value">{{ resources.cpu.util }}%</span>
                <a class="dd-gauge__chart" title="CPU history" @click="showCpuChart"><unicon name="chart-bar" width="13" height="13"></unicon></a>
              </div>
              <div v-if="resources.memory" class="dd-gauge">
                <span class="dd-gauge__label">RAM</span>
                <div class="dd-gauge__bar"><div class="dd-gauge__fill" :class="cpuBarClass(resources.memory.util)" :style="{ width: resources.memory.util + '%' }"></div></div>
                <span class="dd-gauge__value">{{ resources.memory.util }}%</span>
                <a class="dd-gauge__chart" title="RAM history" @click="showMemoryChart"><unicon name="chart-bar" width="13" height="13"></unicon></a>
              </div>
              <div v-if="resources.temperatures?.main != null" class="dd-gauge">
                <span class="dd-gauge__label">Temp <small>({{ resources.temperatures.main_from }})</small></span>
                <div class="dd-gauge__bar"><div class="dd-gauge__fill" :class="cpuBarClass((resources.temperatures.main / 90) * 100)" :style="{ width: Math.min(100, (resources.temperatures.main / 90) * 100) + '%' }"></div></div>
                <span class="dd-gauge__value">{{ resources.temperatures.main }}°C</span>
                <a class="dd-gauge__chart" title="Temperature history" @click="showTemperatureChart"><unicon name="chart-bar" width="13" height="13"></unicon></a>
              </div>
            </div>
          </template>

          <template v-if="device?.ifaces_stat">
            <hr />
            <div class="dd-sysinfo__section">
              <div class="dd-sysinfo__label">Interfaces</div>
              <div v-for="(stat, type) in device.ifaces_stat.splitted" :key="type" class="dd-iface-stat">
                <unicon :name="type === 'ONU' ? 'wifi-router' : 'plug'"></unicon>
                <span class="dd-iface-stat__type">{{ type }}</span>
                <span class="dd-iface-stat__up">{{ stat.up }}</span>/<span class="dd-iface-stat__down">{{ stat.down }}</span>/{{ stat.up + stat.down }}
              </div>
              <p class="dd-hint">Updated: {{ device.ifaces_stat.last_stat }}</p>
            </div>
          </template>

          <hr />
          <div class="dd-actions">
            <sdButton type="light" size="small" @click="goEdit"><unicon name="edit"></unicon> Edit</sdButton>
            <a class="dd-actions__link" :href="`http://${device?.ip}`" target="_blank" rel="noopener"><unicon name="globe"></unicon> Web</a>
            <a class="dd-actions__link" :href="`ssh://${device?.ip}`"><unicon name="lock"></unicon> SSH</a>
            <a class="dd-actions__link" :href="`telnet://${device?.ip}`"><unicon name="window-maximize"></unicon> Telnet</a>
            <a v-if="consoleEnabled" class="dd-actions__link" href="javascript:void(0)" title="Console" @click="openConsole(false)"><unicon name="window-maximize"></unicon> Console</a>
            <a v-if="consoleEnabled" class="dd-actions__link" href="javascript:void(0)" title="Console with auto-login" @click="openConsole(true)"><unicon name="key-skeleton-alt"></unicon> Console (auto)</a>
          </div>
        </sdCards>
      </div>

      <div class="dd-grid__tabs">
        <sdCards :headless="true">
          <a-tabs v-model:active-key="activeTab" :destroy-inactive-tab-pane="true">
            <a-tab-pane v-for="t in tabs" :key="t.key">
              <template #tab>
                {{ t.label }}
                <a-badge v-if="t.counterKey && tabStats[t.counterKey]" :count="tabStats[t.counterKey]" :number-style="{ backgroundColor: '#c77301' }" />
              </template>
              <OntsTreeTab v-if="t.key === 'onts_tree'" ref="ontsTreeRef" :device-id="deviceId" :device-calling="deviceCalling" :modules="systemInfo?.meta?.modules || []" />
              <CardInterfacesTab
                v-else-if="t.key === 'card_interfaces'"
                ref="cardInterfacesRef"
                :device-id="deviceId"
                :device-calling="deviceCalling"
                :modules="systemInfo?.meta?.modules || []"
                @go-to-tree="onGoToTree"
              />
              <SwitchInterfacesTab v-else-if="t.key === 'switch_interfaces'" ref="switchInterfacesRef" :device-id="deviceId" />
              <UnregisteredOntsTab v-else-if="t.key === 'unregistered_onts'" ref="unregisteredRef" :device-id="deviceId" />
              <HistoryLogTab v-else-if="t.key === 'history_log'" ref="historyLogRef" :device-id="deviceId" />
              <EventsTab v-else-if="t.key === 'events'" ref="eventsRef" :device-id="deviceId" />
              <TopologyTab v-else-if="t.key === 'topology'" ref="topologyRef" :device-id="deviceId" />
              <OxidizedTab v-else-if="t.key === 'oxidized'" ref="oxidizedRef" :device-id="deviceId" :device-calling="deviceCalling" />
              <PingerTab v-else-if="t.key === 'pinger'" ref="pingerRef" :device-id="deviceId" />
              <MacrosCard v-else-if="t.key === 'macros'" ref="macrosRef" :device-id="deviceId" />
            </a-tab-pane>
          </a-tabs>
        </sdCards>
      </div>

      <div class="dd-grid__extra">
        <PollersCard :device-id="deviceId" />
        <SupportedModulesCard v-if="systemInfo?.meta?.modules" :modules="systemInfo.meta.modules" />
        <DeviceCallingCard :device-calling="deviceCalling" :offline="device?.deviceIsOffline" />
      </div>
    </div>

    <a-modal v-model:visible="qrModalOpen" title="Device QR code" :footer="null" width="420px">
      <a-skeleton v-if="qrLoading" active />
      <div v-else-if="qrImage" style="text-align: center">
        <img :src="qrImage" style="max-width: 100%" alt="QR code" />
      </div>
      <p v-else>Could not load QR code.</p>
    </a-modal>

    <PrometheusChartModal ref="chartModalRef" />
  </Main>
</template>

<style scoped>
/*
 * A CSS grid (not a-row/a-col) on purpose: the "extra" sidebar cards
 * (Pollers/Supported modules/Device calling) need to render directly
 * below the System-info card on desktop regardless of how tall the tabs
 * column gets (grid areas keep each column's own height independent —
 * flexbox row-wrapping doesn't), while on mobile they need to drop to
 * the very end, after the tabs — below.
 */
.dd-grid {
  display: grid;
  grid-template-columns: 7fr 17fr;
  grid-template-areas: 'info tabs' 'extra tabs';
  align-items: start;
  gap: 25px;
}
.dd-grid__info {
  grid-area: info;
}
.dd-grid__tabs {
  grid-area: tabs;
  min-width: 0;
}
.dd-grid__extra {
  grid-area: extra;
}
@media (max-width: 767px) {
  .dd-grid {
    grid-template-columns: 1fr;
    grid-template-areas: 'info' 'tabs' 'extra';
  }
}
.dd-hint {
  font-size: 12px;
  color: #8c90a4;
  margin-bottom: 10px;
}
.dd-hint--warn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(212, 160, 23, 0.1);
  color: #ab7d0a;
  padding: 8px 10px;
  border-radius: 6px;
  margin-bottom: 12px;
}
.dd-hint--warn :deep(svg) {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}
.dd-sysinfo__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.dd-sysinfo__icon {
  max-width: 160px;
  max-height: 90px;
  object-fit: contain;
}
.dd-sysinfo__section p {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0 0 6px;
  font-size: 13px;
  color: #4b5069;
}
.dd-sysinfo__section p :deep(svg) {
  width: 13px;
  height: 13px;
  color: #8c90a4;
  flex-shrink: 0;
}
.dd-sysinfo__label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: #8c90a4;
  margin-bottom: 8px;
}
.dd-sysinfo__descr {
  white-space: pre-wrap;
  font-size: 12px;
  background: #f8f9fb;
  border-radius: 4px;
  padding: 6px 8px;
}
.dd-gauge {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.dd-gauge__label {
  flex: 0 0 60px;
  font-size: 12px;
  font-weight: 700;
  color: #5a5f7d;
}
.dd-gauge__bar {
  flex: 1;
  height: 10px;
  background: #eceef4;
  border-radius: 999px;
  overflow: hidden;
}
.dd-gauge__fill {
  height: 100%;
  border-radius: 999px;
}
.dd-gauge__fill.is-ok {
  background: #30a46c;
}
.dd-gauge__fill.is-info {
  background: #1868db;
}
.dd-gauge__fill.is-warn {
  background: #d4a017;
}
.dd-gauge__fill.is-danger {
  background: #e5484d;
}
.dd-gauge__value {
  flex: 0 0 44px;
  text-align: right;
  font-size: 12px;
  font-weight: 700;
  color: #272b41;
}
.dd-gauge__chart {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 6px;
  background: #eef1f8;
  color: #5a5f7d;
  transition: background 0.1s ease, transform 0.1s ease;
}
/* The app's global `.unicon svg { fill: <theme gray> }` rule otherwise
   wins over this button's own color — force it to match explicitly. */
.dd-gauge__chart :deep(svg) {
  fill: #5a5f7d !important;
}
.dd-gauge__chart:hover {
  background: #1868db;
  transform: translateY(-1px);
}
.dd-gauge__chart:hover :deep(svg) {
  fill: #fff !important;
}
.dd-iface-stat {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  margin-bottom: 6px;
}
.dd-iface-stat :deep(svg) {
  width: 14px;
  height: 14px;
  color: #8c90a4;
}
.dd-iface-stat__type {
  flex: 1;
  color: #5a5f7d;
}
.dd-iface-stat__up {
  color: #1a7a3a;
  font-weight: 700;
}
.dd-iface-stat__down {
  color: #a60a0a;
  font-weight: 700;
}
.dd-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.dd-actions__link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1px solid #e6e9f1;
  border-radius: 4px;
  padding: 4px 10px;
  font-size: 12px;
  color: #5a5f7d;
}
.dd-actions__link:hover {
  color: #1868db;
  border-color: #1868db;
}
.dd-actions__link :deep(svg) {
  width: 12px;
  height: 12px;
}
</style>
