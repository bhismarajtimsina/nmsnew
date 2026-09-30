<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { Main } from '../styled';

interface UserRow {
  id: number;
  login: string;
  name: string;
  status: string;
  last_activity: string | null;
  role: { id: number; name: string } | null;
}

const router = useRouter();
const loading = ref(true);
const rows = ref<UserRow[]>([]);
const filters = reactive({ login: '', name: '', status: undefined as string | undefined });

// The real app's Users list only ever shows the account holder's own real
// users — the built-in service accounts (Alertmanager, System events,
// console, system) that come back from GET /user alongside them all carry
// negative ids and never appear in its table either, confirmed by a real
// account that had 5 total users returned by the API but only 1 row shown.
const filteredRows = computed(() =>
  rows.value
    .filter((r) => r.id > 0)
    .filter(
      (r) =>
        (!filters.login || r.login.toLowerCase().includes(filters.login.toLowerCase())) &&
        (!filters.name || (r.name || '').toLowerCase().includes(filters.name.toLowerCase())) &&
        (!filters.status || r.status === filters.status),
    ),
);

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/user');
    rows.value = data.data || [];
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// Real-time: the pushed payload is the full user record (password field
// already excluded server-side via its @prop.display=no annotation, same
// mechanism the REST API relies on) — merge it directly, no refetch.
const unsubAdded = wsClient.subscribe('event:storage:users:added', (msg) => mergeById(rows, msg.data));
const unsubUpdated = wsClient.subscribe('event:storage:users:updated', (msg) => mergeById(rows, msg.data));
const unsubDeleted = wsClient.subscribe('event:storage:users:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

function goCreate() {
  router.push({ name: 'users-create' });
}
function goEdit(row: UserRow) {
  router.push({ name: 'users-edit', params: { id: row.id } });
}

function confirmDelete(row: UserRow) {
  Modal.confirm({
    title: `Delete user "${row.login}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/user/${row.id}`);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'User deleted' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete user',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Users list' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" @click="goCreate"><unicon name="plus"></unicon> Add user</sdButton>
      </a-col>
    </a-row>

    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="users-filter-row">
            <a-input v-model:value="filters.login" placeholder="Filter by login" style="max-width: 220px" />
            <a-input v-model:value="filters.name" placeholder="Filter by name" style="max-width: 220px" />
            <a-select v-model:value="filters.status" placeholder="Status" allow-clear style="width: 160px">
              <a-select-option value="ENABLED">Enabled</a-select-option>
              <a-select-option value="DISABLED">Disabled</a-select-option>
            </a-select>
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="filteredRows" row-key="id" size="small" :scroll="{ x: 700 }" :pagination="{ pageSize: 20 }">
            <a-table-column title="Id" data-index="id" :width="70" />
            <a-table-column title="Login" data-index="login" />
            <a-table-column title="Name" data-index="name" />
            <a-table-column title="Last activity" data-index="last_activity" :width="170">
              <template #default="{ record }">{{ record.last_activity || '—' }}</template>
            </a-table-column>
            <a-table-column title="Role" :width="130">
              <template #default="{ record }">
                <a v-if="record.role" @click="router.push({ name: 'user-roles-edit', params: { id: record.role.id } })">{{ record.role.name }}</a>
                <span v-else>—</span>
              </template>
            </a-table-column>
            <a-table-column title="Status" :width="110">
              <template #default="{ record }">
                <span class="status-badge" :class="record.status === 'ENABLED' ? 'status-badge--ok' : 'status-badge--off'">
                  {{ record.status === 'ENABLED' ? 'Enabled' : 'Disabled' }}
                </span>
              </template>
            </a-table-column>
            <a-table-column title="" :width="100">
              <template #default="{ record }">
                <a @click="goEdit(record)" title="Edit"><unicon name="edit"></unicon></a>
                <a class="users-row__delete" @click="confirmDelete(record)" title="Delete"><unicon name="trash-alt"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.users-filter-row {
  display: flex;
  gap: 16px;
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
:deep(.ant-table) .users-row__delete:hover {
  color: #e5484d;
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
