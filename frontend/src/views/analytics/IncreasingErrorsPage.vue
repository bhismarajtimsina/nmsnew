<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import dayjs, { Dayjs } from 'dayjs';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';
import HeartbeatAreaChart from '@/components/utilities/HeartbeatAreaChart.vue';
import type { ChartData } from '@/utility/chartHelpers';

interface Row {
  interface: {
    id: number;
    name: string;
    bind_key: string;
    type: string;
    device: { id: number; ip: string; name: string; group: { id: number; name: string } | null; model: { type: string } | null };
  };
  in_errors: number;
  out_errors: number;
  counter_in_errors: number;
  counter_out_errors: number;
}

const loading = ref(true);
const chartLoading = ref(true);
const rows = ref<Row[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const chart = ref<ChartData | null>(null);
const groupOptions = ref<{ id: number; name: string }[]>([]);

const columnFilters = reactive({ device: '', type: '', group: '', iface: '' });

const filters = reactive({
  groups: [] as number[],
  range: [dayjs().subtract(1, 'day'), dayjs()] as [Dayjs, Dayjs],
  step: '10m',
});

async function loadGroups() {
  const { data } = await DataService.get('/component/analytics/parameters/device-groups');
  groupOptions.value = data.data || [];
}

function groupBody() {
  return filters.groups.map((id) => ({ id }));
}

async function loadChart() {
  chartLoading.value = true;
  try {
    const { data } = await DataService.post('/component/analytics/charts/increasing-errors', {
      step: filters.step,
      device_groups: groupBody(),
      start: filters.range[0].unix(),
      end: filters.range[1].unix(),
    });
    chart.value = data.data;
  } finally {
    chartLoading.value = false;
  }
}

async function loadTable() {
  loading.value = true;
  try {
    const { data } = await DataService.put('/component/analytics/table/increasing-errors', {
      query: {},
      limit: limit.value,
      page: page.value,
      ascending: 0,
      byColumn: 1,
      device_groups: groupBody(),
      step: filters.step,
      choosed_time: filters.range[1].unix(),
    });
    rows.value = data.data || [];
    total.value = data.meta?.total_records ?? rows.value.length;
  } catch (err: any) {
    notification.error({ message: 'Could not load increasing errors', description: err?.response?.data?.error?.description || 'Please try again.' });
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

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!columnFilters.device || r.interface.device.ip.toLowerCase().includes(columnFilters.device.toLowerCase()) || r.interface.device.name.toLowerCase().includes(columnFilters.device.toLowerCase())) &&
      (!columnFilters.type || (r.interface.device.model?.type || '').toLowerCase().includes(columnFilters.type.toLowerCase())) &&
      (!columnFilters.group || (r.interface.device.group?.name || '').toLowerCase().includes(columnFilters.group.toLowerCase())) &&
      (!columnFilters.iface || r.interface.name.toLowerCase().includes(columnFilters.iface.toLowerCase())),
  ),
);

function exportCsv() {
  const header = ['Device', 'Type', 'Device Group', 'Interface', 'Increasing IN-errors', 'Increasing OUT-errors', 'IN errors', 'OUT errors'];
  const lines = filteredRows.value.map((r) =>
    [
      `${r.interface.device.ip} (${r.interface.device.name})`,
      r.interface.device.model?.type || '',
      r.interface.device.group?.name || '',
      r.interface.name,
      r.in_errors,
      r.out_errors,
      r.counter_in_errors,
      r.counter_out_errors,
    ]
      .map((v) => `"${String(v).replace(/"/g, '""')}"`)
      .join(','),
  );
  const blob = new Blob([[header.join(','), ...lines].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'increasing-errors.csv';
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(async () => {
  await loadGroups();
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
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Increasing errors' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="8" style="margin-bottom: 12px">
              <label class="log-filter-label">Device groups</label>
              <a-select v-model:value="filters.groups" mode="multiple" allow-clear placeholder="All groups" style="width: 100%" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" />
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
            <a-input v-model:value="columnFilters.type" placeholder="Filter by type" />
            <a-input v-model:value="columnFilters.group" placeholder="Filter by device group" />
            <a-input v-model:value="columnFilters.iface" placeholder="Filter by interface" />
          </div>
          <a-table
            :data-source="filteredRows"
            :loading="loading"
            row-key="interface.id"
            size="small"
            :scroll="{ x: 900 }"
            :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100', '200'] }"
            @change="onTableChange"
          >
            <a-table-column title="Device" :width="200">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-detail', params: { id: record.interface.device.id } }">{{ record.interface.device.ip }}</router-link>
                <div class="log-subtext">{{ record.interface.device.name }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Type" :width="90">
              <template #default="{ record }">{{ record.interface.device.model?.type || '—' }}</template>
            </a-table-column>
            <a-table-column title="Device Group" :width="140">
              <template #default="{ record }">{{ record.interface.device.group?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Interface" :width="140">
              <template #default="{ record }">
                <router-link
                  :to="{ name: 'device-interface-detail', params: { id: record.interface.device.id, interface: record.interface.bind_key }, query: { type: record.interface.type === 'ONU' ? 'ONU' : 'PHYSICAL' } }"
                >{{ record.interface.name }}</router-link>
              </template>
            </a-table-column>
            <a-table-column title="Increasing IN-errors" data-index="in_errors" :width="150" />
            <a-table-column title="Increasing OUT-errors" data-index="out_errors" :width="160" />
            <a-table-column title="IN errors" data-index="counter_in_errors" :width="110" />
            <a-table-column title="OUT errors" data-index="counter_out_errors" :width="110" />
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
</style>
