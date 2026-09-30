<!--
  The OLT dashboard's "history_log" tab: this device's interface up/down
  history feed, most recent first. Reverse engineered from
  InterfacesHistoryLog-e1WLEL4q.js.
-->
<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { wsClient } from '@/services/wsClient';

const props = defineProps<{ deviceId: number }>();

interface HistoryRow {
  interface_id: number;
  time: string;
  status: string;
  reason: string;
  seconds_from_time: number;
  interface: { name: string; description: string };
}

const loading = ref(true);
const rows = ref<HistoryRow[]>([]);

function humanize(seconds: number) {
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

async function loadInfo(from: 'cache' | 'device' = 'cache') {
  loading.value = true;
  try {
    const { data } = await DataService.get('/device-interface/history/log', { device_id: props.deviceId, from });
    rows.value = data.data || [];
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}
function rowKey(record: HistoryRow, index: number) {
  return `${record.interface_id}-${record.time}-${index}`;
}
defineExpose({ loadInfo });
onMounted(() => loadInfo());

// Real-time: an interface up/down event for THIS device means this log
// just gained a row — refetch quietly (cache read, not a live query).
const unsubPoller = wsClient.subscribe('event:poller:finished', (msg) => {
  if (msg.data?.device?.id === props.deviceId) loadInfo('cache');
});
onBeforeUnmount(() => unsubPoller());
</script>

<template>
  <div>
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!rows.length" description="No history available" />
    <a-table v-else :data-source="rows" :row-key="rowKey" size="small" :pagination="{ pageSize: 50 }">
      <a-table-column title="Status" :width="140">
        <template #default="{ record }">
          <div class="hist-status" :class="record.status === 'up' ? 'is-up' : 'is-down'">
            <strong>{{ record.status }}</strong> <span v-if="record.reason">({{ record.reason }})</span>
          </div>
        </template>
      </a-table-column>
      <a-table-column title="Time" data-index="time" :width="160" />
      <a-table-column title="Time ago" :width="110">
        <template #default="{ record }">{{ humanize(record.seconds_from_time) }}</template>
      </a-table-column>
      <a-table-column title="Interface">
        <template #default="{ record }">{{ record.interface.name }}<span v-if="record.interface.description"> — {{ record.interface.description }}</span></template>
      </a-table-column>
    </a-table>
  </div>
</template>

<style scoped>
.hist-status {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 4px;
  color: #fff;
  text-align: center;
  font-weight: 700;
  font-size: 12px;
}
.hist-status.is-up {
  background: darkgreen;
  border: 1px solid green;
}
.hist-status.is-down {
  background: darkred;
  border: 1px solid red;
}
</style>
