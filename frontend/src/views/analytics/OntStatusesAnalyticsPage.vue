<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, watch } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import dayjs, { Dayjs } from 'dayjs';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';
import HeartbeatAreaChart from '@/components/utilities/HeartbeatAreaChart.vue';
import type { ChartData } from '@/utility/chartHelpers';

interface Row {
  device: { id: number; ip: string; name: string };
  interface: { id: string; name: string; type: string };
  status: string;
}

const loading = ref(true);
const chartLoading = ref(true);
const rows = ref<Row[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const chart = ref<ChartData | null>(null);
// `/component/analytics/parameters/device-list` (unlike `/device/options`)
// doesn't return a `display_name` field, only the raw `ip`/`name` — the
// select was reading `d.display_name` (always undefined) and silently
// falling back to showing the numeric id as its label.
const deviceOptions = ref<{ id: number; ip: string; name: string }[]>([]);

const columnFilters = reactive({ device: '', iface: '', status: undefined as string | undefined });

const filters = reactive({
  devices: [] as number[],
  range: [dayjs().subtract(1, 'day'), dayjs()] as [Dayjs, Dayjs],
  step: '10m',
});

async function loadOptions() {
  const { data } = await DataService.get('/component/analytics/parameters/device-list', { type: 'OLT' });
  deviceOptions.value = data.data || [];
}

function devicesBody() {
  return filters.devices.map((id) => ({ id }));
}
function timeRangeIso(): [string, string] {
  return [filters.range[0].toISOString(), filters.range[1].toISOString()];
}

async function loadChart() {
  chartLoading.value = true;
  try {
    const timeRange = timeRangeIso();
    const { data } = await DataService.post('/component/analytics/charts/ont-statuses', {
      step: filters.step,
      filter: { devices: devicesBody(), time_range: timeRange, step: filters.step, choosed_time: timeRange[1] },
      start: filters.range[0].unix(),
      end: filters.range[1].unix(),
    });
    chart.value = data.data;
  } finally {
    chartLoading.value = false;
  }
}

// The `query` param on this endpoint isn't a free-text search box — the
// backend (DataPagination::getFilteredRecords) treats an object `query` as
// a per-field filter map and matches it against the *entire* server-side
// dataset before paginating. The column filters below used to only filter
// whatever single page of rows was already fetched (so "Offline" only ever
// searched the 50 rows on screen, missing everything on other pages) —
// fixed by sending them as this `query` object instead, so filtering now
// covers all matching rows regardless of page.
function buildQuery(): Record<string, string> {
  const query: Record<string, string> = {};
  if (columnFilters.device) query.device = columnFilters.device;
  if (columnFilters.iface) query.interface = columnFilters.iface;
  if (columnFilters.status) query.status = columnFilters.status;
  return query;
}

async function loadTable() {
  loading.value = true;
  try {
    const timeRange = timeRangeIso();
    const { data } = await DataService.put('/component/analytics/table/ont-statuses-history', {
      query: buildQuery(),
      limit: limit.value,
      page: page.value,
      ascending: 0,
      byColumn: 1,
      filter: { devices: devicesBody(), time_range: timeRange, step: filters.step, choosed_time: filters.range[1].unix() },
    });
    rows.value = data.data || [];
    total.value = data.meta?.total_records ?? rows.value.length;
  } catch (err: any) {
    notification.error({ message: 'Could not load ONT statuses', description: err?.response?.data?.error?.description || 'Please try again.' });
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

let filterDebounce: ReturnType<typeof setTimeout> | null = null;
watch(
  () => [columnFilters.device, columnFilters.iface, columnFilters.status],
  () => {
    if (filterDebounce) clearTimeout(filterDebounce);
    filterDebounce = setTimeout(() => {
      page.value = 1;
      loadTable();
    }, 400);
  },
);

function exportCsv() {
  const header = ['Device', 'Interface', 'Status'];
  const lines = rows.value.map((r) =>
    [`${r.device.ip} (${r.device.name})`, r.interface.name, r.status].map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','),
  );
  const blob = new Blob([[header.join(','), ...lines].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'ont-statuses.csv';
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(async () => {
  await loadOptions();
  search();
});

// Real-time: fleet-wide analytics table — debounced since many devices'
// pollers can finish close together.
const reloadDebounced = debounce(() => search(), 3000);
const unsubPoller = wsClient.subscribe('event:poller:finished', () => reloadDebounced.call());
onBeforeUnmount(() => {
  reloadDebounced.cancel();
  unsubPoller();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'ONT statuses' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="8" style="margin-bottom: 12px">
              <label class="log-filter-label">Devices</label>
              <a-select
                v-model:value="filters.devices"
                mode="multiple"
                allow-clear
                placeholder="All OLTs"
                style="width: 100%"
                :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
                :options="deviceOptions.map((d) => ({ value: d.id, label: `${d.ip} (${d.name})` }))"
              />
            </a-col>
            <a-col :xs="24" :md="8" style="margin-bottom: 12px">
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
            <a-input v-model:value="columnFilters.device" placeholder="Filter by device" />
            <a-input v-model:value="columnFilters.iface" placeholder="Filter by interface" />
            <a-select v-model:value="columnFilters.status" allow-clear placeholder="Choose status" style="width: 160px">
              <a-select-option value="Online">Online</a-select-option>
              <a-select-option value="Offline">Offline</a-select-option>
              <a-select-option value="LOS">LOS</a-select-option>
            </a-select>
          </div>
          <a-table
            :data-source="rows"
            :loading="loading"
            row-key="interface.id"
            size="small"
            :scroll="{ x: 700 }"
            :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100', '200'] }"
            @change="onTableChange"
          >
            <a-table-column title="Device" :width="220">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-detail', params: { id: record.device.id } }">{{ record.device.ip }}</router-link>
                <span class="log-subtext"> ({{ record.device.name }})</span>
              </template>
            </a-table-column>
            <a-table-column title="Interface" data-index="name">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-interface-detail', params: { id: record.device.id, interface: record.interface.id }, query: { type: 'ONU' } }">{{ record.interface.name }}</router-link>
              </template>
            </a-table-column>
            <a-table-column title="Status" :width="140">
              <template #default="{ record }"><span class="status-tag" :class="record.status === 'Online' ? 'is-success' : 'is-failed'">{{ record.status }}</span></template>
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
  max-width: 220px;
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
