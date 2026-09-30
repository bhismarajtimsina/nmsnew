<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';

interface TelegramConfig {
  bot_api_key: string;
  bot_username: string;
  templates: { alert: string; resolved: string; notification: string };
}
interface EmailConfig {
  host: string;
  auth_enabled: boolean;
  username: string;
  password: string;
  smtp_secure: string;
  port: number;
  from_email: string;
  from_name: string;
  templates: { alert: string; resolved: string; notification: string };
}

const loading = ref(true);
const savingTelegram = ref(false);
const savingEmail = ref(false);
const telegram = ref<TelegramConfig | null>(null);
const email = ref<EmailConfig | null>(null);

async function load() {
  loading.value = true;
  const [tRes, eRes] = await Promise.allSettled([
    DataService.get('/component/notifications/config/channel/telegram'),
    DataService.get('/component/notifications/config/channel/email'),
  ]);
  if (tRes.status === 'fulfilled') telegram.value = tRes.value.data.data;
  if (eRes.status === 'fulfilled') email.value = eRes.value.data.data;
  loading.value = false;
}
onMounted(load);

async function saveTelegram() {
  if (!telegram.value) return;
  savingTelegram.value = true;
  try {
    await DataService.put('/component/notifications/config/channel/telegram', telegram.value);
    notification.success({ message: 'Telegram settings saved' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save Telegram settings',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    savingTelegram.value = false;
  }
}
async function saveEmail() {
  if (!email.value) return;
  savingEmail.value = true;
  try {
    await DataService.put('/component/notifications/config/channel/email', email.value);
    notification.success({ message: 'Email settings saved' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save Email settings',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    savingEmail.value = false;
  }
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Notifications configuration' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :xxl="12" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Telegram">
          <a-skeleton v-if="loading" active />
          <template v-else-if="telegram">
            <a-form layout="vertical">
              <a-form-item label="Bot API key">
                <a-input v-model:value="telegram.bot_api_key" type="password" autocomplete="off" placeholder="123456:ABC-DEF..." />
              </a-form-item>
              <a-form-item label="Bot username">
                <a-input v-model:value="telegram.bot_username" placeholder="my_nms_bot" />
              </a-form-item>
              <a-collapse ghost>
                <a-collapse-panel key="templates" header="Message templates">
                  <a-form-item label="Alert">
                    <a-textarea v-model:value="telegram.templates.alert" :rows="4" />
                  </a-form-item>
                  <a-form-item label="Resolved">
                    <a-textarea v-model:value="telegram.templates.resolved" :rows="3" />
                  </a-form-item>
                  <a-form-item label="Notification">
                    <a-textarea v-model:value="telegram.templates.notification" :rows="4" />
                  </a-form-item>
                </a-collapse-panel>
              </a-collapse>
              <sdButton type="primary" :loading="savingTelegram" @click="saveTelegram">
                <unicon name="save"></unicon> Save
              </sdButton>
            </a-form>
          </template>
        </sdCards>
      </a-col>

      <a-col :xxl="12" :xs="24" style="margin-bottom: 25px">
        <sdCards title="Email">
          <a-skeleton v-if="loading" active />
          <template v-else-if="email">
            <a-form layout="vertical">
              <a-row :gutter="16">
                <a-col :span="16">
                  <a-form-item label="SMTP host">
                    <a-input v-model:value="email.host" placeholder="smtp.example.com" />
                  </a-form-item>
                </a-col>
                <a-col :span="8">
                  <a-form-item label="Port">
                    <a-input-number v-model:value="email.port" style="width: 100%" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-form-item label="Encryption">
                <a-select v-model:value="email.smtp_secure" style="width: 100%">
                  <a-select-option value="ssl">SSL</a-select-option>
                  <a-select-option value="tls">TLS</a-select-option>
                  <a-select-option value="">None</a-select-option>
                </a-select>
              </a-form-item>
              <a-form-item>
                <a-checkbox v-model:checked="email.auth_enabled">Authentication enabled</a-checkbox>
              </a-form-item>
              <template v-if="email.auth_enabled">
                <a-form-item label="Username">
                  <a-input v-model:value="email.username" autocomplete="off" />
                </a-form-item>
                <a-form-item label="Password">
                  <a-input v-model:value="email.password" type="password" autocomplete="new-password" />
                </a-form-item>
              </template>
              <a-row :gutter="16">
                <a-col :span="12">
                  <a-form-item label="From email">
                    <a-input v-model:value="email.from_email" placeholder="nms@example.com" />
                  </a-form-item>
                </a-col>
                <a-col :span="12">
                  <a-form-item label="From name">
                    <a-input v-model:value="email.from_name" placeholder="CyberSathy NMS" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-collapse ghost>
                <a-collapse-panel key="templates" header="Message templates">
                  <a-form-item label="Alert">
                    <a-textarea v-model:value="email.templates.alert" :rows="4" />
                  </a-form-item>
                  <a-form-item label="Resolved">
                    <a-textarea v-model:value="email.templates.resolved" :rows="4" />
                  </a-form-item>
                  <a-form-item label="Notification">
                    <a-textarea v-model:value="email.templates.notification" :rows="4" />
                  </a-form-item>
                </a-collapse-panel>
              </a-collapse>
              <sdButton type="primary" :loading="savingEmail" @click="saveEmail">
                <unicon name="save"></unicon> Save
              </sdButton>
            </a-form>
          </template>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>
