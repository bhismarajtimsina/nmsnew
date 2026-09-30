<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useStore } from 'vuex';
import { useRouter } from 'vue-router';
import { notification, Modal } from 'ant-design-vue';
import { DataService } from '@/config/dataService/dataService';
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

const store = useStore();
const router = useRouter();

const loading = ref(true);
const saving = ref(false);
const sessions = ref<Session[]>([]);

const profile = reactive({
  id: 0,
  login: '',
  roleName: '',
  name: '',
  newPassword: '',
  confirmPassword: '',
  is_twofa: false,
});

const strictIp = reactive({
  enabled: false,
  networks: [''] as string[],
});

const notificationChannels = ref<any[]>([]);
const notificationsLoading = ref(true);

const twofaModalOpen = ref(false);
const twofaQr = ref('');
const twofaPin = ref('');
const twofaConnecting = ref(false);

const apiKeyModalOpen = ref(false);
const apiKeyCreating = ref(false);
const apiKeyResult = ref('');
const apiKeyForm = reactive({
  expiredAt: '',
  currentPassword: '',
  description: '',
});

async function loadUser() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/user/self');
    const u = data.data;
    profile.id = u.id;
    profile.login = u.login;
    profile.roleName = u.role?.name || '';
    profile.name = u.name || '';
    profile.is_twofa = !!u.is_twofa;
    const s = u.settings?.strict_access_by_ip;
    strictIp.enabled = !!s?.enabled;
    strictIp.networks = s?.networks?.length ? [...s.networks] : [''];
    sessions.value = u.active_sessions || [];
    store.commit('loginSuccess', u);
  } catch (err) {
    notification.error({ message: 'Could not load account details', description: String(err) });
  } finally {
    loading.value = false;
  }
}

async function loadNotificationChannels() {
  notificationsLoading.value = true;
  try {
    const { data } = await DataService.get('/component/notifications/configured-channels');
    notificationChannels.value = Array.isArray(data.data) ? data.data : Object.values(data.data || {});
  } catch (err) {
    notificationChannels.value = [];
  } finally {
    notificationsLoading.value = false;
  }
}

onMounted(() => {
  loadUser();
  loadNotificationChannels();
});

function deviceLabel(s: Session) {
  const os = s.device?.os_info?.name;
  const client = s.device?.client ? `${s.device.client.name || ''} ${s.device.client.version || ''}`.trim() : '';
  return [os, client].filter(Boolean).join(' · ') || s.description || '—';
}

