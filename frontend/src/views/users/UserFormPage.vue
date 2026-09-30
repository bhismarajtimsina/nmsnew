<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';

interface Session {
  id: number;
  created_at: string;
  expired_at: string;
  last_activity: string | null;
  remote_addr: string | null;
  is_manual: boolean;
  description: string | null;
  device?: { os_info?: { name?: string }; client?: { name?: string; version?: string } } | null;
}

const route = useRoute();
const router = useRouter();
const editingId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => editingId.value !== null);

const loading = ref(true);
const saving = ref(false);
const removing = ref(false);
const roleOptions = ref<{ id: number; name: string }[]>([]);
const groupOptions = ref<{ id: number; name: string }[]>([]);
const localeOptions = ref<{ value: string; label: string }[]>([]);
const sessions = ref<Session[]>([]);

const form = reactive({
  login: '',
  name: '',
  role: undefined as number | undefined,
  device_groups: [] as number[],
  status: 'ENABLED',
  password: '',
  confirmPassword: '',
  language: 'en',
});

async function load() {
  loading.value = true;
  const [rolesRes, groupsRes, defaultsRes] = await Promise.allSettled([
    DataService.get('/user-role'),
    DataService.get('/device-group'),
    DataService.get('/public/defaults'),
  ]);
  if (rolesRes.status === 'fulfilled') roleOptions.value = rolesRes.value.data.data || [];
  if (groupsRes.status === 'fulfilled') groupOptions.value = groupsRes.value.data.data || [];
  if (defaultsRes.status === 'fulfilled') {
    const locales = defaultsRes.value.data.data?.locales?.locales || {};
    localeOptions.value = Object.entries(locales).map(([value, label]) => ({ value, label: label as string }));
  }

  if (isEdit.value) {
    try {
      const { data } = await DataService.get(`/user/${editingId.value}`);
      const u = data.data;
      Object.assign(form, {
        login: u.login,
        name: u.name,
        role: u.role?.id,
        device_groups: (u.device_groups || []).map((g: any) => g.id),
        status: u.status,
        password: '',
        confirmPassword: '',
        language: u.language || 'en',
      });
      sessions.value = u.active_sessions || [];
    } catch (err: any) {
      notification.error({
        message: 'Could not load user',
        description: err?.response?.data?.error?.description || 'Please try again.',
      });
    }
  }
  loading.value = false;
}
onMounted(load);

function deviceLabel(s: Session) {
  const os = s.device?.os_info?.name;
  const client = s.device?.client ? `${s.device.client.name || ''} ${s.device.client.version || ''}`.trim() : '';
  return [os, client].filter(Boolean).join(' · ') || s.description || '—';
}

