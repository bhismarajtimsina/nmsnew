<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { api } from '@/api/client';
import { authBackend } from '@/auth/session';
import { useRealtimeStore } from '@/stores/realtime';
import { mergeById, removeById } from '@/utility/listMerge';
import { fromLegacyGroup, legacySource, newApiSource, type GroupRow } from './deviceGroups';
import { watchDeviceChanges, type Id } from './deviceList';
import { Main } from '../styled';

// With the new login the page reads and writes the new API (src/views/devices/deviceGroups.ts); legacy is unchanged.
const newApi = authBackend() === 'cybersathy';
const source = newApi ? newApiSource(api) : legacySource(DataService as any);

const loading = ref(true);
const rows = ref<GroupRow[]>([]);
const nameFilter = ref('');
const isBuiltIn = (r: GroupRow) => r.builtIn;

async function load() {
  loading.value = true;
  try {
    rows.value = await source.list();
  } catch (err) {
    notification.error({ message: 'Could not load groups', description: source.errorMessage(err) });
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// Real-time. Legacy: device_groups table changed anywhere, so the pushed record is merged in as before. New login: group
// changes publish the same data-free `devices.changed` notice as device changes, and the page reloads through the
// scoped API.
const noop = () => {};
const reloadQuietly = async () => {
  try {
    rows.value = await source.list();
  } catch {
    /* the next manual action shows any error */
  }
};
const unsubChanges = newApi ? watchDeviceChanges((channel, handler) => useRealtimeStore().subscribe(channel, handler), reloadQuietly) : noop;
const unsubAdded = newApi ? noop : wsClient.subscribe('event:storage:device_groups:added', (msg) => mergeById(rows, fromLegacyGroup(msg.data)));
const unsubUpdated = newApi ? noop : wsClient.subscribe('event:storage:device_groups:updated', (msg) => mergeById(rows, fromLegacyGroup(msg.data)));
const unsubDeleted = newApi ? noop : wsClient.subscribe('event:storage:device_groups:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubChanges();
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

const modalOpen = ref(false);
const saving = ref(false);
const editingId = ref<Id | null>(null);
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
    if (editingId.value !== null) {
      await source.update(editingId.value, form);
    } else {
      await source.create(form);
    }
    notification.success({ message: `Group ${editingId.value !== null ? 'updated' : 'created'}` });
    modalOpen.value = false;
    await load();
  } catch (err) {
    notification.error({ message: 'Could not save group', description: source.errorMessage(err) });
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
        await source.remove(row.id);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Group deleted' });
      } catch (err) {
        notification.error({ message: 'Could not delete group', description: source.errorMessage(err) });
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
            <a-table-column v-if="!newApi" title="Id" data-index="id" :width="70" />
            <a-table-column title="Name" data-index="name">
              <template #default="{ record }"><strong>{{ record.name }}</strong></template>
            </a-table-column>
            <a-table-column title="Description" data-index="description">
              <template #default="{ record }">{{ record.description || '' }}</template>
            </a-table-column>
            <a-table-column v-if="newApi" title="Devices" data-index="devices" :width="90" />
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
