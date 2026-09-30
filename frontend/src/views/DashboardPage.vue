<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import { Main } from './styled';
import { GChart } from 'vue-google-charts';
import HeartbeatAreaChart from '@/components/utilities/HeartbeatAreaChart.vue';

dayjs.extend(relativeTime);

const router = useRouter();
const loading = ref(true);

interface ChartData {
  labels: string[];
  datasets: any[];
}

// --- System stat (bottom list widget) ---
interface SystemStat {
  users: number;
  roles: number;
  devices: number;
  device_groups: number;
  interfaces: number;
}
const systemStat = ref<SystemStat | null>(null);
const systemStatRows = computed(() => [
  { key: 'devices', label: 'Count devices', icon: 'server-network', value: systemStat.value?.devices, route: 'devices-list' },
  { key: 'interfaces', label: 'Count interfaces', icon: 'wifi', value: systemStat.value?.interfaces, route: undefined },
  { key: 'device_groups', label: 'Count device groups', icon: 'layer-group', value: systemStat.value?.device_groups, route: 'device-group' },
  { key: 'users', label: 'Count users', icon: 'user', value: systemStat.value?.users, route: 'users' },
  { key: 'roles', label: 'Count roles', icon: 'shield-check', value: systemStat.value?.roles, route: 'user-roles' },
]);

// --- Events severity (stacked single card) ---
interface SeverityStat {
  INFO: number;
  WARNING: number;
  CRITICAL: number;
}
const severityStat = ref<SeverityStat>({ INFO: 0, WARNING: 0, CRITICAL: 0 });
const severityCards = computed(() => [
  { key: 'INFO', label: 'Info', value: severityStat.value.INFO, class: 'sev-block--info' },
  { key: 'WARNING', label: 'Warning', value: severityStat.value.WARNING, class: 'sev-block--warning' },
  { key: 'CRITICAL', label: 'Critical', value: severityStat.value.CRITICAL, class: 'sev-block--critical' },
]);

// --- ONT statuses pie ---
interface OntStatuses {
  labels: string[];
  datasets: { label: string; data: number[]; backgroundColor: string[] }[];
}
const ontStatuses = ref<OntStatuses | null>(null);
const ontStatusRows = computed(() => {
  if (!ontStatuses.value) return [];
  const { labels, datasets } = ontStatuses.value;
  return labels.map((label, i) => ({ label, count: datasets[0].data[i], color: datasets[0].backgroundColor[i] }));
});

// --- Pinger (ApexCharts donut) ---
const pingerStat = ref<ChartData | null>(null);
const pingerUp = computed(() => pingerStat.value?.datasets[0].data[pingerStat.value.labels.indexOf('up')] ?? 0);
const pingerDown = computed(() => pingerStat.value?.datasets[0].data[pingerStat.value.labels.indexOf('down')] ?? 0);
const pingerApexSeries = computed(() => pingerStat.value?.datasets[0].data ?? []);
const pingerApexOptions = computed(() => ({
  // ApexCharts replays its own entry animation on every re-render by
  // default — since `options`/`series` are computed refs that rebuild on
  // every background refresh (WS-triggered or timer), that meant this
  // donut visibly redrew itself from scratch every few seconds.
  // dynamicAnimation is the specific "replay on data update" animation —
  // disabling only that (animations.enabled stays true) keeps the normal
  // one-time entrance animation on the real first load, matching every
  // other chart on this page, while silencing just the repeat replays.
  chart: { type: 'donut', animations: { enabled: true, dynamicAnimation: { enabled: false } } },
  labels: pingerStat.value?.labels.map((l) => l.toUpperCase()) ?? [],
  colors: ['#0a7318', '#8b0000'],
  legend: { show: false },
  dataLabels: { enabled: false },
  stroke: { width: 2 },
  plotOptions: { pie: { donut: { size: '70%' } } },
}));

// --- ONT statuses (Google Charts 3D pie) ---
const ontStatusesGoogleData = computed(() => {
  if (!ontStatuses.value) return [];
  const { labels, datasets } = ontStatuses.value;
  return [['Status', 'Count'], ...labels.map((label, i) => [label, datasets[0].data[i]])];
});
// The backend sends colors as CSS rgba(...) strings — Google Charts' own
// color option only understands hex/named colors and silently renders an
// "Invalid color" error banner instead of the chart when given rgba().
function rgbaToHex(color: string): string {
  const m = color.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
  if (!m) return color;
  const [, r, g, b] = m;
  return '#' + [r, g, b].map((n) => Number(n).toString(16).padStart(2, '0')).join('');
}
const ontStatusesGoogleOptions = computed(() => ({
  is3D: true,
  colors: (ontStatuses.value?.datasets[0].backgroundColor ?? []).map(rgbaToHex),
  legend: { position: 'none' },
  chartArea: { left: 10, top: 10, right: 10, bottom: 10, width: '100%', height: '100%' },
  pieSliceText: 'value',
  pieSliceTextStyle: { fontSize: 13 },
}));

