<script setup lang="ts">
/**
 * Device management under the new login (Plan 25), from src/views/devices/deviceManagement.ts. Adding a device
 * queues only a safe discovery and leaves polling off; polling is never switched on from this page. Deleting needs the
 * device's name typed. The legacy build keeps DeviceManagementListPage.vue and DeviceFormPage.vue.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { notification } from 'ant-design-vue';
import { api } from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import { Main } from '../styled';
import {
  DEVICE_TYPES,
  deviceErrorMessage,
  deviceFormFor,
  deviceProblems,
  devicesApi,
  discoveryMessage,
  emptyDeviceForm,
  type Device,
  type Option,
} from './deviceManagement';

const devices = devicesApi(api);
const router = useRouter();
const auth = useAuthStore();
const canManage = computed(() => auth.can('devices.manage'));
const canDelete = computed(() => auth.can('devices.delete') && auth.can('dangerous_actions.execute'));

const loading = ref(true);
const rows = ref<Device[]>([]);
const groups = ref<Option[]>([]);
const vendors = ref<Option[]>([]);
const families = ref<Option[]>([]);
const profiles = ref<Option[]>([]);
const filter = ref('');
const groupName = (id: string | null) => groups.value.find((g) => g.value === id)?.label ?? '—';
const visibleRows = computed(() => {
  const text = filter.value.toLowerCase();
  return rows.value.filter((d) => !text || d.name.toLowerCase().includes(text) || d.management_ip.startsWith(text));
});

async function load() {
  loading.value = true;
  try {
    [rows.value, groups.value] = await Promise.all([devices.list(), devices.groups()]);
  } catch (err) {
    notification.error({ message: 'Could not load devices', description: deviceErrorMessage(err) });
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref<Device | null>(null);
const form = reactive(emptyDeviceForm());

async function loadFormOptions() {
  [vendors.value, profiles.value] = await Promise.all([devices.vendors(), devices.accessProfiles()]);
}
watch(
  () => form.vendor_slug,
  async (vendor) => {
    form.family_slug = '';
    families.value = vendor ? await devices.families(vendor) : [];
  },
);

async function openCreate() {
  editing.value = null;
  Object.assign(form, emptyDeviceForm());
  modalOpen.value = true;
  await loadFormOptions();
}
async function openEdit(device: Device) {
  editing.value = device;
  Object.assign(form, deviceFormFor(device));
  modalOpen.value = true;
  await loadFormOptions();
}

async function submit() {
  const found = deviceProblems(form);
  if (found.length) {
    notification.error({ message: 'Check the form', description: found.join('. ') });
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      const sent = await devices.update(editing.value, form);
      notification.success({ message: sent ? 'Device updated' : 'Nothing changed' });
    } else {
      const discovery = await devices.create(form);
      notification.success({ message: 'Device added', description: discoveryMessage(discovery) });
    }
    modalOpen.value = false;
    await load();
  } catch (err) {
    notification.error({ message: 'Could not save the device', description: deviceErrorMessage(err) });
  } finally {
    saving.value = false;
  }
}

// Deleting: the API needs the device's exact name, typed by the user. A custom dialog (not Modal.confirm) so the
// Delete button stays disabled until the typed name matches.
const deleting = ref<Device | null>(null);
const typedName = ref('');
function openDelete(device: Device) {
  deleting.value = device;
  typedName.value = '';
}
async function confirmDelete() {
  const device = deleting.value;
  if (!device || typedName.value !== device.name) return;
  try {
    await devices.remove(device, typedName.value);
    rows.value = rows.value.filter((d) => d.id !== device.id);
    notification.success({ message: `Device "${device.name}" deleted` });
    deleting.value = null;
  } catch (err) {
    notification.error({ message: 'Could not delete the device', description: deviceErrorMessage(err) });
  }
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Device management' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row v-if="canManage" :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <sdButton type="primary" @click="openCreate"><unicon name="plus"></unicon> Add device</sdButton>
      </a-col>
    </a-row>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="dm-filter-row">
            <a-input v-model:value="filter" placeholder="Filter by name or address" style="max-width: 260px" />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="visibleRows" row-key="id" size="small" :pagination="{ pageSize: 25 }">
            <a-table-column title="Name" data-index="name">
              <template #default="{ record }">
                <a @click="router.push({ name: 'device-detail', params: { id: record.id } })"><strong>{{ record.name }}</strong></a>
              </template>
            </a-table-column>
            <a-table-column title="Address" data-index="management_ip" :width="140" />
            <a-table-column title="Type" data-index="device_type" :width="90" />
            <a-table-column title="Vendor" data-index="vendor" :width="110" />
            <a-table-column title="Group" :width="160">
              <template #default="{ record }">{{ groupName(record.group_id) }}</template>
            </a-table-column>
            <a-table-column title="Polling" :width="150">
              <template #default="{ record }">
                <a-tag :color="record.polling_enabled ? 'green' : 'default'">{{ record.polling_enabled ? 'on' : 'off' }}</a-tag>
                <span class="dm-owner">{{ record.polling_owner }}</span>
              </template>
            </a-table-column>
            <a-table-column v-if="canManage || canDelete" title="" :width="90">
              <template #default="{ record }">
                <a v-if="canManage" title="Edit" @click="openEdit(record)"><unicon name="edit"></unicon></a>
                <a v-if="canDelete" class="dm-delete" title="Delete" @click="openDelete(record)"><unicon name="trash-alt"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>

    <a-modal v-model:visible="modalOpen" :title="editing ? `Edit ${editing.name}` : 'Add device'" width="560px">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item label="Name"><a-input v-model:value="form.name" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item label="Management IP"><a-input v-model:value="form.management_ip" /></a-form-item></a-col>
        </a-row>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item label="Hostname"><a-input v-model:value="form.hostname" /></a-form-item></a-col>
          <a-col :span="12">
            <a-form-item label="Type"><a-select v-model:value="form.device_type" :options="DEVICE_TYPES.map((t) => ({ value: t, label: t }))" /></a-form-item>
          </a-col>
        </a-row>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item label="Vendor" :extra="editing ? 'Leave empty to keep the current vendor.' : undefined">
              <a-select v-model:value="form.vendor_slug" allow-clear :options="vendors" :disabled="!vendors.length" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="Model family">
              <a-select v-model:value="form.family_slug" allow-clear :options="families" :disabled="!families.length" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item label="Group"><a-select v-model:value="form.group_id" allow-clear :options="groups" /></a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="SNMP access profile" :extra="editing ? 'Leave empty to keep the current profile.' : undefined">
              <a-select v-model:value="form.access_profile_id" allow-clear :options="profiles" :disabled="!profiles.length" />
            </a-form-item>
          </a-col>
        </a-row>
        <p class="dm-note">
          {{ editing ? 'Polling is switched on during the ownership handover, not here.' : 'A new device starts with polling off; only a safe discovery is queued.' }}
        </p>
      </a-form>
      <template #footer>
        <sdButton type="light" @click="modalOpen = false">Close</sdButton>
        <sdButton type="primary" :loading="saving" @click="submit">Save</sdButton>
      </template>
    </a-modal>

    <a-modal :visible="deleting !== null" title="Delete device" width="440px" @cancel="deleting = null">
      <template v-if="deleting">
        <p>This removes <strong>{{ deleting.name }}</strong> and its history from the system. Type its name to confirm.</p>
        <a-input v-model:value="typedName" :placeholder="deleting.name" autocomplete="off" />
      </template>
      <template #footer>
        <sdButton type="light" @click="deleting = null">Cancel</sdButton>
        <sdButton type="danger" :disabled="!deleting || typedName !== deleting.name" @click="confirmDelete">Delete</sdButton>
      </template>
    </a-modal>
  </Main>
</template>

<style scoped>
.dm-filter-row {
  padding: 16px 16px 16px 0;
}
.dm-owner {
  color: #8c90a4;
  font-size: 12px;
}
.dm-note {
  color: #8c90a4;
  margin: 0;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
:deep(.ant-table) .dm-delete:hover {
  color: #e5484d;
}
</style>
