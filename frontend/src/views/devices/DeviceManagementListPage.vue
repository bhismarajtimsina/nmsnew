<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';

interface DeviceRow {
  id: number;
  ip: string;
  name: string;
  mac: string | null;
  description: string | null;
  updated_at: string;
  model: { id: number; name: string } | null;
  access: { id: number; name: string } | null;
  group: { id: number; name: string } | null;
}

const router = useRouter();
const loading = ref(true);
const rows = ref<DeviceRow[]>([]);
const groupOptions = ref<{ id: number; name: string }[]>([]);
const filters = reactive({ ip: '', mac: '', name: '', group: undefined as number | undefined, description: '', model: '', access: '' });
// Six stacked filter inputs ate almost the whole first screen on mobile
// before any actual device data was visible — collapsed behind a toggle by
// default (with a badge showing how many are active) instead.
const filtersOpen = ref(false);
const activeFilterCount = computed(() => Object.values(filters).filter((v) => v !== '' && v !== undefined).length);

async function load() {
  loading.value = true;
  const [devicesRes, groupsRes] = await Promise.allSettled([
    DataService.get('/device', { limit: 999999 }),
    DataService.get('/device-group'),
  ]);
  if (devicesRes.status === 'fulfilled') rows.value = devicesRes.value.data.data || [];
  if (groupsRes.status === 'fulfilled') groupOptions.value = groupsRes.value.data.data || [];
  loading.value = false;
}
onMounted(load);

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!filters.ip || r.ip.toLowerCase().includes(filters.ip.toLowerCase())) &&
      (!filters.mac || (r.mac || '').toLowerCase().includes(filters.mac.toLowerCase())) &&
      (!filters.name || (r.name || '').toLowerCase().includes(filters.name.toLowerCase())) &&
      (!filters.group || r.group?.id === filters.group) &&
      (!filters.description || (r.description || '').toLowerCase().includes(filters.description.toLowerCase())) &&
      (!filters.model || (r.model?.name || '').toLowerCase().includes(filters.model.toLowerCase())) &&
      (!filters.access || (r.access?.name || '').toLowerCase().includes(filters.access.toLowerCase())),
  ),
);

function goCreate() {
  router.push({ name: 'device-management-create' });
}
function goEdit(row: DeviceRow) {
  router.push({ name: 'device-management-edit', params: { id: row.id } });
}

function confirmDelete(row: DeviceRow) {
  Modal.confirm({
    title: `Delete device "${row.name || row.ip}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/device/${row.id}`);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Device deleted' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete device',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Device management' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" @click="goCreate"><unicon name="plus"></unicon> Add device</sdButton>
      </a-col>
    </a-row>

    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <button type="button" class="dm-filter-toggle" @click="filtersOpen = !filtersOpen">
            <unicon name="filter"></unicon>
            Filters
            <span v-if="activeFilterCount" class="dm-filter-toggle__badge">{{ activeFilterCount }}</span>
            <unicon :name="filtersOpen ? 'angle-up' : 'angle-down'" class="dm-filter-toggle__chevron"></unicon>
          </button>
          <div v-show="filtersOpen" class="dm-filter-row">
            <a-input v-model:value="filters.ip" placeholder="Filter by IP" style="max-width: 180px" />
            <a-input v-model:value="filters.mac" placeholder="Filter by MAC" style="max-width: 180px" />
            <a-input v-model:value="filters.name" placeholder="Filter by name" style="max-width: 180px" />
            <a-select v-model:value="filters.group" placeholder="Group" allow-clear style="width: 180px" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" />
            <a-input v-model:value="filters.model" placeholder="Filter by model" style="max-width: 180px" />
            <a-input v-model:value="filters.access" placeholder="Filter by access" style="max-width: 180px" />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="filteredRows" row-key="id" size="small" :scroll="{ x: 900 }" :pagination="{ pageSize: 25 }">
            <a-table-column title="Id" data-index="id" :width="70" />
            <a-table-column title="IP" :width="130">
              <template #default="{ record }"><a @click="goEdit(record)">{{ record.ip }}</a></template>
            </a-table-column>
            <a-table-column title="MAC" data-index="mac" :width="150">
              <template #default="{ record }">{{ record.mac || '—' }}</template>
            </a-table-column>
            <a-table-column title="Name" data-index="name" />
            <a-table-column title="Groups" :width="140">
              <template #default="{ record }">{{ record.group?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Description" data-index="description">
              <template #default="{ record }">{{ record.description || '' }}</template>
            </a-table-column>
            <a-table-column title="Model" :width="160">
              <template #default="{ record }">{{ record.model?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Access" :width="120">
              <template #default="{ record }">{{ record.access?.name || '—' }}</template>
            </a-table-column>
            <a-table-column title="Updated at" data-index="updated_at" :width="160" />
            <a-table-column title="" :width="100">
              <template #default="{ record }">
                <a @click="goEdit(record)" title="Edit"><unicon name="edit"></unicon></a>
                <a class="dm-row__delete" title="Delete" @click="confirmDelete(record)"><unicon name="trash-alt"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.dm-filter-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 16px 16px 0 0;
  padding: 6px 14px;
  border: 1px solid #e6e9f1;
  border-radius: 6px;
  background: #fff;
  color: #5a5f7d;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.dm-filter-toggle:hover {
  border-color: #1868db;
  color: #1868db;
}
.dm-filter-toggle :deep(svg) {
  width: 13px;
  height: 13px;
}
.dm-filter-toggle__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: #1868db;
  color: #fff;
  font-size: 11px;
}
.dm-filter-toggle__chevron {
  margin-left: 2px;
}
.dm-filter-row {
  display: flex;
  gap: 12px;
  padding: 16px 16px 16px 0;
  flex-wrap: wrap;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
:deep(.ant-table) a:hover {
  color: #1868db;
}
:deep(.ant-table) .dm-row__delete:hover {
  color: #e5484d;
}
</style>
