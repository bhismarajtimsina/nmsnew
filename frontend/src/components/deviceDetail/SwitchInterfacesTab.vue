<!--
  Interfaces tab for a plain SWITCH-type device (as opposed to an OLT) —
  reverse-engineered from the real, previously-unused `Switches` component
  API (`GET /component/switches/interfaces/{device}`), which bundles
  interfaces_list + link_info + counters + description + sfp_optical in
  one call. Built after a real BDCOM S5612 switch hit a 500 the moment it
  was added, since the OLT-only tabs (ONTs tree/Physical ports) were being
  shown — and called — for every device regardless of type. Feature set
  (optical column, errors badge, live-traffic/history-chart buttons)
  mirrors PhysicalPortsTable.vue (the OLT's own physical-port table) for
  parity between device types.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { formatBytes } from '@/utility/formatters';
import { signalColor } from '@/utility/opticalColors';
import PrometheusChartModal from '@/components/deviceDetail/PrometheusChartModal.vue';
import LiveTrafficModal from '@/components/deviceDetail/LiveTrafficModal.vue';

const props = defineProps<{ deviceId: number }>();

interface SwitchInterface {
  interface: { id: number; name: string; type: string; xid?: string };
  link_info: { oper_status: string; nway_status: string; admin_state: string }[] | null;
  counters: { in_octets?: string; out_octets?: string; in_errors?: string; out_errors?: string } | null;
  description: { description: string } | null;
  optical: { rx_power?: number | null; tx_power?: number | null; temp?: number | null; vcc?: number | null; present?: boolean | null } | null;
}
const rows = ref<SwitchInterface[]>([]);
const loading = ref(true);
const errorMessage = ref('');

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await DataService.get(`/component/switches/interfaces/${props.deviceId}`, { from });
    rows.value = data.data || [];
  } catch (err: any) {
    errorMessage.value = err?.response?.data?.error?.description || 'Could not load interfaces.';
    notification.error({ message: 'Could not load interfaces', description: errorMessage.value });
  } finally {
    loading.value = false;
  }
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: a poller cycle finishing for THIS device means these
// interfaces are likely stale — quietly re-pull from cache (not a live
// device query).
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});
onBeforeUnmount(() => unsubPoller());

function statusColor(status: string | undefined) {
  return status === 'Up' ? 'darkgreen' : status === 'Down' ? '#8c90a4' : '#b37100';
}
function fmtCount(n: string | undefined) {
  const v = Number(n || 0);
  if (v > 1e12) return (v / 1e12).toFixed(2) + 'T';
  if (v > 1e9) return (v / 1e9).toFixed(2) + 'G';
  if (v > 1e6) return (v / 1e6).toFixed(2) + 'M';
  if (v > 1e3) return (v / 1e3).toFixed(2) + 'K';
  return String(v);
}

const chartModalRef = ref<InstanceType<typeof PrometheusChartModal> | null>(null);
const liveTrafficRef = ref<InstanceType<typeof LiveTrafficModal> | null>(null);
function showChart(iface: SwitchInterface) {
  chartModalRef.value?.open(
    'Traffic counters chart',
    '/component/prometheus_wrapper/chart-traffic-counter-series',
    { device_id: props.deviceId, interface_id: iface.interface.id },
    { unit: 'bitrate' },
  );
}
function showLiveTraffic(iface: SwitchInterface) {
  liveTrafficRef.value?.open(props.deviceId, iface.interface.id, iface.interface.name);
}
</script>

