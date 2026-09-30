<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { Main } from '../styled';

interface GroupRow {
  id: number;
  name: string;
  description: string | null;
  created_at: string;
}

const loading = ref(true);
const rows = ref<GroupRow[]>([]);
const nameFilter = ref('');
const isBuiltIn = (r: GroupRow) => r.id < 0;

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/device-group');
    rows.value = data.data || [];
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// Real-time: device_groups table changed anywhere — refresh without a manual reload.
const unsubAdded = wsClient.subscribe('event:storage:device_groups:added', (msg) => mergeById(rows, msg.data));
const unsubUpdated = wsClient.subscribe('event:storage:device_groups:updated', (msg) => mergeById(rows, msg.data));
const unsubDeleted = wsClient.subscribe('event:storage:device_groups:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

const modalOpen = ref(false);
const saving = ref(false);
const editingId = ref<number | null>(null);
const form = reactive({ name: '', description: '' });

function openCreate() {
  editingId.value = null;
  Object.assign(form, { name: '', description: '' });
  modalOpen.value = true;
}
function openEdit(row: GroupRow) {
  if (isBuiltIn(row)) return;
  editingId.value = row.id;
  Object.assign(form, { name: row.name, description: row.description || '' });
  modalOpen.value = true;
}

async function submit() {
  if (!form.name.trim()) {
    notification.error({ message: 'Name is required' });
    return;
  }
  saving.value = true;
  try {
    if (editingId.value) {
      await DataService.put(`/device-group/${editingId.value}`, { name: form.name, description: form.description || undefined });
    } else {
      await DataService.post('/device-group', { name: form.name, description: form.description || undefined });
    }
    notification.success({ message: `Group ${editingId.value ? 'updated' : 'created'}` });
    modalOpen.value = false;
    await load();
  } catch (err: any) {
    notification.error({
      message: 'Could not save group',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

function confirmDelete(row: GroupRow) {
  if (isBuiltIn(row)) return;
  Modal.confirm({
    title: `Delete group "${row.name}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/device-group/${row.id}`);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Group deleted' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete group',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Group device management' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" @click="openCreate"><unicon name="plus"></unicon> Add new group</sdButton>
      </a-col>
    </a-row>

    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="group-filter-row">
            <a-input v-model:value="nameFilter" placeholder="Filter by name" style="max-width: 260px" />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table
            v-else
            :data-source="rows.filter((r) => !nameFilter || r.name.toLowerCase().includes(nameFilter.toLowerCase()))"
            row-key="id"
            size="small"
            :pagination="{ pageSize: 20 }"
          >
            <a-table-column title="Id" data-index="id" :width="70" />
            <a-table-column title="Name" data-index="name">
              <template #default="{ record }"><strong>{{ record.name }}</strong></template>
            </a-table-column>
            <a-table-column title="Description" data-index="description">
              <template #default="{ record }">{{ record.description || '' }}</template>
            </a-table-column>
            <a-table-column title="Created at" data-index="created_at" :width="170" />
            <a-table-column title="" :width="100">
              <template #default="{ record }">
                <a :class="{ 'group-row__muted': isBuiltIn(record) }" title="Edit" @click="openEdit(record)"><unicon name="edit"></unicon></a>
                <a
                  v-if="!isBuiltIn(record)"
                  class="group-row__delete"
                  title="Delete"
                  @click="confirmDelete(record)"
                ><unicon name="trash-alt"></unicon></a>
                <span v-else class="group-row__muted" title="Built-in group can't be deleted"><unicon name="trash-alt"></unicon></span>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="modalOpen" :title="editingId ? 'Edit group' : 'Create group'" width="480px">
      <a-form layout="vertical">
        <a-form-item label="Name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item label="Description">
          <a-input v-model:value="form.description" />
        </a-form-item>
      </a-form>
      <template #footer>
        <sdButton type="light" @click="modalOpen = false">Close</sdButton>
        <sdButton type="primary" :loading="saving" @click="submit">Save</sdButton>
      </template>
    </a-modal>
  </Main>
</template>

<style scoped>
.group-filter-row {
  padding: 16px 16px 16px 0;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
:deep(.ant-table) a:hover {
  color: #1868db;
}
:deep(.ant-table) .group-row__delete:hover {
  color: #e5484d;
}
.group-row__muted {
  margin-right: 12px;
  color: #c7cbdb !important;
  cursor: not-allowed;
}
</style>
