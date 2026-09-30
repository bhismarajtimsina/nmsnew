<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';

interface ChartData {
  labels: string[];
  datasets: { label: string; data: number[]; backgroundColor?: string[] }[];
}
interface Row {
  id: number;
  name: string;
  status: string;
  description: string;
  device: { id: number; name: string; ip: string };
  optical_rx: string | null;
  optical_tx: string | null;
  optical_olt_rx: string | null;
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

const columnFilters = reactive({ device: '', iface: '', desc: '', status: undefined as string | undefined });

const filters = reactive({
  devices: [] as number[],
  type: 'rx' as 'rx' | 'tx' | 'olt_rx',
});

async function loadOptions() {
  const { data } = await DataService.get('/component/analytics/parameters/device-list', { type: 'OLT' });
  deviceOptions.value = data.data || [];
}

function devicesBody() {
  return filters.devices.map((id) => ({ id }));
}

async function loadChart() {
  chartLoading.value = true;
  try {
    const { data } = await DataService.post('/component/analytics/bars/ont-levels', {
      filter: { devices: devicesBody(), type: filters.type, level: null },
    });
    chart.value = data.data;
  } finally {
    chartLoading.value = false;
  }
}

async function loadTable() {
  loading.value = true;
  try {
    const { data } = await DataService.put('/component/analytics/table/signal-strength', {
      query: {},
      limit: limit.value,
      page: page.value,
      ascending: 0,
      byColumn: 1,
      filter: { devices: devicesBody(), type: filters.type, level: null },
    });
    rows.value = data.data || [];
    total.value = data.meta?.total_records ?? rows.value.length;
  } catch (err: any) {
    notification.error({ message: 'Could not load ONT signal strength', description: err?.response?.data?.error?.description || 'Please try again.' });
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

const apexSeries = computed(() => [{ name: 'ONTs', data: chart.value?.datasets[0]?.data.map(Number) ?? [] }]);
const apexOptions = computed(() => ({
  chart: { type: 'bar' as const, toolbar: { show: false }, zoom: { enabled: false } },
  plotOptions: { bar: { columnWidth: '70%', borderRadius: 2, distributed: true } },
  colors: chart.value?.datasets[0]?.backgroundColor ?? ['#1868db'],
  dataLabels: { enabled: false },
  legend: { show: false },
  grid: { borderColor: '#485e9015' },
  xaxis: { categories: chart.value?.labels ?? [], labels: { style: { fontSize: '11px' } } },
  yaxis: { min: 0, labels: { style: { fontSize: '11px' } } },
  tooltip: { y: { formatter: (v: number) => `${v} ONTs` } },
}));

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!columnFilters.device || r.device.ip.includes(columnFilters.device) || r.device.name.toLowerCase().includes(columnFilters.device.toLowerCase())) &&
      (!columnFilters.iface || r.name.toLowerCase().includes(columnFilters.iface.toLowerCase())) &&
      (!columnFilters.desc || (r.description || '').toLowerCase().includes(columnFilters.desc.toLowerCase())) &&
      (!columnFilters.status || r.status === columnFilters.status),
  ),
);

function exportCsv() {
  const header = ['Device', 'Interface', 'Status', 'Description', 'RX', 'TX', 'OLT RX'];
  const lines = filteredRows.value.map((r) =>
    [`${r.device.ip} (${r.device.name})`, r.name, r.status, r.description, r.optical_rx ?? '', r.optical_tx ?? '', r.optical_olt_rx ?? '']
      .map((v) => `"${String(v).replace(/"/g, '""')}"`)
      .join(','),
  );
  const blob = new Blob([[header.join(','), ...lines].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'ont-level-strength.csv';
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
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'ONT signal strength' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="14" style="margin-bottom: 12px">
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
            <a-col :xs="16" :md="6" style="margin-bottom: 12px">
              <label class="log-filter-label">Signal type</label>
              <a-select v-model:value="filters.type" style="width: 100%">
                <a-select-option value="rx">RX</a-select-option>
                <a-select-option value="tx">TX</a-select-option>
                <a-select-option value="olt_rx">OLT RX</a-select-option>
              </a-select>
            </a-col>
            <a-col :xs="8" :md="4" style="margin-bottom: 12px; display: flex; align-items: flex-end; gap: 8px">
              <sdButton type="primary" block @click="search"><unicon name="search"></unicon></sdButton>
            </a-col>
          </a-row>
          <div class="log-filter-actions">
            <sdButton type="primary" @click="exportCsv"><unicon name="download-alt"></unicon> Export to excel</sdButton>
          </div>
        </sdCards>

        <sdCards title="Chart data" style="margin-bottom: 16px">
          <a-skeleton v-if="chartLoading" active />
          <apexchart v-else type="bar" height="300" :options="apexOptions" :series="apexSeries"></apexchart>
        </sdCards>

        <sdCards :headless="true">
          <div class="col-filter-row">
            <a-input v-model:value="columnFilters.device" placeholder="Filter by device" />
            <a-input v-model:value="columnFilters.iface" placeholder="Filter by interface" />
            <a-input v-model:value="columnFilters.desc" placeholder="Filter by description" />
            <a-select v-model:value="columnFilters.status" allow-clear placeholder="Choose status" style="width: 140px">
              <a-select-option value="Online">Online</a-select-option>
              <a-select-option value="Offline">Offline</a-select-option>
              <a-select-option value="LOS">LOS</a-select-option>
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
            <a-table-column title="Device" :width="200">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-detail', params: { id: record.device.id } }">{{ record.device.ip }}</router-link>
                <div class="log-subtext">{{ record.device.name }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Interface" data-index="name" :width="150" />
            <a-table-column title="Status" :width="110">
              <template #default="{ record }"><span class="status-tag" :class="record.status === 'Online' ? 'is-success' : 'is-failed'">{{ record.status }}</span></template>
            </a-table-column>
            <a-table-column title="Description" data-index="description" />
            <a-table-column title="RX" :width="80">
              <template #default="{ record }">{{ record.optical_rx ?? '—' }}</template>
            </a-table-column>
            <a-table-column title="TX" :width="80">
              <template #default="{ record }">{{ record.optical_tx ?? '—' }}</template>
            </a-table-column>
            <a-table-column title="OLT RX" :width="90">
              <template #default="{ record }">{{ record.optical_olt_rx ?? '—' }}</template>
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
