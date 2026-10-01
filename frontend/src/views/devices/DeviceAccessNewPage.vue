<script setup lang="ts">
/**
 * SNMP access profiles under the new login (Plan 25). Secrets are write-only: this page never shows one, and a secret
 * left blank while editing keeps the stored value (src/views/devices/accessProfiles.ts). The legacy build keeps
 * DeviceAccessPage.vue; the router picks one or the other.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { Modal, notification } from 'ant-design-vue';
import { api } from '@/api/client';
import { Main } from '../styled';
import {
  AUTH_PROTOCOLS,
  PRIV_PROTOCOLS,
  emptyForm,
  errorMessage,
  formFor,
  problems,
  profilesApi,
  type Profile,
} from './accessProfiles';

const profiles = profilesApi(api);
const loading = ref(true);
const rows = ref<Profile[]>([]);
const nameFilter = ref('');
const visibleRows = computed(() =>
  rows.value.filter((r) => !nameFilter.value || r.name.toLowerCase().includes(nameFilter.value.toLowerCase())),
);

async function load() {
  loading.value = true;
  try {
    rows.value = await profiles.list();
  } catch (err) {
    notification.error({ message: 'Could not load access profiles', description: errorMessage(err) });
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref<Profile | null>(null);
const form = reactive(emptyForm());

function openCreate() {
  editing.value = null;
  Object.assign(form, emptyForm());
  modalOpen.value = true;
}
function openEdit(row: Profile) {
  editing.value = row;
  Object.assign(form, formFor(row));
  modalOpen.value = true;
}

async function submit() {
  const found = problems(form, editing.value !== null);
  if (found.length) {
    notification.error({ message: 'Check the form', description: found.join('. ') });
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      const sent = await profiles.update(editing.value, form);
      notification.success({ message: sent ? 'Access profile updated' : 'Nothing changed' });
    } else {
      await profiles.create(form);
      notification.success({ message: 'Access profile created' });
    }
    modalOpen.value = false;
    await load();
  } catch (err) {
    notification.error({ message: 'Could not save access profile', description: errorMessage(err) });
  } finally {
    saving.value = false;
    // Typed secrets are not kept in memory once the dialog is done with them.
    Object.assign(form, { community: '', v3_auth_secret: '', v3_priv_secret: '' });
  }
}

function confirmDelete(row: Profile) {
  Modal.confirm({
    title: `Delete access profile "${row.name}"?`,
    content: row.devices_using ? `${row.devices_using} device(s) use it; the server will refuse until they are moved.` : undefined,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await profiles.remove(row.id);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Access profile deleted' });
      } catch (err) {
        notification.error({ message: 'Could not delete access profile', description: errorMessage(err) });
      }
    },
  });
}

const set = (yes: boolean) => (yes ? 'set' : 'not set');
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Access management' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" @click="openCreate"><unicon name="plus"></unicon> Create new access</sdButton>
      </a-col>
    </a-row>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="access-filter-row">
            <a-input v-model:value="nameFilter" placeholder="Filter by name" style="max-width: 260px" />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="visibleRows" row-key="id" size="small" :pagination="{ pageSize: 20 }">
            <a-table-column title="Name" data-index="name">
              <template #default="{ record }"><strong>{{ record.name }}</strong></template>
            </a-table-column>
            <a-table-column title="SNMP" data-index="snmp_version" :width="80" />
            <a-table-column title="Credentials">
              <template #default="{ record }">
                <span v-if="record.snmp_version === 'v3'">
                  user {{ record.snmp_v3_username }} · {{ record.snmp_v3_auth_protocol }}/{{ record.snmp_v3_priv_protocol }} · auth secret
                  {{ set(record.has_auth_secret) }}, privacy secret {{ set(record.has_priv_secret) }}
                </span>
                <span v-else>community {{ set(record.has_community) }}</span>
              </template>
            </a-table-column>
            <a-table-column title="Timeout / retries" :width="140">
              <template #default="{ record }">{{ record.timeout_ms }} ms / {{ record.retries }}</template>
            </a-table-column>
            <a-table-column title="Devices" data-index="devices_using" :width="90" />
            <a-table-column title="" :width="100">
              <template #default="{ record }">
                <a title="Edit" @click="openEdit(record)"><unicon name="edit"></unicon></a>
                <a class="access-row__delete" title="Delete" @click="confirmDelete(record)"><unicon name="trash-alt"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="modalOpen" :title="editing ? 'Edit access profile' : 'Create access profile'" width="520px">
      <a-form layout="vertical">
        <a-form-item label="Name"><a-input v-model:value="form.name" /></a-form-item>
        <a-form-item label="SNMP version" :extra="editing ? 'The version cannot be changed; create a new profile instead.' : undefined">
          <a-radio-group v-model:value="form.snmp_version" :disabled="!!editing">
            <a-radio value="v1">v1</a-radio>
            <a-radio value="v2c">v2c</a-radio>
            <a-radio value="v3">v3</a-radio>
          </a-radio-group>
        </a-form-item>
        <template v-if="form.snmp_version !== 'v3'">
          <a-form-item label="Community" :extra="editing ? 'Leave blank to keep the stored community.' : undefined">
            <a-input-password v-model:value="form.community" autocomplete="new-password" />
          </a-form-item>
        </template>
        <template v-else>
          <a-form-item label="User name"><a-input v-model:value="form.v3_username" autocomplete="off" /></a-form-item>
          <a-row :gutter="12">
            <a-col :span="10">
              <a-form-item label="Authentication">
                <a-select v-model:value="form.v3_auth_protocol" :options="AUTH_PROTOCOLS.map((p) => ({ value: p, label: p }))" />
              </a-form-item>
            </a-col>
            <a-col :span="14">
              <a-form-item label="Authentication secret" :extra="editing ? 'Leave blank to keep the stored secret.' : undefined">
                <a-input-password v-model:value="form.v3_auth_secret" autocomplete="new-password" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-row :gutter="12">
            <a-col :span="10">
              <a-form-item label="Privacy">
                <a-select v-model:value="form.v3_priv_protocol" :options="PRIV_PROTOCOLS.map((p) => ({ value: p, label: p }))" />
              </a-form-item>
            </a-col>
            <a-col :span="14">
              <a-form-item label="Privacy secret" :extra="editing ? 'Leave blank to keep the stored secret.' : undefined">
                <a-input-password v-model:value="form.v3_priv_secret" autocomplete="new-password" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item label="Timeout (ms)"><a-input-number v-model:value="form.timeout_ms" :min="200" :max="10000" :step="100" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="Retries"><a-input-number v-model:value="form.retries" :min="0" :max="3" style="width: 100%" /></a-form-item>
          </a-col>
        </a-row>
      </a-form>
      <template #footer>
        <sdButton type="light" @click="modalOpen = false">Close</sdButton>
        <sdButton type="primary" :loading="saving" @click="submit">Save</sdButton>
      </template>
    </a-modal>
  </Main>
</template>

<style scoped>
.access-filter-row {
  padding: 16px 16px 16px 0;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
:deep(.ant-table) .access-row__delete:hover {
  color: #e5484d;
}
</style>
