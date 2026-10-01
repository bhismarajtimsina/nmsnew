<script setup lang="ts">
/**
 * Users under the new login (Plan 25), from src/views/users/usersApi.ts. Accounts are disabled, never deleted; a
 * generated password is shown once and then dropped. The legacy build keeps UsersListPage.vue and UserFormPage.vue;
 * the router picks one or the other.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { Modal, notification } from 'ant-design-vue';
import { api } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import { Main } from '../styled';
import { emptyUserForm, userErrorMessage, userFormFor, userProblems, usersApi, type Role, type User } from './usersApi';

const users = usersApi(api);
const auth = useAuthStore();
const canManage = computed(() => auth.can('users.manage'));

const loading = ref(true);
const rows = ref<User[]>([]);
const roles = ref<Role[]>([]);
const filters = reactive({ text: '', status: undefined as 'active' | 'disabled' | undefined });
const visibleRows = computed(() =>
  rows.value.filter((u) => {
    const text = filters.text.toLowerCase();
    if (text && !u.username.toLowerCase().includes(text) && !u.display_name.toLowerCase().includes(text)) return false;
    if (filters.status === 'active' && !u.is_active) return false;
    if (filters.status === 'disabled' && u.is_active) return false;
    return true;
  }),
);

async function load() {
  loading.value = true;
  try {
    [rows.value, roles.value] = await Promise.all([users.list(), users.roles()]);
  } catch (err) {
    notification.error({ message: 'Could not load users', description: userErrorMessage(err) });
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref<User | null>(null);
const form = reactive(emptyUserForm());

function openCreate() {
  editing.value = null;
  Object.assign(form, emptyUserForm());
  modalOpen.value = true;
}
function openEdit(user: User) {
  editing.value = user;
  Object.assign(form, userFormFor(user, roles.value));
  modalOpen.value = true;
}

// A password the server generated: shown once, in its own dialog, then forgotten.
const shownPassword = ref<{ username: string; password: string } | null>(null);
function showOnce(username: string, password: string | null) {
  if (password) shownPassword.value = { username, password };
}
function closePassword() {
  shownPassword.value = null;
}

async function submit() {
  const found = userProblems(form, editing.value !== null);
  if (found.length) {
    notification.error({ message: 'Check the form', description: found.join('. ') });
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      const sent = await users.update(editing.value, form, roles.value);
      notification.success({ message: sent ? 'User updated' : 'Nothing changed' });
    } else {
      const password = await users.create(form);
      notification.success({ message: 'User created' });
      showOnce(form.username.trim(), password);
    }
    modalOpen.value = false;
    await load();
  } catch (err) {
    notification.error({ message: 'Could not save user', description: userErrorMessage(err) });
  } finally {
    saving.value = false;
  }
}

function confirmResetPassword(user: User) {
  Modal.confirm({
    title: `Reset the password of "${user.username}"?`,
    content: 'A new password is generated and shown once. The user is signed out everywhere and must change it at the next sign-in.',
    okText: 'Reset password',
    onOk: async () => {
      try {
        showOnce(user.username, await users.resetPassword(user.id));
      } catch (err) {
        notification.error({ message: 'Could not reset the password', description: userErrorMessage(err) });
      }
    },
  });
}

function confirmResetTwoFactor(user: User) {
  Modal.confirm({
    title: `Reset two-factor sign-in for "${user.username}"?`,
    content: 'Their authenticator stops working; they enroll again at the next sign-in.',
    okText: 'Reset 2FA',
    okType: 'danger',
    onOk: async () => {
      try {
        await users.resetTwoFactor(user.id);
        notification.success({ message: 'Two-factor sign-in reset' });
        await load();
      } catch (err) {
        notification.error({ message: 'Could not reset two-factor sign-in', description: userErrorMessage(err) });
      }
    },
  });
}

function when(value: string | null) {
  return value ? new Date(value).toLocaleString() : 'never';
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Users list' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row v-if="canManage" :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" :disabled="!roles.length" @click="openCreate"><unicon name="plus"></unicon> Add user</sdButton>
      </a-col>
    </a-row>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="users-filter-row">
            <a-input v-model:value="filters.text" placeholder="Filter by login or name" style="max-width: 260px" />
            <a-select v-model:value="filters.status" placeholder="Status" allow-clear style="width: 160px">
              <a-select-option value="active">Active</a-select-option>
              <a-select-option value="disabled">Disabled</a-select-option>
            </a-select>
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="visibleRows" row-key="id" size="small" :pagination="{ pageSize: 25 }">
            <a-table-column title="Login" data-index="username">
              <template #default="{ record }"><strong>{{ record.username }}</strong></template>
            </a-table-column>
            <a-table-column title="Name" data-index="display_name" />
            <a-table-column title="Role" data-index="role" />
            <a-table-column title="Status" :width="100">
              <template #default="{ record }">
                <a-tag :color="record.is_active ? 'green' : 'default'">{{ record.is_active ? 'active' : 'disabled' }}</a-tag>
              </template>
            </a-table-column>
            <a-table-column title="2FA" :width="70">
              <template #default="{ record }">{{ record.totp_enabled ? 'on' : 'off' }}</template>
            </a-table-column>
            <a-table-column title="Last sign-in" :width="180">
              <template #default="{ record }">{{ when(record.last_login_at) }}</template>
            </a-table-column>
            <a-table-column v-if="canManage" title="" :width="130">
              <template #default="{ record }">
                <a title="Edit" @click="openEdit(record)"><unicon name="edit"></unicon></a>
                <a title="Reset password" @click="confirmResetPassword(record)"><unicon name="key-skeleton"></unicon></a>
                <a v-if="record.totp_enabled" title="Reset 2FA" @click="confirmResetTwoFactor(record)"><unicon name="shield-slash"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="modalOpen" :title="editing ? `Edit ${editing.username}` : 'Add user'" width="480px">
      <a-form layout="vertical">
        <a-form-item v-if="!editing" label="Login"><a-input v-model:value="form.username" autocomplete="off" /></a-form-item>
        <a-form-item label="Name"><a-input v-model:value="form.display_name" /></a-form-item>
        <a-form-item label="Email"><a-input v-model:value="form.email" /></a-form-item>
        <a-form-item label="Role">
          <a-select v-model:value="form.role_id" :options="roles.map((r) => ({ value: r.id, label: r.name }))" />
        </a-form-item>
        <a-form-item v-if="editing" label="Active">
          <a-switch v-model:checked="form.is_active" />
        </a-form-item>
        <p v-if="!editing" class="users-note">A password is generated and shown once; the user must change it at the first sign-in.</p>
      </a-form>
      <template #footer>
        <sdButton type="light" @click="modalOpen = false">Close</sdButton>
        <sdButton type="primary" :loading="saving" @click="submit">Save</sdButton>
      </template>
    </a-modal>

    <a-modal :visible="shownPassword !== null" title="New password" :closable="false" :mask-closable="false" width="440px">
      <template v-if="shownPassword">
        <p>The password for <strong>{{ shownPassword.username }}</strong> is shown only now. Pass it on securely.</p>
        <a-typography-paragraph copyable class="users-password">{{ shownPassword.password }}</a-typography-paragraph>
      </template>
      <template #footer>
        <sdButton type="primary" @click="closePassword">I have saved it</sdButton>
      </template>
    </a-modal>
  </Main>
</template>

<style scoped>
.users-filter-row {
  display: flex;
  gap: 16px;
  padding: 16px 16px 16px 0;
  flex-wrap: wrap;
}
.users-note {
  color: #8c90a4;
  margin: 0;
}
.users-password {
  font-family: monospace;
  font-size: 16px;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
</style>