// --- ONT signal (ApexCharts bar) ---
const ontLevelsBar = ref<ChartData | null>(null);
const ontLevelsApexSeries = computed(() => [
  { name: 'ONTs', data: ontLevelsBar.value?.datasets[0].data.map(Number) ?? [] },
]);
// The backend colors each bar individually (amber for weak/borderline
// signal, green for healthy, dark red for the worst) via
// datasets[0].backgroundColor[i] — Apex takes that as a per-point color
// list on the series itself rather than a flat top-level `colors` array.
const ontLevelsApexOptions = computed(() => ({
  // Same reasoning as pingerApexOptions above — normal one-time entrance
  // animation on first load, no replay on background refreshes.
  chart: { type: 'bar', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: true, dynamicAnimation: { enabled: false } } },
  plotOptions: { bar: { columnWidth: '70%', borderRadius: 2, distributed: true } },
  colors: ontLevelsBar.value?.datasets[0].backgroundColor ?? ['#1868db'],
  dataLabels: { enabled: false },
  legend: { show: false },
  grid: { borderColor: '#485e9015' },
  xaxis: { categories: ontLevelsBar.value?.labels ?? [], labels: { style: { fontSize: '11px' } } },
  yaxis: { min: 0, labels: { style: { fontSize: '11px' } } },
  tooltip: { y: { formatter: (v: number) => `${v} ONTs` } },
}));

// --- Bad signal (small stat card) ---
const badSignalCount = ref<number | null>(null);

// --- Online ONTs / Online devices trend (Chart.js area, HeartbeatAreaChart.vue) ---
const ontStatusesTrend = ref<ChartData | null>(null);
const deviceStatusesTrend = ref<ChartData | null>(null);
const trendLoading = ref(false);

// Time-range ladder for the two trend charts, shortest → longest. The
// backend (Prometheus-backed) takes start/end as unix seconds and a step
// duration string — going finer than the step for a given range just
// re-requests more points than Prometheus actually has, so each rung pairs
// a sensible bucket size with its span.
const rangeLadder = [
  { label: '5m', seconds: 5 * 60, step: '15s' },
  { label: '15m', seconds: 15 * 60, step: '30s' },
  { label: '20m', seconds: 20 * 60, step: '30s' },
  { label: '30m', seconds: 30 * 60, step: '1m' },
  { label: '1h', seconds: 60 * 60, step: '2m' },
  { label: '3h', seconds: 3 * 60 * 60, step: '5m' },
  { label: '6h', seconds: 6 * 60 * 60, step: '10m' },
  { label: '12h', seconds: 12 * 60 * 60, step: '10m' },
  { label: '24h', seconds: 24 * 60 * 60, step: '30m' },
  { label: '3d', seconds: 3 * 24 * 60 * 60, step: '2h' },
  { label: '7d', seconds: 7 * 24 * 60 * 60, step: '4h' },
  { label: '15d', seconds: 15 * 24 * 60 * 60, step: '8h' },
];
const rangeIndex = ref(7); // 12h default
const selectedRange = computed(() => rangeLadder[rangeIndex.value]);
const rangeTo = ref(Date.now());
const rangeFromLabel = computed(() => dayjs(rangeTo.value - selectedRange.value.seconds * 1000).format('DD MMM, HH:mm'));
const rangeToLabel = computed(() => dayjs(rangeTo.value).format('DD MMM, HH:mm'));
const canZoomIn = computed(() => rangeIndex.value > 0);
const canZoomOut = computed(() => rangeIndex.value < rangeLadder.length - 1);

// The device-statuses trend endpoint returns "All" (total device count) and
// "Online" (the online subset) — NOT complementary segments the way the ONT
// chart's LOS/Offline/Online three datasets are. HeartbeatAreaChart stacks
// whatever datasets it's given as additive layers, so feeding it "All" +
// "Online" directly double-counts (both were ~equal with a single device,
// which is exactly why there was never a visible red/offline band even
// though the chart *looked* like it had one). Derived a real Offline
// series (All - Online) here instead, so a device actually going down
// shows up the same way the ONT chart already shows LOS/Offline devices.
function toOnlineOfflineChart(raw: ChartData | null): ChartData | null {
  if (!raw) return raw;
  const all = raw.datasets.find((d) => /all/i.test(d.label))?.data;
  const online = raw.datasets.find((d) => /online/i.test(d.label))?.data;
  if (!all || !online) return raw;
  const offline = all.map((total: number, i: number) => Math.max(0, total - (online[i] ?? 0)));
  return {
    labels: raw.labels,
    datasets: [
      { label: 'Offline', data: offline, borderColor: '#e5484d', backgroundColor: '#e5484d' },
      { label: 'Online', data: online, borderColor: '#0a7318', backgroundColor: '#0a7318' },
    ],
  };
}

