<!--
  Per-interface up/down history — reverse engineered from IfaceDownHistory
  in UpwardTopology-BurABP73.js. Distinct from the ONU-specific
  "reasons.history_table" already shown on the ONU status card: this is
  the generic interface up/down log (same source the device-level History
  log tab uses), scoped to just this one interface, with a computed
  uptime/downtime duration per row.
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';

const props = defineProps<{ interfaceDbId: number; interfaceType: string }>();

interface HistoryRow {
  up: string | null;
  down: string | null;
  down_reason: string | null;
}

const loading = ref(true);
const rows = ref<HistoryRow[]>([]);

function duration(row: HistoryRow) {
  const from = row.up ? new Date(row.up.replace(' ', 'T')) : null;
  if (!from) return '-';
  const to = row.down ? new Date(row.down.replace(' ', 'T')) : new Date();
  let seconds = Math.floor((to.getTime() - from.getTime()) / 1000);
  if (seconds < 0) seconds = 0;
  const days = Math.floor(seconds / 86400);
  const h = String(Math.floor((seconds % 86400) / 3600)).padStart(2, '0');
  const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
  const s = String(seconds % 60).padStart(2, '0');
  return days ? `${days}d ${h}:${m}:${s}` : `${h}:${m}:${s}`;
}

async function loadInfo() {
  loading.value = true;
  try {
    const { data } = await DataService.get(`/device-interface/history/${props.interfaceDbId}`);
    rows.value = data.data || [];
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}
onMounted(() => loadInfo());
</script>

<template>
  <sdCards title="Interface history" style="margin-bottom: 20px">
    <a-skeleton v-if="loading" active />
    <a-empty v-else-if="!rows.length" description="History is empty" />
    <a-table v-else :data-source="rows" row-key="up" size="small" :pagination="{ pageSize: 10 }">
      <a-table-column title="Up" data-index="up" />
      <a-table-column title="Down" data-index="down" />
      <a-table-column v-if="interfaceType === 'ONU'" title="Down reason">
        <template #default="{ record }"><strong>{{ record.down_reason || '-' }}</strong></template>
      </a-table-column>
      <a-table-column title="Duration">
        <template #default="{ record }">{{ duration(record) }}</template>
      </a-table-column>
    </a-table>
  </sdCards>
</template>