<template>
  <div>
    <a-alert v-if="errorMessage" type="error" :message="errorMessage" show-icon style="margin-bottom: 16px" />
    <a-table :data-source="rows" :loading="loading" row-key="interface.id" size="small" :pagination="{ pageSize: 50 }" :scroll="{ x: 1100 }">
      <a-table-column title="Name" :width="120">
        <template #default="{ record }">
          <router-link :to="{ name: 'device-interface-detail', params: { id: props.deviceId, interface: record.interface.id }, query: { type: 'SWITCH' } }">
            <strong>{{ record.interface.name }}</strong>
          </router-link>
        </template>
      </a-table-column>
      <a-table-column title="Description" :width="130" :ellipsis="true">
        <template #default="{ record }">{{ record.description?.description || '-' }}</template>
      </a-table-column>
      <a-table-column title="Status" :width="90">
        <template #default="{ record }">
          <span :style="{ fontWeight: 700, color: statusColor(record.link_info?.[0]?.oper_status) }">
            {{ record.link_info?.[0]?.oper_status || 'N/A' }}
          </span>
        </template>
      </a-table-column>
      <a-table-column title="Admin" :width="90">
        <template #default="{ record }">{{ record.link_info?.[0]?.admin_state || 'N/A' }}</template>
      </a-table-column>
      <a-table-column title="Speed" :width="90">
        <template #default="{ record }">{{ record.link_info?.[0]?.nway_status || 'N/A' }}</template>
      </a-table-column>
      <a-table-column title="Optical" :width="160">
        <template #default="{ record }">
          <template v-if="record.optical">
            <span v-if="record.optical.present === false" class="sit-optical-pill sit-optical-pill--absent">No transceiver</span>
            <div v-else class="sit-optical-group">
              <strong
                v-if="record.optical.rx_power != null"
                class="sit-optical-pill"
                title="RX power"
                :style="{ background: signalColor(record.optical.rx_power) }"
              >
                RX {{ record.optical.rx_power }}dBm
              </strong>
              <strong
                v-if="record.optical.tx_power != null"
                class="sit-optical-pill"
                title="TX power"
                :style="{ background: signalColor(record.optical.tx_power) }"
              >
                TX {{ record.optical.tx_power }}dBm
              </strong>
            </div>
          </template>
        </template>
      </a-table-column>
      <a-table-column title="Errors" :width="100">
        <template #default="{ record }">
          <span
            v-if="record.counters"
            class="sit-badge"
            :style="{ background: record.counters.in_errors !== '0' || record.counters.out_errors !== '0' ? 'darkred' : 'gray' }"
          >
            {{ fmtCount(record.counters.in_errors) }}/{{ fmtCount(record.counters.out_errors) }}
          </span>
        </template>
      </a-table-column>
      <a-table-column title="Counters (in/out)" :width="160">
        <template #default="{ record }">
          <span v-if="record.counters">{{ formatBytes(record.counters.in_octets) }} / {{ formatBytes(record.counters.out_octets) }}</span>
        </template>
      </a-table-column>
      <a-table-column title="" :width="90">
        <template #default="{ record }">
          <a class="sit-icon-btn sit-icon-btn--chart" title="Traffic history" @click="showChart(record)"><unicon name="chart-bar" width="16" height="16"></unicon></a>
          <a class="sit-icon-btn sit-icon-btn--live" title="Live traffic" @click="showLiveTraffic(record)"><unicon name="heart-rate" width="16" height="16"></unicon></a>
        </template>
      </a-table-column>
    </a-table>

    <PrometheusChartModal ref="chartModalRef" />
    <LiveTrafficModal ref="liveTrafficRef" />
  </div>
</template>

<style scoped>
.sit-optical-group {
  display: flex;
  flex-direction: column;
  gap: 3px;
  align-items: flex-start;
}
.sit-optical-pill {
  display: inline-block;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 5px;
  white-space: nowrap;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}
.sit-optical-pill--absent {
  background: #b3b6c4;
  font-weight: 500;
  font-style: italic;
}
.sit-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
}
.sit-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  color: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  transition: transform 0.1s ease, box-shadow 0.1s ease;
}
.sit-icon-btn :deep(svg) {
  fill: #fff !important;
}
.sit-icon-btn + .sit-icon-btn {
  margin-left: 6px;
}
.sit-icon-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.22);
}
.sit-icon-btn--chart {
  background: #1868db;
}
.sit-icon-btn--live {
  background: #30a46c;
  animation: sit-live-pulse 1.8s ease-in-out infinite;
}
@keyframes sit-live-pulse {
  0%,
  100% {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15), 0 0 0 0 rgba(48, 164, 108, 0.5);
  }
  50% {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15), 0 0 0 5px rgba(48, 164, 108, 0);
  }
}
</style>