async function loadTrends(background = false) {
  if (!background) trendLoading.value = true;
  const end = Math.floor(rangeTo.value / 1000);
  const start = end - selectedRange.value.seconds;
  const { step } = selectedRange.value;
  const [ontRes, deviceRes] = await Promise.allSettled([
    DataService.post('/component/analytics/charts/ont-statuses', { start, end, step }),
    DataService.post('/component/analytics/charts/device-statuses', { start, end, step }),
  ]);
  if (ontRes.status === 'fulfilled') ontStatusesTrend.value = ontRes.value.data.data;
  if (deviceRes.status === 'fulfilled') deviceStatusesTrend.value = toOnlineOfflineChart(deviceRes.value.data.data);
  if (!background) trendLoading.value = false;
}
function zoomIn() {
  if (!canZoomIn.value) return;
  rangeIndex.value -= 1;
  rangeTo.value = Date.now();
  loadTrends();
}
function zoomOut() {
  if (!canZoomOut.value) return;
  rangeIndex.value += 1;
  rangeTo.value = Date.now();
  loadTrends();
}

// Online ONTs chart / Online devices below are rendered by
// HeartbeatAreaChart.vue (Chart.js), which takes the raw `ChartData` shape
// (labels + datasets with label/data/borderColor) directly — see that
// file's header comment for why this moved off both ApexCharts and the
// shared Chartjs.vue wrapper.

// --- Error calling by device ---
interface ErrorCallingDevice {
  device: { id: number; ip: string; name: string };
  errors_count: number;
  not_responding_count: number;
}
interface ErrorCalling {
  errors_count: number;
  not_responding_count: number;
  data: ErrorCallingDevice[];
}
const errorCalling = ref<ErrorCalling | null>(null);

// --- Last user activity ---
interface SystemAction {
  id: number;
  created_at: string;
  action: string;
  user?: { name: string } | null;
  message: string;
  status: 'SUCCESS' | 'FAILED' | string;
}
const latestActions = ref<SystemAction[]>([]);

// --- Unregistered ONTs ---
const unregisteredOnts = ref<any[]>([]);

// --- Events by name ---
interface EventByName {
  name: string;
  severity: 'INFO' | 'WARNING' | 'CRITICAL';
  count: number;
}
const eventsByName = ref<EventByName[]>([]);

// --- Events table ---
interface WcaEvent {
  id: number;
  annotation: string;
  description: string;
  severity: 'INFO' | 'WARNING' | 'CRITICAL';
  created_at: string;
  resolved_at: string | null;
  device?: { id: number; name: string; ip: string } | null;
  labels?: Record<string, any>;
}
const eventsTable = ref<WcaEvent[]>([]);

const severityMeta: Record<string, { class: string; icon: string }> = {
  CRITICAL: { class: 'sev-critical', icon: 'exclamation-triangle' },
  WARNING: { class: 'sev-warning', icon: 'bell' },
  INFO: { class: 'sev-info', icon: 'info-circle' },
};

const resolvingId = ref<number | null>(null);
const resolvingAll = ref(false);
const unresolvedCount = computed(() => eventsTable.value.filter((e) => !e.resolved_at).length);