// What the API enforces, in the same order it checks (see
// Infrastructure/Security/Passwords::checkPasswordStrength). Repeated here so
// the form can say no before a request goes out, and so a rejection reads as
// a sentence instead of a list of codes like NO_SPECIAL.
const PASSWORD_RULES: { test: (v: string) => boolean; code: string; text: string }[] = [
  { code: 'TOO_SHORT', text: 'at least 8 characters', test: (v) => v.length >= 8 },
  { code: 'NO_UPPERCASE', text: 'a capital letter', test: (v) => /[A-ZА-ЯЁ]/u.test(v) },
  { code: 'NO_LOWERCASE', text: 'a small letter', test: (v) => /[a-zа-яё]/u.test(v) },
  { code: 'NO_DIGIT', text: 'a number', test: (v) => /\d/.test(v) },
  { code: 'NO_SPECIAL', text: 'a symbol such as ! @ # $ % or ?', test: (v) => /[!@#$%^&*(),.?":{}|<>]/.test(v) },
];

const passwordRequirements = PASSWORD_RULES.map((r) => r.text).join(', ');

/** Which requirements a candidate password still fails, in plain words. */
function passwordShortfalls(value: string): string[] {
  return PASSWORD_RULES.filter((r) => !r.test(value)).map((r) => r.text);
}

/** Turn the API's own warning codes into the same plain words. */
function describePasswordWarnings(description: string): string | null {
  const match = /Password has warnings:\s*(.+)$/i.exec(description || '');
  if (!match) return null;
  const codes = match[1].split(',').map((c) => c.trim());
  const words = codes
    .map((code) => PASSWORD_RULES.find((r) => r.code === code)?.text)
    .filter(Boolean) as string[];
  return words.length ? `The password needs ${words.join(', ')}.` : null;
}

async function saveProfile() {
  if (profile.newPassword && profile.newPassword !== profile.confirmPassword) {
    notification.error({ message: 'Passwords do not match' });
    return;
  }
  if (profile.newPassword) {
    const missing = passwordShortfalls(profile.newPassword);
    if (missing.length) {
      notification.error({
        message: 'That password will not be accepted',
        description: `It needs ${missing.join(', ')}.`,
      });
      return;
    }
  }
  saving.value = true;
  const payload: Record<string, any> = { name: profile.name };
  const changingPassword = !!profile.newPassword;
  if (changingPassword) payload.password = profile.newPassword;

  try {
    await DataService.put('/user/self', payload);
    if (changingPassword) {
      // The API closes every active session (including this one) whenever a
      // user's own password changes — so the current key is now dead too.
      notification.success({
        message: 'Password changed',
        description: 'You have been signed out on all devices — please sign in again.',
      });
      await store.dispatch('logOut');
      router.push('/auth/login');
      return;
    }
    notification.success({ message: 'Profile updated' });
    profile.newPassword = '';
    profile.confirmPassword = '';
    await loadUser();
  } catch (err: any) {
    const raw = err?.response?.data?.error?.description || '';
    notification.error({
      message: 'Could not save profile',
      description: describePasswordWarnings(raw) || raw || 'Please check the form and try again.',
    });
  } finally {
    saving.value = false;
  }
}

async function saveStrictIp() {
  saving.value = true;
  try {
    const networks = strictIp.networks.map((n) => n.trim()).filter(Boolean);
    await DataService.put('/user/self', {
      settings: { strict_access_by_ip: { enabled: strictIp.enabled, networks } },
    });
    notification.success({ message: 'Access settings updated' });
    await loadUser();
  } catch (err: any) {
    notification.error({
      message: 'Could not save access settings',
      description: err?.response?.data?.error?.description || 'Check the network/CIDR format and try again.',
    });
  } finally {
    saving.value = false;
  }
}

function addNetwork() {
  strictIp.networks.push('');
}
function removeNetwork(i: number) {
  strictIp.networks.splice(i, 1);
  if (!strictIp.networks.length) strictIp.networks.push('');
}

async function openTwofaModal() {
  twofaModalOpen.value = true;
  twofaQr.value = '';
  twofaPin.value = '';
  try {
    const { data } = await DataService.get('/user/self/2fa');
    twofaQr.value = data.data?.twofaData?.qr || '';
  } catch (err) {
    notification.error({ message: 'Could not load 2FA setup' });
    twofaModalOpen.value = false;
  }
}

async function confirmTwofa() {
  if (!twofaPin.value) return;
  twofaConnecting.value = true;
  try {
    await DataService.put('/user/self/2fa/connect', { twofa_pin: twofaPin.value });
    notification.success({ message: 'Two-factor authentication enabled' });
    twofaModalOpen.value = false;
    await loadUser();
  } catch (err: any) {
    notification.error({
      message: 'Could not enable 2FA',
      description: err?.response?.data?.error?.description || 'Check the code and try again.',
    });
  } finally {
    twofaConnecting.value = false;
  }
}

async function disableTwofa() {
  saving.value = true;
  try {
    await DataService.put('/user/self', { is_twofa: false });
    notification.success({ message: 'Two-factor authentication disabled' });
    await loadUser();
  } catch (err) {
    notification.error({ message: 'Could not disable 2FA' });
  } finally {
    saving.value = false;
  }
}

async function closeSession(id: number) {
  try {
    await DataService.delete(`/user-session-close/${id}`);
    notification.success({ message: 'Session closed' });
    sessions.value = sessions.value.filter((s) => s.id !== id);
  } catch (err) {
    notification.error({ message: 'Could not close session' });
  }
}

function confirmCloseSession(id: number) {
  Modal.confirm({
    title: 'Close this session?',
    content: 'The device using this session will be signed out immediately.',
    okText: 'Close session',
    okType: 'danger',
    onOk: () => closeSession(id),
  });
}

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
    const { data } = await DataService.put(`/user/${profile.id}/generate-auth-key`, {
      expired_at: apiKeyForm.expiredAt,
      current_password: apiKeyForm.currentPassword,
      description: apiKeyForm.description || null,
    });
    apiKeyResult.value = data.data.key;
    notification.success({ message: 'API key created' });
    await loadUser();
  } catch (err: any) {
    notification.error({
      message: 'Could not create API key',
      description: err?.response?.data?.error?.description || 'Check your password and try again.',
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
    // Clipboard API can be unavailable (e.g. insecure context) — the key
    // is still selectable/visible in the field, so this is a soft failure.
  }
}

const hasNotificationChannels = computed(() => notificationChannels.value.length > 0);
</script>

<template>
  <sdPageHeader
    class="ninjadash-page-header-main"
    :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '/account/settings', breadcrumbName: 'My account' }]"
  />
  <Main>
    <a-row :gutter="25">
      <a-col :xxl="7" :xl="7" :lg="8" :xs="24">
        <sdCards title="Personal info" style="margin-bottom: 25px">
          <a-skeleton v-if="loading" active />
          <template v-else>
            <div class="account-meta">
              <p><strong>Your login:</strong> {{ profile.login }}</p>
              <p><strong>ID:</strong> {{ profile.id }}</p>
              <p><strong>Your role:</strong> {{ profile.roleName }}</p>
            </div>
            <a-form layout="vertical" @finish="saveProfile">
              <a-form-item label="Name">
                <a-input v-model:value="profile.name" />
              </a-form-item>
              <a-form-item label="New password">
                <a-input v-model:value="profile.newPassword" type="password" autocomplete="new-password" />
                <span class="field-hint">
                  Leave blank to keep your current password. A new one needs {{ passwordRequirements }}.
                </span>
              </a-form-item>
              <a-form-item label="Confirm password">
                <a-input v-model:value="profile.confirmPassword" type="password" autocomplete="new-password" />
                <span v-if="profile.newPassword" class="field-hint">
                  Changing your password signs you out on every device, including this one.
                </span>
              </a-form-item>
              <a-form-item label="Two-factor authentication">
                <div class="twofa-row">
                  <a-tag :color="profile.is_twofa ? 'green' : 'default'">
                    {{ profile.is_twofa ? 'Enabled' : 'Disabled' }}
                  </a-tag>
                  <sdButton
                    v-if="!profile.is_twofa"
                    type="primary"
                    size="small"
                    :disabled="saving"
                    @click.prevent="openTwofaModal"
                  >
                    Enable
                  </sdButton>
                  <sdButton v-else type="light" size="small" :disabled="saving" @click.prevent="disableTwofa">
                    Disable
                  </sdButton>
                </div>
              </a-form-item>
              <sdButton type="primary" htmlType="submit" :disabled="saving" :loading="saving">
                <unicon name="save"></unicon> Save
              </sdButton>
            </a-form>
          </template>
        </sdCards>

        <sdCards title="Strict access by IP/network" style="margin-bottom: 25px">
          <a-skeleton v-if="loading" active />
          <template v-else>
            <div class="strict-ip-toggle">
              <span>Enable strict access over IP</span>
              <a-switch v-model:checked="strictIp.enabled" />
            </div>
            <div v-if="strictIp.enabled" class="strict-ip-networks">
              <div v-for="(net, i) in strictIp.networks" :key="i" class="strict-ip-network-row">
                <a-input v-model:value="strictIp.networks[i]" placeholder="e.g. 203.0.113.4/32" />
                <sdButton type="light" size="small" @click="removeNetwork(i)"><unicon name="trash-alt"></unicon></sdButton>
              </div>
              <a-button type="dashed" block @click="addNetwork">+ Add network</a-button>
            </div>
            <sdButton type="primary" style="margin-top: 16px" :disabled="saving" :loading="saving" @click="saveStrictIp">
              <unicon name="save"></unicon> Save
            </sdButton>
          </template>
        </sdCards>

        <sdCards title="Notifications configuration">
          <a-skeleton v-if="notificationsLoading" active />
          <a-empty
            v-else-if="!hasNotificationChannels"
            description="Not found configured sources"
          >
            <template #description>
              <p>Not found configured sources</p>
              <p style="font-size: 12px; color: #8c90a4">Please, configure sources before</p>
            </template>
          </a-empty>
          <ul v-else class="notif-channel-list">
            <li v-for="(c, i) in notificationChannels" :key="i">{{ c.name || c.key || c }}</li>
          </ul>
        </sdCards>
      </a-col>

      <a-col :xxl="17" :xl="17" :lg="16" :xs="24">
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

    <a-modal v-model:visible="twofaModalOpen" title="Enable two-factor authentication" :footer="null">
      <div class="twofa-modal">
        <p>Scan this QR code with your authenticator app, then enter the 6-digit code it shows.</p>
        <div v-if="twofaQr" class="twofa-qr">
          <img :src="twofaQr" alt="2FA QR code" />
        </div>
        <a-input v-model:value="twofaPin" placeholder="123456" maxlength="6" style="margin-top: 16px" />
        <sdButton
          type="primary"
          style="margin-top: 16px"
          :disabled="!twofaPin || twofaConnecting"
          :loading="twofaConnecting"
          @click="confirmTwofa"
        >
          Confirm &amp; enable
        </sdButton>
      </div>
    </a-modal>

    <a-modal v-model:visible="apiKeyModalOpen" title="Create API key" :footer="null">
      <div v-if="!apiKeyResult" class="api-key-modal">
        <a-form layout="vertical" @finish="createApiKey">
          <a-form-item label="Description" help="Optional — helps you recognise this key later">
            <a-input v-model:value="apiKeyForm.description" placeholder="e.g. Grafana integration" />
          </a-form-item>
          <a-form-item label="Expires on">
            <a-date-picker v-model:value="apiKeyForm.expiredAt" style="width: 100%" value-format="YYYY-MM-DD" />
          </a-form-item>
          <a-form-item label="Your current password" help="Required to confirm it's really you">
            <a-input v-model:value="apiKeyForm.currentPassword" type="password" autocomplete="current-password" />
          </a-form-item>
          <sdButton type="primary" htmlType="submit" :disabled="apiKeyCreating" :loading="apiKeyCreating">
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
.account-meta {
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid #f1f2f6;
}
.account-meta p {
  margin: 0 0 4px;
  font-size: 14px;
}
.field-hint {
  display: block;
  font-size: 12px;
  color: #8c90a4;
  margin-top: 4px;
}
.twofa-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.strict-ip-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 12px;
}
.strict-ip-networks {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 8px;
}
.strict-ip-network-row {
  display: flex;
  gap: 8px;
}
.strict-ip-network-row .ant-input {
  flex: 1;
}
.notif-channel-list {
  margin: 0;
  padding-left: 18px;
}
.twofa-qr {
  text-align: center;
}
.twofa-qr img {
  max-width: 220px;
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
  .strict-ip-network-row {
    flex-direction: column;
  }
}
</style>
