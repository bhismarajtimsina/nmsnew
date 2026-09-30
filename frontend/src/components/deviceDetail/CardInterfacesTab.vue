<!--
  The OLT dashboard's "card_interfaces" tab: the physical (uplink/PON)
  ports on the OLT. Reverse engineered from CardInterfacesComponent in
  DeviceInfo-CGuBjiZ6.js. Chassis-based models (this system's Huawei OLTs)
  report `card_list`/`card_status` module support, so their ports are
  grouped into collapsible per-slot cards showing that slot's model,
  operational/admin status, CPU load and temperature — matching the real
  per-slot cards view exactly. Single-board models (this system's BDCOM
  OLT) don't support those modules, so — matching the original's own
  fallback for that case — ports render as one flat table.
-->
<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { wsClient } from '@/services/wsClient';
import type { DeviceCalling } from '@/composables/useDeviceCalling';
import { loadColor as cpuColor, tempColor } from '@/utility/opticalColors';
import PhysicalPortsTable from '@/components/deviceDetail/PhysicalPortsTable.vue';
import PrometheusChartModal from '@/components/deviceDetail/PrometheusChartModal.vue';
import LiveTrafficModal from '@/components/deviceDetail/LiveTrafficModal.vue';

const props = defineProps<{ deviceId: number; deviceCalling: DeviceCalling; modules: string[] }>();
const emit = defineEmits<{ (e: 'go-to-tree', search: string): void }>();

interface PhysicalPort {
  interface: { id: number; name: string; type: string; parent?: number; _shelf?: string | number; _slot?: string | number };
  oper_status: string;
  nway_status: string;
  description: string;
  counters: { in_errors: string; out_errors: string; in_octets: string; out_octets: string } | null;
  onts: { count: number; online: number; offline: number } | null;
  optical: { tx_power?: number | null; rx_power?: number | null; tx?: number | null; temp?: number | null; vcc?: number | null } | null;
}
interface OntRow {
  interface: { parent: number };
  status: string;
}
interface Card {
  id: number;
  shelf: number;
  slot: number;
  cfg_type: string;
  admin_status?: string;
  _admin_status?: string;
  oper_status?: string;
  _oper_status?: string;
  cpu_load?: string;
  temperature?: string;
}

const loading = ref(true);
const errorMessage = ref('');
const rawInterfaces = ref<PhysicalPort[]>([]);
const onts = ref<OntRow[]>([]);
const cards = ref<Card[]>([]);

function hasModule(name: string) {
  return props.modules.includes(name);
}

const interfaces = computed<PhysicalPort[]>(() => {
  const ontsByParent = new Map<number, { count: number; online: number; offline: number }>();
  onts.value.forEach((o) => {
    const parent = o.interface?.parent;
    if (!parent) return;
    const stat = ontsByParent.get(parent) || { count: 0, online: 0, offline: 0 };
    stat.count++;
    if (o.status === 'Online') stat.online++;
    else stat.offline++;
    ontsByParent.set(parent, stat);
  });
  return rawInterfaces.value.map((i) => ({ ...i, onts: i.interface.type === 'PON' ? ontsByParent.get(i.interface.id) || null : null }));
});

const hasCards = computed(() => hasModule('card_list') && cards.value.length > 0);
const expandedKeys = ref<string[]>([]);
function cardInterfaces(card: Card) {
  return interfaces.value.filter((i) => String(i.interface._shelf) === String(card.shelf) && String(i.interface._slot) === String(card.slot));
}
function cardStatColor(card: Card) {
  if (card.oper_status === 'hwOffline') return '#8F0000';
  return '#0E4D00';
}

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  errorMessage.value = '';
  cards.value = [];
  try {
    if (hasModule('card_list')) {
      const { data } = await DataService.get(`/component/olts/cards/${props.deviceId}`, { from });
      props.deviceCalling.setMeta(data.meta);
      const byKey = new Map<string, Card>();
      (data.data || []).forEach((c: Card) => byKey.set(`${c.shelf}/${c.slot}`, { ...c }));
      if (hasModule('card_status')) {
        const status = await DataService.get(`/component/olts/cards/status/${props.deviceId}`, { from });
        props.deviceCalling.setMeta(status.data.meta);
        (status.data.data || []).forEach((s: Card) => {
          const existing = byKey.get(`${s.shelf}/${s.slot}`);
          if (existing) Object.assign(existing, s);
        });
      }
      cards.value = Array.from(byKey.values());
      expandedKeys.value = cards.value.map((c) => `${c.shelf}/${c.slot}`);
    }
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || err.message;
  }
  try {
    const { data } = await DataService.get(`/component/olts/interfaces/physical/${props.deviceId}`, { from });
    rawInterfaces.value = data.data || [];
    props.deviceCalling.setMeta(data.meta);
  } catch (err: any) {
    if (err?.response?.data?.error) errorMessage.value = err.response.data.error.description;
    else errorMessage.value = err.message;
  }
  try {
    const { data } = await DataService.get(`/component/olts/interfaces/onts/${props.deviceId}`, { from });
    onts.value = data.data || [];
  } catch {
    onts.value = [];
  }
  loading.value = false;
}