async function resolveEvent(ev: WcaEvent) {
  resolvingId.value = ev.id;
  try {
    await DataService.put(`/component/events/${ev.id}/resolve`);
    ev.resolved_at = dayjs().format('YYYY-MM-DD HH:mm:ss');
    notification.success({ message: 'Event resolved' });
  } catch (err: any) {
    notification.error({
      message: 'Could not resolve event',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    resolvingId.value = null;
  }
}

function confirmResolve(ev: WcaEvent) {
  Modal.confirm({
    title: 'Resolve this event?',
    content: ev.annotation,
    okText: 'Resolve',
    onOk: () => resolveEvent(ev),
  });
}

function confirmResolveAll() {
  Modal.confirm({
    title: `Resolve ${unresolvedCount.value} unresolved event(s) shown here?`,
    content: 'Only the events currently listed in this widget are affected.',
    okText: 'Resolve all',
    onOk: async () => {
      resolvingAll.value = true;
      // The backend's /resolve-all is filter-scoped, not ID-scoped — it
      // would resolve every unresolved event matching the filter system-wide
      // (hundreds, in this account), completely ignoring a `limit`. Loop the
      // per-event endpoint instead so this genuinely only touches the
      // handful of events actually shown in the widget.
      const targets = eventsTable.value.filter((e) => !e.resolved_at);
      const now = dayjs().format('YYYY-MM-DD HH:mm:ss');
      const results = await Promise.allSettled(
        targets.map((e) => DataService.put(`/component/events/${e.id}/resolve`)),
      );
      let ok = 0;
      results.forEach((r, i) => {
        if (r.status === 'fulfilled') {
          targets[i].resolved_at = now;
          ok += 1;
        }
      });
      resolvingAll.value = false;
      if (ok === targets.length) {
        notification.success({ message: `Resolved ${ok} event(s)` });
      } else {
        notification.error({ message: `Resolved ${ok} of ${targets.length} — some failed, please retry.` });
      }
    },
  });
}

function goTo(name?: string, params?: Record<string, any>) {
  if (name) router.push({ name, params });
}

// `background`: true for a WS-triggered or timer-based refresh — updates
// every widget's data in place without touching `loading`, so Vue's own
// reactivity just swaps the numbers/rows instead of every widget's
// <a-skeleton> flashing blank and back. Confirmed live this was happening
// on EVERY poller finishing anywhere in the fleet (debounced to 3s, but
// with many devices that's still often enough to look like the whole
// dashboard was reloading from scratch constantly) even though nothing
// about the page actually needed to look like a fresh load — the data
// underneath was the only thing that needed to change. `loading` now
// stays reserved for the real first mount, where a skeleton is correct.
async function load(background = false) {
  if (!background) loading.value = true;
  const results = await Promise.allSettled([
    DataService.get('/dashboard/widget/system-stat'),
    DataService.get('/component/events/severity-stat'),
    DataService.get('/dashboard/widget/ont-statuses'),
    DataService.get('/dashboard/widget/error-calling-by-device'),
    DataService.get('/dashboard/latest-system-actions'),
    DataService.get('/component/pinger/device-status-stat'),
    DataService.get('/component/analytics/widgets/bad-signals'),
    DataService.get('/component/events/count-by-name'),
    DataService.post('/component/analytics/bars/ont-levels', {}),
    DataService.get('/component/onts_registration/unregistered', { from: 'store' }),
    DataService.post('/component/events', {
      device_id: 0,
      key: '',
      labels: {},
      limit: 8,
      name: '',
      severity: '',
      not_resolved: true,
      page: 1,
    }),
  ]);
  const [
    sysRes,
    sevRes,
    ontRes,
    errRes,
    actRes,
    pingerRes,
    badSignalRes,
    eventsByNameRes,
    ontLevelsRes,
    unregRes,
    eventsTableRes,
  ] = results;
  if (sysRes.status === 'fulfilled') systemStat.value = sysRes.value.data.data;
  if (sevRes.status === 'fulfilled') severityStat.value = sevRes.value.data.data;
  if (ontRes.status === 'fulfilled') ontStatuses.value = ontRes.value.data.data;
  if (errRes.status === 'fulfilled') errorCalling.value = errRes.value.data.data;
  if (actRes.status === 'fulfilled') latestActions.value = (actRes.value.data.data || []).slice(0, 10);
  if (pingerRes.status === 'fulfilled') pingerStat.value = pingerRes.value.data.data;
  if (badSignalRes.status === 'fulfilled') badSignalCount.value = badSignalRes.value.data.data.count;
  if (eventsByNameRes.status === 'fulfilled') eventsByName.value = eventsByNameRes.value.data.data || [];
  if (ontLevelsRes.status === 'fulfilled') ontLevelsBar.value = ontLevelsRes.value.data.data;
  if (unregRes.status === 'fulfilled') unregisteredOnts.value = unregRes.value.data.data || [];
  if (eventsTableRes.status === 'fulfilled') eventsTable.value = eventsTableRes.value.data.data || [];
  if (!background) loading.value = false;
}

// --- Auto-refresh ---
const refreshOptions = [
  { value: 0, label: 'Off' },
  { value: 30, label: '30s' },
  { value: 60, label: '1m' },
  { value: 300, label: '5m' },
  { value: 900, label: '15m' },
];
const refreshSeconds = ref(60);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

function refreshNow() {
  // Both callers (the timer below and the WS debounce further down) are
  // automatic, not a user clicking a "refresh" button — there isn't one —
  // so this is always a background update: data changes in place, no
  // skeleton flash. The real first load in onMounted is the only
  // non-background call.
  load(true);
  // Auto-refresh implies the window keeps sliding forward to "now" rather
  // than staying pinned to whenever the page happened to load.
  rangeTo.value = Date.now();
  loadTrends(true);
}

function applyRefreshInterval() {
  if (refreshTimer) {
    clearInterval(refreshTimer);
    refreshTimer = null;
  }
  if (refreshSeconds.value > 0) {
    refreshTimer = setInterval(refreshNow, refreshSeconds.value * 1000);
  }
}
watch(refreshSeconds, applyRefreshInterval);

// Real-time: covers the whole system, so this is deliberately independent
// of the "Update interval" dropdown above (including its "off" setting) —
// a real change refreshes the dashboard immediately either way. Debounced
// since alerts/poller cycles/actions can arrive in bursts.
const wsRefreshDebounced = debounce(() => refreshNow(), 3000);
const unsubPoller = wsClient.subscribe('event:poller:finished', () => wsRefreshDebounced.call());
const unsubAction = wsClient.subscribe('event:sys_action:added', () => wsRefreshDebounced.call());
const unsubWebhook = wsClient.subscribe('event:webhook:alertmanager', () => wsRefreshDebounced.call());
const unsubDeviceAdded = wsClient.subscribe('event:device:added', () => wsRefreshDebounced.call());
const unsubDeviceUpdated = wsClient.subscribe('event:device:updated', () => wsRefreshDebounced.call());
const unsubDeviceDeleted = wsClient.subscribe('event:device:deleted', () => wsRefreshDebounced.call());

onMounted(() => {
  load();
  loadTrends();
  applyRefreshInterval();
});
onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer);
  wsRefreshDebounced.cancel();
  unsubPoller();
  unsubAction();
  unsubWebhook();
  unsubDeviceAdded();
  unsubDeviceUpdated();
  unsubDeviceDeleted();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }]" class="ninjadash-page-header-main">
    <template #buttons>
      <div class="refresh-control">
        <span>Update interval</span>
        <a-select v-model:value="refreshSeconds" size="small" style="width: 90px">
          <a-select-option v-for="opt in refreshOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</a-select-option>
        </a-select>
      </div>
    </template>
  </sdPageHeader>
  <Main>
    <!-- Row 1: Pinger | ONT statuses | ONT signal bar | Bad ONT signal -->
    <a-row :gutter="25">
      <a-col :xxl="6" :lg="12" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Pinger (ICMP)">
          <a-skeleton v-if="loading" active />
          <template v-else-if="pingerStat">
            <apexchart type="donut" height="260" :options="pingerApexOptions" :series="pingerApexSeries"></apexchart>
            <div class="dash-pinger-legend">
              <span class="dash-pill dash-pill--up">Up: {{ pingerUp }}</span>
              <span class="dash-pill dash-pill--down">Down: {{ pingerDown }}</span>
            </div>
          </template>
          <a-empty v-else description="Pinger not available" />
        </sdCards>
      </a-col>

      <a-col :xxl="6" :lg="12" :xs="24" style="margin-bottom: 25px">
        <sdCards title="ONT statuses">
          <a-skeleton v-if="loading" active />
          <template v-else-if="ontStatuses">
            <GChart type="PieChart" :data="ontStatusesGoogleData" :options="ontStatusesGoogleOptions" style="height: 260px; width: 100%" />
            <div class="dash-pinger-legend dash-pinger-legend--wrap">
              <span v-for="row in ontStatusRows" :key="row.label" class="dash-pill" :style="{ background: row.color, color: '#fff' }">
                {{ row.label }}: {{ row.count }}
              </span>
            </div>
          </template>
          <a-empty v-else description="No ONT interfaces found" />
        </sdCards>
      </a-col>

      <a-col :xxl="8" :lg="16" :xs="24" style="margin-bottom: 25px">
        <sdCards title="ONTs signal strength">
          <a-skeleton v-if="loading" active />
          <apexchart
            v-else-if="ontLevelsBar"
            type="bar"
            height="230"
            :options="ontLevelsApexOptions"
            :series="ontLevelsApexSeries"
          ></apexchart>
          <a-empty v-else description="No signal data" />
        </sdCards>
      </a-col>

      <a-col :xxl="4" :lg="8" :xs="24" style="margin-bottom: 25px">
        <sdCards :headless="true" class="dash-solid-stat dash-solid-stat--danger" @click="goTo('analytics-ont-level-strength')">
          <a-skeleton v-if="loading" :paragraph="false" active />
          <template v-else>
            <h2 class="dash-solid-stat__value">{{ badSignalCount ?? '—' }}</h2>
            <p class="dash-solid-stat__label">ONTs with bad signal</p>
          </template>
        </sdCards>
        <sdCards title="Events stat" class="dash-events-stat-card" style="margin-top: 25px">
          <a-skeleton v-if="loading" active />
          <div v-else class="dash-sev-stack">
            <div v-for="card in severityCards" :key="card.key" class="sev-block" :class="card.class" @click="goTo('events')">
              <span class="sev-block__value">{{ card.value }}</span>
              <span class="sev-block__label">{{ card.label }}</span>
            </div>
          </div>
        </sdCards>
      </a-col>
    </a-row>

    <!-- Time-range control for the two date/time trend charts below -->
    <a-row :gutter="25">
      <a-col :span="24">
        <div class="range-bar">
          <div class="range-bar__dates">
            <unicon name="calendar-alt"></unicon>
            <span>{{ rangeFromLabel }}</span>
            <unicon name="arrow-right"></unicon>
            <span>{{ rangeToLabel }}</span>
          </div>
          <div class="range-bar__controls">
            <button
              type="button"
              class="range-bar__btn"
              :disabled="!canZoomOut || trendLoading"
              title="Zoom out (longer range, up to 15d)"
              @click="zoomOut"
            >
              −
            </button>
            <span class="range-bar__label">{{ selectedRange.label }}</span>
            <button
              type="button"
              class="range-bar__btn"
              :disabled="!canZoomIn || trendLoading"
              title="Zoom in (shorter range, down to 5m)"
              @click="zoomIn"
            >
              +
            </button>
            <a-spin v-if="trendLoading" size="small" style="margin-left: 8px" />
          </div>
        </div>
      </a-col>
    </a-row>

    <!-- Row 2: Online ONTs chart | Online devices -->
    <a-row :gutter="25">
      <a-col :xxl="12" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Online ONTs chart">
          <a-skeleton v-if="trendLoading && !ontStatusesTrend" active />
          <div v-else-if="ontStatusesTrend" class="trend-chart-wrap">
            <HeartbeatAreaChart :chart="ontStatusesTrend" />
          </div>
          <a-empty v-else description="No trend data" />
        </sdCards>
      </a-col>

      <a-col :xxl="12" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Online devices">
          <a-skeleton v-if="trendLoading && !deviceStatusesTrend" active />
          <div v-else-if="deviceStatusesTrend" class="trend-chart-wrap">
            <HeartbeatAreaChart :chart="deviceStatusesTrend" />
          </div>
          <a-empty v-else description="No trend data" />
        </sdCards>
      </a-col>
    </a-row>

    <!-- Row 3: Error device calling | Last user activity -->
    <a-row :gutter="25">
      <a-col :xxl="11" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Error device calling (last day)">
          <a-skeleton v-if="loading" active />
          <template v-else-if="errorCalling">
            <a-row :gutter="16" style="margin-bottom: 16px">
              <a-col :span="12">
                <div class="dash-mini-stat">
                  <span class="dash-mini-stat__value dash-mini-stat__value--danger">{{ errorCalling.errors_count }}</span>
                  <span class="dash-mini-stat__label">Errors count</span>
                </div>
              </a-col>
              <a-col :span="12">
                <div class="dash-mini-stat">
                  <span class="dash-mini-stat__value">{{ errorCalling.not_responding_count }}</span>
                  <span class="dash-mini-stat__label">Not respond count</span>
                </div>
              </a-col>
            </a-row>
            <p class="dash-subtitle">Top device by errors</p>
            <a-table
              v-if="errorCalling.data.length"
              :data-source="errorCalling.data"
              :pagination="false"
              row-key="device.id"
              size="small"
              :scroll="{ x: 500 }"
            >
              <a-table-column title="Device" data-index="device">
                <template #default="{ record }">
                  <router-link :to="{ name: 'device-detail', params: { id: record.device.id } }">
                    {{ record.device.ip }} - {{ record.device.name }}
                  </router-link>
                </template>
              </a-table-column>
              <a-table-column title="Errors count" data-index="errors_count" :width="140" />
              <a-table-column title="Not respond count" data-index="not_responding_count" :width="160" />
            </a-table>
            <a-empty v-else description="No device calling errors right now" />
          </template>
        </sdCards>
      </a-col>

      <a-col :xxl="13" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Last user activity">
          <a-skeleton v-if="loading" active />
          <a-table
            v-else-if="latestActions.length"
            :data-source="latestActions"
            :pagination="false"
            row-key="id"
            size="small"
            :scroll="{ x: 760 }"
          >
            <a-table-column title="Time" data-index="created_at" :width="180">
              <template #default="{ record }">
                <span :title="record.created_at">{{ dayjs(record.created_at).fromNow() }}</span>
              </template>
            </a-table-column>
            <a-table-column title="Action" data-index="action" :width="150" />
            <a-table-column title="User" :width="120">
              <template #default="{ record }">{{ record.user?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Message" data-index="message" />
            <a-table-column title="Status" data-index="status" :width="100">
              <template #default="{ record }">
                <span class="status-badge" :class="record.status === 'SUCCESS' ? 'status-badge--ok' : 'status-badge--fail'">
                  {{ record.status }}
                </span>
              </template>
            </a-table-column>
          </a-table>
          <a-empty v-else description="No recent activity" />
        </sdCards>
      </a-col>
    </a-row>

    <!-- Row 4: Unregistered ONTs + Count events by name (stacked, left) | Events table (right, 2:1) -->
    <a-row :gutter="25">
      <a-col :xxl="8" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Unregistered ONTs" style="margin-bottom: 25px">
          <a-skeleton v-if="loading" active />
          <template v-else-if="unregisteredOnts.length">
            <ul class="dash-plain-list">
              <li v-for="(item, i) in unregisteredOnts" :key="i">{{ item.serial || item.ident || item.mac_address || JSON.stringify(item) }}</li>
            </ul>
          </template>
          <div v-else class="dash-empty-state">
            <unicon name="wifi-slash"></unicon>
            <p>Unregistered ONTs not found</p>
          </div>
        </sdCards>

        <sdCards title="Count events by name (last day)">
          <a-skeleton v-if="loading" active />
          <a-table
            v-else-if="eventsByName.length"
            :data-source="eventsByName"
            :pagination="false"
            row-key="name"
            size="small"
            :scroll="{ x: 420 }"
          >
            <a-table-column title="Event name" data-index="name">
              <template #default="{ record }"><code>{{ record.name }}</code></template>
            </a-table-column>
            <a-table-column title="Severity" :width="120">
              <template #default="{ record }">
                <span class="sev-tag" :class="severityMeta[record.severity]?.class">{{ record.severity }}</span>
              </template>
            </a-table-column>
            <a-table-column title="Count" data-index="count" :width="90" />
          </a-table>
          <a-empty v-else description="No events recorded" />
        </sdCards>
      </a-col>

      <a-col :xxl="16" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Events table">
          <template v-if="unresolvedCount" #button>
            <sdButton type="danger" size="small" :loading="resolvingAll" :disabled="resolvingAll" @click="confirmResolveAll">
              Resolve all
            </sdButton>
          </template>
          <a-skeleton v-if="loading" active />
          <ul v-else-if="eventsTable.length" class="dash-events-table">
            <li v-for="ev in eventsTable" :key="ev.id" class="dash-events-table__row" :class="severityMeta[ev.severity]?.class">
              <div class="dash-events-table__head">
                <span class="dash-events-table__title">{{ ev.annotation }}</span>
                <span class="dash-events-table__time">{{ dayjs(ev.created_at).fromNow() }}</span>
                <span class="dash-events-table__resolve" :class="ev.resolved_at ? 'is-resolved' : 'is-open'">
                  {{ ev.resolved_at ? 'Resolved' : 'Not resolved' }}
                </span>
              </div>
              <div class="dash-events-table__meta">
                <span class="sev-tag" :class="severityMeta[ev.severity]?.class">{{ ev.severity }}</span>
                <router-link v-if="ev.device" :to="{ name: 'device-detail', params: { id: ev.device.id } }">
                  {{ ev.device.ip }}
                </router-link>
                <span v-if="ev.labels?.iface_name">{{ ev.labels.iface_name }}</span>
              </div>
              <p class="dash-events-table__desc">{{ ev.description }}</p>
              <sdButton
                v-if="!ev.resolved_at"
                type="light"
                size="small"
                class="dash-events-table__resolve-btn"
                :loading="resolvingId === ev.id"
                :disabled="resolvingId === ev.id || resolvingAll"
                @click="confirmResolve(ev)"
              >
                <unicon name="check"></unicon> Resolve
              </sdButton>
            </li>
          </ul>
          <a-empty v-else description="No events" />
        </sdCards>
      </a-col>
    </a-row>

    <!-- Row 6: System stat -->
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 25px">
        <sdCards title="System stat">
          <a-skeleton v-if="loading" active />
          <!-- Was a narrow stacked list in a half-width column, leaving the
               rest of the row blank on desktop — now a horizontal row of
               stat blocks spanning the full width, matching the mini-stat
               style already used elsewhere on this dashboard. -->
          <div v-else class="dash-system-stat">
            <div
              v-for="row in systemStatRows"
              :key="row.key"
              class="dash-system-stat__item"
              :class="{ clickable: !!row.route }"
              @click="goTo(row.route)"
            >
              <unicon :name="row.icon" class="dash-system-stat__icon"></unicon>
              <span class="dash-system-stat__value">{{ row.value ?? '—' }}</span>
              <span class="dash-system-stat__label">{{ row.label }}</span>
            </div>
          </div>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
