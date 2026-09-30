<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';
import { wsClient } from '@/services/wsClient';

interface AccessRow {
  id: number;
  name: string;
  public_community: string | null;
  private_community: string | null;
  login: string | null;
  password: string | null;
  params?: { sw_core_connection?: ConnectionParams | null } | null;
}

// The "Set custom connection parameters" toggle in the real "Create new
// access" modal reveals exactly these 8 fields, pre-filled from
// GET /device-access-defaults (its own "default switcher-core connection
// settings" endpoint) — confirmed by that endpoint's real response. When
// left off, the profile just uses those defaults and its stored
// `params.sw_core_connection` is null; toggling on and saving writes a full
// object of overrides there instead.
interface ConnectionParams {
  console_port: string;
  console_timeout_sec: string;
  console_connection_type: string;
  snmp_timeout_sec: string;
  snmp_repeats: string;
  mikrotik_api_port: string;
  snmp_port: string;
  snmp_version: string;
}

const loading = ref(true);
const rows = ref<AccessRow[]>([]);
const nameFilter = ref('');
const connectionDefaults = ref<ConnectionParams | null>(null);

async function load() {
  loading.value = true;
  const [accessRes, defaultsRes] = await Promise.allSettled([
    DataService.get('/device-access'),
    DataService.get('/device-access-defaults'),
  ]);
  if (accessRes.status === 'fulfilled') rows.value = accessRes.value.data.data || [];
  if (defaultsRes.status === 'fulfilled') connectionDefaults.value = defaultsRes.value.data.data;
  loading.value = false;
}
onMounted(load);

// Real-time: added/updated always refetch (never a local merge) — the raw
// pushed record wouldn't carry the "HIDDEN" placeholders the real endpoint
// substitutes for login/community/password (see openEdit's comment above),
// so merging it in directly would either leak nothing useful or blank
// those columns out. deleted is safe to apply locally: it never needs
// those fields, and matches confirmDelete's own local removal above.
const unsubAdded = wsClient.subscribe('event:device-access:added', () => load());
const unsubUpdated = wsClient.subscribe('event:device-access:updated', () => load());
const unsubDeleted = wsClient.subscribe('event:storage:device_accesses:deleted', (msg) => {
  rows.value = rows.value.filter((r) => r.id !== msg.data.id);
});
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

// --- Create/edit modal ---
// The real app's create/edit is a modal, not a routed page (unlike Devices,
// Macros, Users, Roles) — this profile only has a handful of fields, so
// that's what it faithfully replicates here too.
const modalOpen = ref(false);
const saving = ref(false);
const editingId = ref<number | null>(null);
const useCustomConnection = ref(false);
const form = reactive({ name: '', public_community: '', private_community: '', login: '', password: '' });
const connectionForm = reactive<ConnectionParams>({
  console_port: '',
  console_timeout_sec: '',
  console_connection_type: 'telnet',
  snmp_timeout_sec: '',
  snmp_repeats: '',
  mikrotik_api_port: '',
  snmp_port: '',
  snmp_version: '2c',
});

function resetConnectionForm(source?: ConnectionParams | null) {
  Object.assign(connectionForm, source || connectionDefaults.value || {});
}

function openCreate() {
  editingId.value = null;
  Object.assign(form, { name: '', public_community: '', private_community: '', login: '', password: '' });
  useCustomConnection.value = false;
  resetConnectionForm();
  modalOpen.value = true;
}
function openEdit(row: AccessRow) {
  editingId.value = row.id;
  // login/password come back as the literal string "HIDDEN" from the API,
  // never the real secret — left blank here so an untouched field means
  // "keep the existing value" rather than actually submitting the word
  // "HIDDEN" as the new password.
  Object.assign(form, {
    name: row.name,
    public_community: row.public_community === 'HIDDEN' ? '' : row.public_community || '',
    private_community: row.private_community === 'HIDDEN' ? '' : row.private_community || '',
    login: row.login === 'HIDDEN' ? '' : row.login || '',
    password: '',
  });
  const existing = row.params?.sw_core_connection;
  useCustomConnection.value = !!existing;
  resetConnectionForm(existing);
  modalOpen.value = true;
}

function toggleCustomConnection(checked: boolean) {
  useCustomConnection.value = checked;
  if (checked && !connectionForm.snmp_port) resetConnectionForm();
}