async function submit() {
  if (!form.login.trim() || !form.name.trim()) {
    notification.error({ message: 'Login and name are required' });
    return;
  }
  if (!isEdit.value && !form.password) {
    notification.error({ message: 'Password is required for a new user' });
    return;
  }
  if (form.password && form.password !== form.confirmPassword) {
    notification.error({ message: 'Passwords do not match' });
    return;
  }
  saving.value = true;
  const payload: Record<string, any> = {
    name: form.name,
    role: form.role ? { id: form.role } : undefined,
    device_groups: form.device_groups.map((id) => ({ id })),
    status: form.status,
    language: form.language,
  };
  if (form.password) payload.password = form.password;
  try {
    if (isEdit.value) {
      await DataService.put(`/user/${editingId.value}`, payload);
      notification.success({ message: 'User updated' });
    } else {
      await DataService.post('/user', { ...payload, login: form.login });
      notification.success({ message: 'User created' });
    }
    router.push({ name: 'users' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save user',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

function confirmRemove() {
  if (!editingId.value) return;
  Modal.confirm({
    title: `Delete user "${form.login}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      removing.value = true;
      try {
        await DataService.delete(`/user/${editingId.value}`);
        notification.success({ message: 'User deleted' });
        router.push({ name: 'users' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete user',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      } finally {
        removing.value = false;
      }
    },
  });
}

async function closeSession(id: number) {
  try {
    await DataService.delete(`/user-session-close/${id}`);
    notification.success({ message: 'Session closed' });
    sessions.value = sessions.value.filter((s) => s.id !== id);
  } catch {
    notification.error({ message: 'Could not close session' });
  }
}
function confirmCloseSession(id: number) {
  Modal.confirm({
    title: 'Close this session?',
    content: "The device using this session will be signed out immediately.",
    okText: 'Close session',
    okType: 'danger',
    onOk: () => closeSession(id),
  });
}

// --- Create API key (PUT /user/{id}/generate-auth-key) ---
const apiKeyModalOpen = ref(false);
const apiKeyCreating = ref(false);
const apiKeyResult = ref('');
const apiKeyForm = reactive({ expiredAt: '', currentPassword: '', description: '' });
function openApiKeyModal() {
  apiKeyModalOpen.value = true;
  apiKeyResult.value = '';
  apiKeyForm.expiredAt = '';
  apiKeyForm.currentPassword = '';
  apiKeyForm.description = '';
}
async function createApiKey() {
  if (!apiKeyForm.expiredAt || !apiKeyForm.currentPassword) {
    notification.error({ message: 'Expiry date and your current password are required' });
    return;
  }
  apiKeyCreating.value = true;
  try {
    const { data } = await DataService.put(`/user/${editingId.value}/generate-auth-key`, {
      expired_at: apiKeyForm.expiredAt,
      current_password: apiKeyForm.currentPassword,
      description: apiKeyForm.description || null,
    });
    apiKeyResult.value = data.data.key;
    notification.success({ message: 'API key created' });
    await load();
  } catch (err: any) {
    notification.error({
      message: 'Could not create API key',
      description: err?.response?.data?.error?.description || "Check the current user's password and try again.",
    });
  } finally {
    apiKeyCreating.value = false;
  }
}
async function copyApiKey() {
  try {
    await navigator.clipboard.writeText(apiKeyResult.value);
    notification.success({ message: 'Copied to clipboard' });
  } catch {
    // Clipboard API can be unavailable — the key is still visible/selectable.
  }
}

const pageTitle = computed(() => (isEdit.value ? `Edit user ${form.login}` : 'Add new user'));
</script>

<template>
  <sdPageHeader
    :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '/management/user', breadcrumbName: 'Users list' }, { path: '', breadcrumbName: pageTitle }]"
    class="ninjadash-page-header-main"
  />
  <Main>
    <a-row :gutter="25">
      <a-col :xxl="isEdit ? 7 : 24" :xl="isEdit ? 7 : 24" :lg="isEdit ? 8 : 24" :xs="24">
        <sdCards title="Personal info" style="margin-bottom: 25px">
          <a-skeleton v-if="loading" active />
          <a-form v-else layout="vertical">
            <a-form-item label="Login">
              <a-input v-model:value="form.login" :disabled="isEdit" autocomplete="off" />
            </a-form-item>
            <a-form-item label="Name">
              <a-input v-model:value="form.name" />
            </a-form-item>
            <a-form-item label="Role">
              <a-select v-model:value="form.role" placeholder="User role" style="width: 100%" :options="roleOptions.map((r) => ({ value: r.id, label: r.name }))" />
            </a-form-item>
            <a-form-item label="Device groups">
              <a-select v-model:value="form.device_groups" mode="multiple" style="width: 100%" :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))" />
            </a-form-item>
            <a-form-item label="Status">
              <a-select v-model:value="form.status" style="width: 100%">
                <a-select-option value="ENABLED">Enabled</a-select-option>
                <a-select-option value="DISABLED">Disabled</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item :label="isEdit ? 'New password' : 'Password'">
              <a-input v-model:value="form.password" type="password" autocomplete="new-password" />
              <span v-if="isEdit" class="field-hint">Leave blank to keep the current password</span>
            </a-form-item>
            <a-form-item label="Confirm password">
              <a-input v-model:value="form.confirmPassword" type="password" autocomplete="new-password" />
            </a-form-item>
            <a-form-item label="Language">
              <a-select v-model:value="form.language" style="width: 100%" :options="localeOptions" />
            </a-form-item>
            <div class="user-form-actions">
              <sdButton type="light" @click="router.push({ name: 'users' })"><unicon name="arrow-left"></unicon> Back</sdButton>
              <sdButton type="primary" html-type="button" :loading="saving" @click="submit"><unicon name="save"></unicon> {{ isEdit ? 'Save' : 'Create' }}</sdButton>
              <sdButton v-if="isEdit" type="danger" :loading="removing" @click="confirmRemove"><unicon name="trash-alt"></unicon> Delete</sdButton>
            </div>
          </a-form>
        </sdCards>
      </a-col>

      <a-col v-if="isEdit" :xxl="17" :xl="17" :lg="16" :xs="24">
        <sdCards title="Active sessions">
          <template #button>
            <sdButton type="primary" size="small" @click="openApiKeyModal">
              <unicon name="plus"></unicon> Create API key
            </sdButton>
          </template>
          <a-skeleton v-if="loading" active />
          <a-table
            v-else
            class="sessions-table"
            :data-source="sessions"
            row-key="id"
            :pagination="{ pageSize: 10 }"
            :scroll="{ x: 760 }"
            size="small"
          >
            <a-table-column title="Created at" data-index="created_at" :width="150" />
            <a-table-column title="Expires after" data-index="expired_at" :width="150" />
            <a-table-column title="Last activity" data-index="last_activity" :width="150" />
            <a-table-column title="IP address" data-index="remote_addr" :width="130" />
            <a-table-column title="Device" :width="180">
              <template #default="{ record }">{{ deviceLabel(record) }}</template>
            </a-table-column>
            <a-table-column title="Manual" :width="90">
              <template #default="{ record }">{{ record.is_manual ? 'Yes' : 'No' }}</template>
            </a-table-column>
            <a-table-column title="" :width="130">
              <template #default="{ record }">
                <sdButton type="danger" size="small" @click="confirmCloseSession(record.id)">
                  Close session
                </sdButton>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="apiKeyModalOpen" title="Create API key" :footer="null">
      <div v-if="!apiKeyResult" class="api-key-modal">
        <a-form layout="vertical">
          <a-form-item label="Description" help="Optional — helps recognise this key later">
            <a-input v-model:value="apiKeyForm.description" placeholder="e.g. Grafana integration" />
          </a-form-item>
          <a-form-item label="Expires on">
            <a-date-picker v-model:value="apiKeyForm.expiredAt" style="width: 100%" value-format="YYYY-MM-DD" />
          </a-form-item>
          <a-form-item label="Your current password" help="Required to confirm it's really you">
            <a-input v-model:value="apiKeyForm.currentPassword" type="password" autocomplete="current-password" />
          </a-form-item>
          <sdButton type="primary" html-type="button" :disabled="apiKeyCreating" :loading="apiKeyCreating" @click="createApiKey">
            Create key
          </sdButton>
        </a-form>
      </div>
      <div v-else class="api-key-result">
        <a-alert
          type="warning"
          show-icon
          message="Copy this key now"
          description="It won't be shown again — store it somewhere safe."
          style="margin-bottom: 16px"
        />
        <a-input-group compact style="display: flex">
          <a-input :value="apiKeyResult" readonly style="flex: 1" />
          <sdButton type="primary" @click="copyApiKey"><unicon name="copy"></unicon></sdButton>
        </a-input-group>
      </div>
    </a-modal>
  </Main>
</template>

<style scoped>
.user-form-actions {
  display: flex;
  gap: 10px;
  margin-top: 8px;
}
.user-form-actions :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.field-hint {
  display: block;
  font-size: 12px;
  color: #8c90a4;
  margin-top: 4px;
}
.sessions-table :deep(.ant-table-body) {
  overflow-x: auto;
}
@media (max-width: 575px) {
  .sessions-table :deep(.ant-table-thead > tr > th),
  .sessions-table :deep(.ant-table-tbody > tr > td) {
    padding: 8px;
    font-size: 12px;
    white-space: nowrap;
  }
}
</style>