/* Was a fixed 230px — bumped up and made to scale with viewport width so it
   keeps growing on wider screens instead of staying pinned to one height,
   while clamp()'s min/max keep it from ever going too short (mobile) or
   comically tall (ultra-wide). */
.trend-chart-wrap {
  height: clamp(340px, 34vw, 520px);
}
/* Card chrome for the two trend charts matches every other dashboard card
   (white background, dark heading) — only the trace lines themselves keep
   the glowing "heart monitor" look, via HeartbeatAreaChart.vue's own
   colors tuned for a light card. */
.dash-pinger-legend {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-top: 12px;
  flex-wrap: wrap;
}
.dash-pinger-legend--wrap {
  padding: 0 8px;
}
.dash-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 12px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  border: 1px solid rgba(255, 255, 255, 0.35);
}
.dash-pill--up {
  background: rgba(5, 100, 0, 0.9);
  color: #fff;
}
.dash-pill--down {
  background: rgba(130, 0, 0, 0.9);
  color: #fff;
}

.dash-solid-stat {
  cursor: pointer;
  text-align: center;
  border-radius: 10px;
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}
.dash-solid-stat:hover {
  transform: translateY(-2px);
}
.dash-solid-stat :deep(.ant-card-body) {
  padding: 22px 14px;
  border-radius: 10px;
}
.dash-solid-stat--danger :deep(.ant-card-body) {
  background: linear-gradient(135deg, #a60a0a, #7a0000);
}
.dash-solid-stat--muted :deep(.ant-card-body) {
  background: linear-gradient(135deg, #37415c, #272b41);
}
.dash-solid-stat__value {
  /* CardFrame's own styled-component forces `.ant-card-body h1..h6 { color:
     dark-text }` with higher specificity than a plain scoped class here
     (its selector chain carries an extra class), so it silently wins over
     this rule on the <h2> — force it back to white explicitly. */
  margin: 0;
  font-size: 34px;
  font-weight: 800;
  color: #fff !important;
  line-height: 1.1;
}
.dash-solid-stat__label {
  margin: 6px 0 0;
  font-size: 12px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.85);
}

.dash-events-stat-card :deep(.ant-card-body) {
  padding: 0;
}
.dash-sev-stack {
  display: flex;
  flex-direction: column;
}
.sev-block {
  padding: 18px 16px;
  cursor: pointer;
  text-align: center;
  border-top: 1px solid rgba(255, 255, 255, 0.18);
}
.sev-block:first-child {
  border-top: none;
}
.sev-block__value {
  display: block;
  font-size: 26px;
  font-weight: 800;
  color: #fff;
}
.sev-block__label {
  display: block;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.85);
  margin-top: 2px;
}
.sev-block--info {
  background: #1868db;
}
.sev-block--warning {
  background: #d4a017;
}
.sev-block--critical {
  background: #a60a0a;
}

