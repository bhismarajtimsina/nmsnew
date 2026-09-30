<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { Main } from '../styled';

interface RoleRow {
  id: number;
  name: string;
  display: boolean;
  description: string | null;
  permissions: string[];
}

const router = useRouter();
const loading = ref(true);
const rows = ref<RoleRow[]>([]);
const filters = reactive({ name: '', description: '' });

// Built-in roles carry negative ids (Owner -2, System -1) — the real app
// still lists them but shows them with muted/disabled edit & delete
// controls since they can't actually be changed or removed.
const isBuiltIn = (r: RoleRow) => r.id < 0;
const rolesBreadcrumb = "User's roles";

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!filters.name || r.name.toLowerCase().includes(filters.name.toLowerCase())) &&
      (!filters.description || (r.description || '').toLowerCase().includes(filters.description.toLowerCase())),
  ),
);

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/user-role');
    rows.value = data.data || [];
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// Real-time: user_roles table changed anywhere — refresh without a manual reload.
const unsubAdded = wsClient.subscribe('event:storage:user_roles:added', (msg) => mergeById(rows, msg.data));
const unsubUpdated = wsClient.subscribe('event:storage:user_roles:updated', (msg) => mergeById(rows, msg.data));
const unsubDeleted = wsClient.subscribe('event:storage:user_roles:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

function goCreate() {
  router.push({ name: 'user-roles-create' });
}
function goEdit(row: RoleRow) {
  router.push({ name: 'user-roles-edit', params: { id: row.id } });
}

function confirmDelete(row: RoleRow) {
  Modal.confirm({
    title: `Delete role "${row.name}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/user-role/${row.id}`);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Role deleted' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete role',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: rolesBreadcrumb }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" @click="goCreate"><unicon name="user-plus"></unicon> Create new role</sdButton>
      </a-col>
    </a-row>

    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="roles-filter-row">
            <a-input v-model:value="filters.name" placeholder="Filter by name" style="max-width: 260px" />
            <a-input v-model:value="filters.description" placeholder="Filter by description" style="max-width: 260px" />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="filteredRows" row-key="id" size="small" :scroll="{ x: 700 }" :pagination="{ pageSize: 20 }">
            <a-table-column title="Id" data-index="id" :width="70" />
            <a-table-column title="Name" data-index="name">
              <template #default="{ record }"><strong>{{ record.name }}</strong></template>
            </a-table-column>
            <a-table-column title="Display" :width="110">
              <template #default="{ record }">
                <span class="status-badge" :class="record.display ? 'status-badge--ok' : 'status-badge--off'">
                  {{ record.display ? 'Enabled' : 'Disabled' }}
                </span>
              </template>
            </a-table-column>
            <a-table-column title="Description" data-index="description">
              <template #default="{ record }">{{ record.description || '' }}</template>
            </a-table-column>
            <a-table-column title="Role permissions count" :width="180">
              <template #default="{ record }">{{ (record.permissions || []).length }}</template>
            </a-table-column>
            <a-table-column title="" :width="100">
              <template #default="{ record }">
                <a @click="goEdit(record)" title="Edit" :class="{ 'roles-row__muted': isBuiltIn(record) }"><unicon name="edit"></unicon></a>
                <a
                  v-if="!isBuiltIn(record)"
                  class="roles-row__delete"
                  title="Delete"
                  @click="confirmDelete(record)"
                ><unicon name="trash-alt"></unicon></a>
                <span v-else class="roles-row__muted" title="Built-in role can't be deleted"><unicon name="trash-alt"></unicon></span>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.roles-filter-row {
  display: flex;
  gap: 16px;
  padding: 16px 16px 16px 0;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
:deep(.ant-table) a:hover {
  color: #1868db;
}
:deep(.ant-table) .roles-row__delete:hover {
  color: #e5484d;
}
.roles-row__muted {
  margin-right: 12px;
  color: #c7cbdb !important;
  cursor: not-allowed;
}
.status-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
}
.status-badge--ok {
  background: #1a7d36;
}
.status-badge--off {
  background: #6b7280;
}
</style>
