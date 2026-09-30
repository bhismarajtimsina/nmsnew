<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import dayjs, { Dayjs } from 'dayjs';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';
import HeartbeatAreaChart from '@/components/utilities/HeartbeatAreaChart.vue';
import { toOnlineOfflineChart, type ChartData } from '@/utility/chartHelpers';

interface Row {
  id: number;
  ip: string;
  name: string;
  status: string;
  uptime_sec: string | number;
  group: { id: number; name: string } | null;
  model: { id: number; name: string } | null;
}

const loading = ref(true);
const chartLoading = ref(true);
const rows = ref<Row[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const chart = ref<ChartData | null>(null);

const columnFilters = reactive({ ip: '', name: '', group: '', model: '', status: undefined as string | undefined });

const filters = reactive({
  range: [dayjs().subtract(1, 'day'), dayjs()] as [Dayjs, Dayjs],
  step: '10m',
});

function timeRangeIso(): [string, string] {
  return [filters.range[0].toISOString(), filters.range[1].toISOString()];
}

async function loadChart() {
  chartLoading.value = true;
  try {
    const { data } = await DataService.post('/component/analytics/charts/device-statuses', {
      step: filters.step,
      start: filters.range[0].unix(),
      end: filters.range[1].unix(),
    });
    chart.value = toOnlineOfflineChart(data.data);
  } finally {
    chartLoading.value = false;
  }
}

async function loadTable() {
  loading.value = true;
  try {
    const timeRange = timeRangeIso();
    const { data } = await DataService.put('/component/analytics/table/device-statuses', {
      query: {},
      limit: limit.value,
      page: page.value,
      ascending: 0,
      byColumn: 1,
      filter: { devices: [], time_range: timeRange, step: filters.step, choosed_time: filters.range[1].unix() },
    });
    rows.value = data.data || [];
    total.value = data.meta?.total_records ?? rows.value.length;
  } catch (err: any) {
    notification.error({ message: 'Could not load device statuses', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadChart();
  loadTable();
}
function onTableChange(pagination: any) {
  page.value = pagination.current;
  limit.value = pagination.pageSize;
  loadTable();
}

function humanUptime(sec: string | number) {
  const n = Number(sec);
  if (!n) return '—';
  return dayjs().subtract(n, 'second').fromNow(true) + ' up';
}

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!columnFilters.ip || r.ip.includes(columnFilters.ip)) &&
      (!columnFilters.name || r.name.toLowerCase().includes(columnFilters.name.toLowerCase())) &&
      (!columnFilters.group || (r.group?.name || '').toLowerCase().includes(columnFilters.group.toLowerCase())) &&
      (!columnFilters.model || (r.model?.name || '').toLowerCase().includes(columnFilters.model.toLowerCase())) &&
      (!columnFilters.status || r.status === columnFilters.status),
  ),
);

function exportCsv() {
  const header = ['Ip', 'Name', 'Group', 'Model', 'Uptime sec', 'Status'];
  const lines = filteredRows.value.map((r) =>
    [r.ip, r.name, r.group?.name || '', r.model?.name || '', r.uptime_sec, r.status].map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','),
  );
  const blob = new Blob([[header.join(','), ...lines].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'device-statuses.csv';
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(search);

// Real-time: fleet-wide analytics table — debounced since many devices'
// pollers can finish close together.
const reloadDebounced = debounce(() => search(), 3000);
const unsubPoller = wsClient.subscribe('event:poller:finished', () => reloadDebounced.call());
const unsubDevice = wsClient.subscribe('event:device:updated', () => reloadDebounced.call());
onBeforeUnmount(() => {
  reloadDebounced.cancel();
  unsubPoller();
  unsubDevice();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Device statuses' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12" style="margin-bottom: 12px">
              <label class="log-filter-label">Time range</label>
              <a-range-picker v-model:value="filters.range" show-time format="YYYY-MM-DD HH:mm" style="width: 100%" />
            </a-col>
            <a-col :xs="12" :md="4" style="margin-bottom: 12px">
              <label class="log-filter-label">Graph step</label>
              <a-select v-model:value="filters.step" style="width: 100%">
                <a-select-option value="1m">1m</a-select-option>
                <a-select-option value="10m">10m</a-select-option>
                <a-select-option value="30m">30m</a-select-option>
                <a-select-option value="1h">1h</a-select-option>
                <a-select-option value="1d">1d</a-select-option>
              </a-select>
            </a-col>
            <a-col :xs="12" :md="4" style="margin-bottom: 12px; display: flex; align-items: flex-end; gap: 8px">
              <sdButton type="primary" block @click="search"><unicon name="search"></unicon></sdButton>
            </a-col>
          </a-row>
          <div class="log-filter-actions">
            <sdButton type="primary" @click="exportCsv"><unicon name="download-alt"></unicon> Export to excel</sdButton>
          </div>
        </sdCards>

        <sdCards title="Chart data" style="margin-bottom: 16px">
          <a-skeleton v-if="chartLoading" active />
          <div v-else style="height: 320px">
            <HeartbeatAreaChart :chart="chart" />
          </div>
        </sdCards>

        <sdCards :headless="true">
          <div class="col-filter-row">
            <a-input v-model:value="columnFilters.ip" placeholder="Filter by IP" />
            <a-input v-model:value="columnFilters.name" placeholder="Filter by name" />
            <a-input v-model:value="columnFilters.group" placeholder="Filter by group" />
            <a-input v-model:value="columnFilters.model" placeholder="Filter by model" />
            <a-select v-model:value="columnFilters.status" allow-clear placeholder="Choose status" style="width: 140px">
              <a-select-option value="Up">Up</a-select-option>
              <a-select-option value="Down">Down</a-select-option>
            </a-select>
          </div>
          <a-table
            :data-source="filteredRows"
            :loading="loading"
            row-key="id"
            size="small"
            :scroll="{ x: 900 }"
            :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100', '200'] }"
            @change="onTableChange"
          >
            <a-table-column title="Ip" :width="140">
              <template #default="{ record }"><router-link :to="{ name: 'device-detail', params: { id: record.id } }">{{ record.ip }}</router-link></template>
            </a-table-column>
            <a-table-column title="Name" data-index="name" />
            <a-table-column title="Group" :width="150">
              <template #default="{ record }">{{ record.group?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Model" :width="180">
              <template #default="{ record }">{{ record.model?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Uptime" :width="180">
              <template #default="{ record }">{{ humanUptime(record.uptime_sec) }} <span class="log-subtext">{{ record.uptime_sec }}</span></template>
            </a-table-column>
            <a-table-column title="Status" :width="100">
              <template #default="{ record }"><span class="status-tag" :class="record.status === 'Up' ? 'is-success' : 'is-failed'">{{ record.status }}</span></template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.log-filter-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #5a5f7d;
  margin-bottom: 6px;
}
.log-filter-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 8px;
}
.log-subtext {
  font-size: 11px;
  color: #8c90a4;
}
.col-filter-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  padding: 16px 16px 0;
}
.col-filter-row :deep(.ant-input) {
  max-width: 200px;
}
.status-tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
}
.status-tag.is-success {
  background: #1a7a3a;
}
.status-tag.is-failed {
  background: #a60a0a;
}
</style>