.dash-mini-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 12px 8px;
  border: 1px solid #eceef4;
  border-radius: 8px;
}
.dash-mini-stat__value {
  font-size: 26px;
  font-weight: 800;
  color: #272b41;
}
.dash-mini-stat__value--danger {
  color: #e5484d;
}
.dash-mini-stat__label {
  font-size: 12px;
  color: #8c90a4;
  margin-top: 2px;
}
.dash-subtitle {
  font-size: 13px;
  font-weight: 600;
  color: #4b5069;
  margin: 4px 0 8px;
}

.status-badge {
  display: inline-block;
  padding: 1px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.status-badge--ok {
  background: rgba(38, 179, 87, 0.12);
  color: #1a9c50;
}
.status-badge--fail {
  background: rgba(255, 77, 79, 0.12);
  color: #e5484d;
}

.dash-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 48px 20px;
  color: #8c90a4;
}
.dash-empty-state :deep(svg) {
  width: 28px;
  height: 28px;
}
.dash-plain-list {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
  color: #4b5069;
}

.sev-tag {
  display: inline-block;
  padding: 1px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.sev-tag.sev-info {
  background: rgba(24, 104, 219, 0.12);
  color: #1868db;
}
.sev-tag.sev-warning {
  background: rgba(212, 160, 23, 0.15);
  color: #ab7d0a;
}
.sev-tag.sev-critical {
  background: rgba(166, 10, 10, 0.12);
  color: #a60a0a;
}

.dash-events-table {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 520px;
  overflow-y: auto;
}
.dash-events-table__row {
  padding: 12px 14px;
  border-left: 4px solid #d4a017;
  border-bottom: 1px solid #f0f1f5;
  background: #fafbfc;
  margin-bottom: 8px;
  border-radius: 0 6px 6px 0;
}
.dash-events-table__row.sev-critical {
  border-left-color: #a60a0a;
}
.dash-events-table__row.sev-info {
  border-left-color: #1868db;
}
.dash-events-table__head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.dash-events-table__title {
  font-weight: 700;
  font-size: 13px;
  color: #272b41;
}
.dash-events-table__time {
  font-size: 12px;
  color: #8c90a4;
}
.dash-events-table__resolve {
  margin-left: auto;
  font-size: 11px;
  font-weight: 700;
}
.dash-events-table__resolve.is-open {
  color: #a60a0a;
}
.dash-events-table__resolve.is-resolved {
  color: #1a9c50;
}
.dash-events-table__meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
  font-size: 12px;
}
.dash-events-table__desc {
  margin: 6px 0 0;
  font-size: 12px;
  color: #4b5069;
}
.dash-events-table__resolve-btn {
  margin-top: 8px;
}
.dash-events-table__resolve-btn :deep(svg) {
  width: 12px;
  height: 12px;
  margin-right: 4px;
}

