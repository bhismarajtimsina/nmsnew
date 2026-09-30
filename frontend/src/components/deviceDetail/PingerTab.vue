<!--
  The OLT dashboard's "pinger" tab: this device's ICMP reachability status,
  availability percentages, and recent down/up log — reverse engineered
  field-for-field from the core-bundled Pinger component (found via its
  `/component/pinger/status|logs/{id}` calls in index-DULVLRUP.js).
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { wsClient } from '@/services/wsClient';

const props = defineProps<{ deviceId: number }>();

interface PingerStatus {
  status: string;
  raw_status: number;
  is_tcp: boolean;
  duration: number;
  last_change: string;
  availability?: { '24h': number; '7d': number; '30d': number };
}
interface DownLog {
  id: number;
  start: string;
  stop: string;
  duration_sec: number;
}

const loading = ref(true);
const status = ref<PingerStatus | null>(null);
const logs = ref<DownLog[]>([]);

function humanizeDuration(seconds: number) {
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
  return `${Math.floor(seconds / 86400)}d ${Math.floor((seconds % 86400) / 3600)}h`;
}

async function loadInfo() {
  loading.value = true;
  try {
    const { data } = await DataService.get(`/component/pinger/status/${props.deviceId}`);
    status.value = data.data;
  } catch {
    status.value = null;
  }
  if (status.value) {
    try {
      const { data } = await DataService.get(`/component/pinger/logs/${props.deviceId}`);
      logs.value = data.data || [];
    } catch {
      logs.value = [];
    }
  }
  loading.value = false;
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: fires only on an actual up/down transition for this device
// (not on every ping check), so no debounce needed here.
const unsubPinger = wsClient.subscribe('event:pinger:host-status-changed', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo();
});
onBeforeUnmount(() => unsubPinger());
</script>

<template>
  <div class="pinger-tab">
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!status" description="Info not available" />
    <a-row v-else :gutter="20">
      <a-col :xs="24" :md="logs.length ? 10 : 24">
        <h4>
          Status:
          <span :style="{ color: status.status === 'Up' ? 'darkgreen' : 'darkred' }">{{ status.status }}</span>
        </h4>
        <a-divider style="margin: 8px 0" />

        <template v-if="status.availability">
          <h4>Availability</h4>
          <a-row class="pinger-avail">
            <a-col :span="8">
              <div>24h</div>
              <div class="pinger-avail__value">{{ status.availability['24h'] }}%</div>
            </a-col>
            <a-col :span="8">
              <div>7d</div>
              <div class="pinger-avail__value">{{ status.availability['7d'] }}%</div>
            </a-col>
            <a-col :span="8">
              <div>30d</div>
              <div class="pinger-avail__value">{{ status.availability['30d'] }}%</div>
            </a-col>
          </a-row>
          <a-divider style="margin: 8px 0" />
        </template>

        <table class="pinger-info">
          <tbody>
            <tr>
              <td>{{ status.status === 'Up' ? 'Online from' : 'Offline from' }}:</td>
              <td><b>{{ status.last_change }}</b><br /><small>{{ humanizeDuration(status.duration) }}</small></td>
            </tr>
            <tr v-if="status.status === 'Up'">
              <td>Packet loss:</td>
              <td>
                <span v-if="status.raw_status > 0 && status.raw_status < 100">Yes ({{ 100 - status.raw_status }}%)</span>
                <span v-else-if="status.raw_status === 100">No</span>
                <span v-else-if="status.raw_status === 999">Only TCP</span>
              </td>
            </tr>
          </tbody>
        </table>
      </a-col>

      <a-col v-if="logs.length" :xs="24" :md="14">
        <h4>Down logs</h4>
        <a-divider style="margin: 8px 0" />
        <a-table :data-source="logs" row-key="id" size="small" :pagination="{ pageSize: 10 }">
          <a-table-column title="Down" data-index="start" />
          <a-table-column title="Up" data-index="stop" />
          <a-table-column title="Duration">
            <template #default="{ record }">{{ humanizeDuration(record.duration_sec) }}</template>
          </a-table-column>
        </a-table>
      </a-col>
    </a-row>
  </div>
</template>

<style scoped>
.pinger-avail {
  text-align: center;
}
.pinger-avail__value {
  font-size: 110%;
  font-weight: 700;
}
.pinger-info td {
  padding: 4px 8px 4px 0;
  vertical-align: top;
  font-size: 13px;
}
</style>
