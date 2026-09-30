<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';
import { debounce } from '@/utility/debounce';

interface FavoriteRow {
  id: number;
  name: string;
  bind_key: string;
  status: string;
  description: string;
  created_at: string;
  device: { id: number; name: string; ip: string };
  ident: { ident: string } | null;
}

const router = useRouter();
const loading = ref(true);
const rows = ref<FavoriteRow[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(50);
const deviceOptions = ref<{ id: number; display_name: string }[]>([]);

const columnFilters = reactive({ device: '', name: '', ident: '', description: '', status: undefined as string | undefined });

const filters = reactive({ devices: [] as number[], search: '' });

async function loadOptions() {
  const { data } = await DataService.get('/device/options');
  deviceOptions.value = data.data || [];
}

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.put('/interface-marks/favorite/list', {
      query: {},
      limit: limit.value,
      page: page.value,
      ascending: 0,
      byColumn: 1,
      filter: { devices: filters.devices.map((id) => ({ id })), value: filters.search },
    });
    rows.value = data.data || [];
    total.value = data.meta?.total_records ?? rows.value.length;
  } catch (err: any) {
    notification.error({ message: 'Could not load favorite interfaces', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  load();
}
function onTableChange(pagination: any) {
  page.value = pagination.current;
  limit.value = pagination.pageSize;
  load();
}

function goToDevice(id: number) {
  router.push({ name: 'device-detail', params: { id } });
}
// Physical ports report Up/Down; ONU/ONT interfaces report Online/Offline —
// used here to pick which detail panel a row's interface should open.
function ifaceType(status: string) {
  return ['Up', 'Down'].includes(status) ? 'PHYSICAL' : 'ONU';
}

function confirmUnfavorite(row: FavoriteRow) {
  Modal.confirm({
    title: `Remove "${row.name}" from favorites?`,
    okText: 'Remove',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.put(`/interface-marks/favorite/${row.id}`, { favorite: false });
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Removed from favorites' });
      } catch (err: any) {
        notification.error({ message: 'Could not update favorite status', description: err?.response?.data?.error?.description || 'Please try again.' });
      }
    },
  });
}

const filteredRows = () =>
  rows.value.filter(
    (r) =>
      (!columnFilters.device || r.device.ip.includes(columnFilters.device) || r.device.name.toLowerCase().includes(columnFilters.device.toLowerCase())) &&
      (!columnFilters.name || r.name.toLowerCase().includes(columnFilters.name.toLowerCase())) &&
      (!columnFilters.ident || (r.ident?.ident || '').toLowerCase().includes(columnFilters.ident.toLowerCase())) &&
      (!columnFilters.description || (r.description || '').toLowerCase().includes(columnFilters.description.toLowerCase())) &&
      (!columnFilters.status || r.status === columnFilters.status),
  );

onMounted(async () => {
  await loadOptions();
  load();
});

// Real-time: optical/status readings here come from device pollers —
// debounced since a fleet can report in close together.
const reloadDebounced = debounce(() => load(), 3000);
const unsubPoller = wsClient.subscribe('event:poller:finished', () => reloadDebounced.call());
onBeforeUnmount(() => {
  reloadDebounced.cancel();
  unsubPoller();
});
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Favorite interfaces' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Filters" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12" style="margin-bottom: 12px">
              <label class="log-filter-label">Devices</label>
              <a-select
                v-model:value="filters.devices"
                mode="multiple"
                allow-clear
                placeholder="All devices"
                style="width: 100%"
                :filter-option="(input: string, opt: any) => opt.label.toLowerCase().includes(input.toLowerCase())"
                :options="deviceOptions.map((d) => ({ value: d.id, label: d.display_name }))"
              />
            </a-col>
            <a-col :xs="18" :md="9" style="margin-bottom: 12px">
              <label class="log-filter-label">Search</label>
              <a-input v-model:value="filters.search" placeholder="Value" />
            </a-col>
            <a-col :xs="6" :md="3" style="margin-bottom: 12px; display: flex; align-items: flex-end">
              <sdButton type="primary" block @click="search"><unicon name="search"></unicon></sdButton>
            </a-col>
          </a-row>
        </sdCards>

        <sdCards :headless="true">
          <div class="col-filter-row">
            <a-input v-model:value="columnFilters.device" placeholder="Filter by device" />
            <a-input v-model:value="columnFilters.name" placeholder="Filter by name" />
            <a-input v-model:value="columnFilters.ident" placeholder="Filter by ident" />
            <a-input v-model:value="columnFilters.description" placeholder="Filter by description" />
            <a-select v-model:value="columnFilters.status" allow-clear placeholder="Choose status" style="width: 150px">
              <a-select-option value="Online">Online</a-select-option>
              <a-select-option value="Offline">Offline</a-select-option>
              <a-select-option value="Up">Up</a-select-option>
              <a-select-option value="Down">Down</a-select-option>
            </a-select>
          </div>
          <a-table
            :data-source="filteredRows()"
            :loading="loading"
            row-key="id"
            size="small"
            :scroll="{ x: 900 }"
            :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100', '200'] }"
            @change="onTableChange"
          >
            <a-table-column title="Created at" data-index="created_at" :width="160" />
            <a-table-column title="Device" :width="200">
              <template #default="{ record }">
                <a @click="goToDevice(record.device.id)">{{ record.device.ip }}</a>
                <div class="log-subtext">{{ record.device.name }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Name">
              <template #default="{ record }">
                <router-link :to="{ name: 'device-interface-detail', params: { id: record.device.id, interface: record.bind_key }, query: { type: ifaceType(record.status) } }">{{ record.name }}</router-link>
              </template>
            </a-table-column>
            <a-table-column title="Ident" :width="180">
              <template #default="{ record }">{{ record.ident?.ident || '—' }}</template>
            </a-table-column>
            <a-table-column title="Description" data-index="description" />
            <a-table-column title="Status" :width="110">
              <template #default="{ record }"><span class="status-tag" :class="['Online', 'Up'].includes(record.status) ? 'is-success' : 'is-failed'">{{ record.status }}</span></template>
            </a-table-column>
            <a-table-column title="" :width="60">
              <template #default="{ record }">
                <a class="fav-remove" title="Remove from favorites" @click="confirmUnfavorite(record)"><unicon name="star"></unicon></a>
              </template>
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
.fav-remove {
  color: #d4a017;
}
.fav-remove:hover {
  color: #a60a0a;
}
</style>