.dash-system-stat {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}
.dash-system-stat__item {
  flex: 1 1 150px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 4px;
  padding: 18px 12px;
  border: 1px solid #eceef4;
  border-radius: 8px;
}
.dash-system-stat__item.clickable {
  cursor: pointer;
}
.dash-system-stat__item.clickable:hover {
  border-color: #1868db;
}
.dash-system-stat__item.clickable:hover .dash-system-stat__value,
.dash-system-stat__item.clickable:hover .dash-system-stat__icon {
  color: #1868db;
}
.dash-system-stat__icon {
  width: 18px;
  height: 18px;
  color: #8c90a4;
}
.dash-system-stat__value {
  font-size: 22px;
  font-weight: 800;
  color: #272b41;
}
.dash-system-stat__label {
  font-size: 12px;
  color: #8c90a4;
}

/* Every widget card on this page — a visible border reads as more defined
   than the template's default shadow-only card, especially with this many
   boxes packed together. */
:deep(.ant-card) {
  border: 1px solid #e6e9f1;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
}
:deep(.ant-card:hover) {
  border-color: #d7dce8;
}

.range-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 18px;
  margin-bottom: 25px;
  background: #fff;
  border: 1px solid #e6e9f1;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
}
.range-bar__dates {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #4b5069;
}
.range-bar__dates :deep(svg) {
  width: 14px;
  height: 14px;
  color: #8c90a4;
}
.range-bar__controls {
  display: flex;
  align-items: center;
  gap: 10px;
}
.range-bar__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  border: 1px solid #dde1ec;
  background: #f8f9fb;
  color: #1868db;
  font-size: 18px;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
  transition: background 0.15s ease;
}
.range-bar__btn:hover:not(:disabled) {
  background: rgba(24, 104, 219, 0.1);
}
.range-bar__btn:disabled {
  color: #c4c9d6;
  cursor: not-allowed;
}
.range-bar__label {
  min-width: 40px;
  text-align: center;
  font-size: 13px;
  font-weight: 700;
  color: #272b41;
}

.refresh-control {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #8c90a4;
}
</style>