function goToTree(search: string) {
  emit('go-to-tree', search);
}

const chartModalRef = ref<InstanceType<typeof PrometheusChartModal> | null>(null);
const liveTrafficRef = ref<InstanceType<typeof LiveTrafficModal> | null>(null);
function showChart(iface: PhysicalPort) {
  chartModalRef.value?.open('Traffic counters chart', '/component/prometheus_wrapper/chart-traffic-counter-series', { device_id: props.deviceId, interface_id: iface.interface.id }, { unit: 'bitrate' });
}
function showLiveTraffic(iface: PhysicalPort) {
  liveTrafficRef.value?.open(props.deviceId, iface.interface.id, iface.interface.name);
}

defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: a poller cycle finishing for THIS device means these cards
// are likely stale — quietly re-pull from cache (not a live device query).
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});
onBeforeUnmount(() => unsubPoller());
</script>

<template>
  <div>
    <a-alert v-if="errorMessage" type="error" :message="errorMessage" show-icon style="margin-bottom: 12px" />
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!interfaces.length" description="No physical ports found" />

    <a-collapse v-else-if="hasCards" v-model:activeKey="expandedKeys" :bordered="false" ghost class="ci-collapse">
      <a-collapse-panel v-for="card in cards" :key="`${card.shelf}/${card.slot}`">
        <template #header>
          <div class="ci-card__header">
            <span class="ci-card__slot">{{ card.shelf }}/{{ card.slot }}<small>{{ card.cfg_type }}</small></span>
            <span class="ci-card__status">
              <strong :style="{ color: cardStatColor(card) }">{{ card.oper_status }}</strong> <small v-if="card._oper_status">({{ card._oper_status }})</small><br />
              <span class="ci-card__status-muted">{{ card.admin_status }} <small v-if="card._admin_status">({{ card._admin_status }})</small></span>
            </span>
            <span v-if="card.oper_status !== 'hwOffline'" class="ci-card__metrics">
              <span v-if="card.cpu_load != null" class="ci-card__badge" :style="{ background: cpuColor(Number(card.cpu_load)) }"><unicon name="processor" width="12" height="12"></unicon> {{ card.cpu_load }}%</span>
              <span v-if="card.temperature != null" class="ci-card__badge" :style="{ background: tempColor(Number(card.temperature)) }"><unicon name="temperature-half" width="12" height="12"></unicon> {{ card.temperature }} °C</span>
            </span>
          </div>
        </template>
        <PhysicalPortsTable :device-id="deviceId" :interfaces="cardInterfaces(card)" @go-to-tree="goToTree" @show-chart="showChart" @show-live-traffic="showLiveTraffic" />
      </a-collapse-panel>
    </a-collapse>

    <PhysicalPortsTable v-else :device-id="deviceId" :interfaces="interfaces" @go-to-tree="goToTree" @show-chart="showChart" @show-live-traffic="showLiveTraffic" />

    <PrometheusChartModal ref="chartModalRef" />
    <LiveTrafficModal ref="liveTrafficRef" />
  </div>
</template>

<style scoped>
.ci-collapse :deep(.ant-collapse-item) {
  background: #fafbfe;
  border: 1px solid #eef0f6;
  border-radius: 10px !important;
  margin-bottom: 10px;
  overflow: hidden;
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.ci-collapse :deep(.ant-collapse-item:hover) {
  border-color: #d6ddf0;
  box-shadow: 0 2px 10px rgba(24, 104, 219, 0.08);
}
.ci-collapse :deep(.ant-collapse-header) {
  padding: 12px 16px !important;
  align-items: center !important;
}
.ci-collapse :deep(.ant-collapse-content-box) {
  padding: 0 4px 14px !important;
}
.ci-card__header {
  display: flex;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
  width: 100%;
}
.ci-card__slot {
  font-weight: 700;
  font-size: 15px;
  color: #272b41;
  min-width: 90px;
}
.ci-card__slot small {
  display: block;
  font-weight: 400;
  font-size: 11px;
  color: #8c90a4;
}
.ci-card__status {
  font-size: 12.5px;
  min-width: 140px;
}
.ci-card__status-muted {
  color: #8c90a4;
}
.ci-card__metrics {
  display: flex;
  gap: 8px;
  margin-left: auto;
}
.ci-card__badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}
</style>