async function submit() {
  if (!form.name.trim()) {
    notification.error({ message: 'Name is required' });
    return;
  }
  if (!editingId.value && (!form.public_community || !form.login || !form.password)) {
    notification.error({ message: 'Public community, login and password are required' });
    return;
  }
  saving.value = true;
  const payload: Record<string, any> = { name: form.name };
  if (form.public_community) payload.public_community = form.public_community;
  if (form.private_community) payload.private_community = form.private_community;
  if (form.login) payload.login = form.login;
  if (form.password) payload.password = form.password;
  payload.params = { sw_core_connection: useCustomConnection.value ? { ...connectionForm } : null };
  try {
    if (editingId.value) {
      await DataService.put(`/device-access/${editingId.value}`, payload);
    } else {
      await DataService.post('/device-access', payload);
    }
    notification.success({ message: `Access profile ${editingId.value ? 'updated' : 'created'}` });
    modalOpen.value = false;
    await load();
  } catch (err: any) {
    notification.error({
      message: 'Could not save access profile',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

function confirmDelete(row: AccessRow) {
  Modal.confirm({
    title: `Delete access profile "${row.name}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`/device-access/${row.id}`);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Access profile deleted' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete access profile',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}
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
            <a-table-column title="Login" data-index="login" :width="140" />
            <a-table-column title="Connection" :width="140">
              <template #default="{ record }">{{ record.params?.sw_core_connection ? 'Custom' : 'Default' }}</template>
            </a-table-column>
            <a-table-column title="" :width="100">
              <template #default="{ record }">
                <a @click="openEdit(record)" title="Edit"><unicon name="edit"></unicon></a>
                <a class="access-row__delete" title="Delete" @click="confirmDelete(record)"><unicon name="trash-alt"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="modalOpen" :title="editingId ? 'Edit access' : 'Create new access'" width="720px">
      <a-row :gutter="24">
        <a-col :xs="24" :md="useCustomConnection ? 11 : 24">
          <a-form layout="vertical">
            <a-form-item label="Name">
              <a-input v-model:value="form.name" />
            </a-form-item>
            <a-form-item label="Public community">
              <a-input v-model:value="form.public_community" placeholder="public" autocomplete="off" />
            </a-form-item>
            <a-form-item label="Private community">
              <a-input v-model:value="form.private_community" placeholder="private" autocomplete="off" />
            </a-form-item>
            <a-form-item label="Login">
              <a-input v-model:value="form.login" autocomplete="off" />
            </a-form-item>
            <a-form-item label="Password">
              <a-input v-model:value="form.password" type="password" autocomplete="new-password" />
              <span v-if="editingId" class="access-field-hint">Leave blank to keep the current password</span>
            </a-form-item>
          </a-form>
        </a-col>
        <a-col :xs="24" :md="useCustomConnection ? 13 : 24">
          <sdButton
            :type="useCustomConnection ? 'default' : 'primary'"
            block
            style="margin-bottom: 12px"
            @click="toggleCustomConnection(!useCustomConnection)"
          >
            {{ useCustomConnection ? 'Use default connection parameters' : 'Set custom connection parameters' }}
          </sdButton>
          <p v-if="!useCustomConnection" class="access-connection-note">Used default connections parameters</p>
          <a-form v-else layout="vertical" class="access-connection-form">
            <a-row :gutter="12">
              <a-col :span="12">
                <a-form-item label="Console port">
                  <a-input v-model:value="connectionForm.console_port" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Console timeout (sec)">
                  <a-input v-model:value="connectionForm.console_timeout_sec" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Console connection type">
                  <a-select v-model:value="connectionForm.console_connection_type" style="width: 100%">
                    <a-select-option value="telnet">telnet</a-select-option>
                    <a-select-option value="ssh">ssh</a-select-option>
                  </a-select>
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="SNMP port">
                  <a-input v-model:value="connectionForm.snmp_port" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="SNMP version">
                  <a-select v-model:value="connectionForm.snmp_version" style="width: 100%">
                    <a-select-option value="1">1</a-select-option>
                    <a-select-option value="2c">2c</a-select-option>
                    <a-select-option value="3">3</a-select-option>
                  </a-select>
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="SNMP timeout (sec)">
                  <a-input v-model:value="connectionForm.snmp_timeout_sec" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="SNMP repeats">
                  <a-input v-model:value="connectionForm.snmp_repeats" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Mikrotik API port">
                  <a-input v-model:value="connectionForm.mikrotik_api_port" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-form>
        </a-col>
      </a-row>
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
:deep(.ant-table) a:hover {
  color: #1868db;
}
:deep(.ant-table) .access-row__delete:hover {
  color: #e5484d;
}
.access-field-hint {
  display: block;
  font-size: 12px;
  color: #8c90a4;
  margin-top: 4px;
}
.access-connection-note {
  text-align: center;
  font-weight: 700;
  color: #272b41;
  padding: 40px 0;
}
.access-connection-form {
  border: 1px solid #eceef4;
  border-radius: 8px;
  padding: 12px 12px 0;
}
</style>
