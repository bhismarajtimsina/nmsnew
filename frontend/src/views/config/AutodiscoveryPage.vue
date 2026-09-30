<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';

interface DeviceAccess {
  id: number;
  name: string;
}
interface DeviceGroup {
  id: number;
  name: string;
}
interface NetworkRow {
  cidr: string;
  device_access: DeviceAccess | null;
  device_group: DeviceGroup | null;
}

const loading = ref(true);
const saving = ref(false);
const networks = ref<NetworkRow[]>([]);
const accessOptions = ref<DeviceAccess[]>([]);
const groupOptions = ref<DeviceGroup[]>([]);

async function load() {
  loading.value = true;
  const [netRes, accessRes, groupRes] = await Promise.allSettled([
    DataService.get('/component/autodiscovery/all'),
    DataService.get('/device-access'),
    DataService.get('/device-group'),
  ]);
  if (netRes.status === 'fulfilled') networks.value = netRes.value.data.data || [];
  if (accessRes.status === 'fulfilled') accessOptions.value = accessRes.value.data.data || [];
  if (groupRes.status === 'fulfilled') groupOptions.value = groupRes.value.data.data || [];
  loading.value = false;
}
onMounted(load);

function addNetwork() {
  networks.value.push({ cidr: '', device_access: null, device_group: null });
}
function removeNetwork(i: number) {
  networks.value.splice(i, 1);
}

function setAccess(row: NetworkRow, id: number) {
  row.device_access = accessOptions.value.find((a) => a.id === id) || null;
}
function setGroup(row: NetworkRow, id: number) {
  row.device_group = groupOptions.value.find((g) => g.id === id) || null;
}

async function save() {
  for (const n of networks.value) {
    if (!n.cidr || !n.device_access || !n.device_group) {
      notification.error({ message: 'Every network row needs a CIDR, device access and device group' });
      return;
    }
  }
  saving.value = true;
  try {
    const { data } = await DataService.put('/component/autodiscovery/all', networks.value);
    networks.value = data.data || networks.value;
    notification.success({ message: 'Autodiscovery networks saved' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save networks',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Autodiscovery' }]" class="ninjadash-page-header-main">
    <template #buttons>
      <div class="ad-header-actions">
        <sdButton type="primary" @click="addNetwork"><unicon name="plus"></unicon> Add network</sdButton>
        <sdButton type="primary" :loading="saving" @click="save"><unicon name="save"></unicon> Save</sdButton>
      </div>
    </template>
  </sdPageHeader>
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <a-skeleton v-if="loading" active />
          <template v-else-if="networks.length">
            <div v-for="(row, i) in networks" :key="i" class="ad-row">
              <sdButton type="danger" size="small" class="ad-row__delete" @click="removeNetwork(i)">
                <unicon name="trash-alt"></unicon>
              </sdButton>
              <div class="ad-row__field">
                <label>Network CIDR</label>
                <a-input v-model:value="row.cidr" placeholder="10.0.10.0/24" />
              </div>
              <div class="ad-row__field">
                <label>Device access</label>
                <a-select
                  :value="row.device_access?.id"
                  style="width: 100%"
                  :options="accessOptions.map((a) => ({ value: a.id, label: a.name }))"
                  @change="(v: number) => setAccess(row, v)"
                />
              </div>
              <div class="ad-row__field">
                <label>Add new devices to group</label>
                <a-select
                  :value="row.device_group?.id"
                  style="width: 100%"
                  :options="groupOptions.map((g) => ({ value: g.id, label: g.name }))"
                  @change="(v: number) => setGroup(row, v)"
                />
              </div>
            </div>
          </template>
          <div v-else class="ad-empty">Not found autodiscovery networks configuration</div>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.ad-header-actions {
  display: flex;
  gap: 10px;
}
.ad-header-actions :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.ad-empty {
  padding: 40px 0;
  text-align: center;
  font-size: 14px;
  color: #8c90a4;
  font-weight: 700;
}
.ad-row {
  display: flex;
  align-items: flex-end;
  gap: 20px;
  padding: 16px 0;
  border-bottom: 1px solid #f0f1f5;
}
.ad-row:last-child {
  border-bottom: none;
}
.ad-row__delete {
  flex-shrink: 0;
}
.ad-row__field {
  flex: 1;
}
.ad-row__field label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: #272b41;
  margin-bottom: 6px;
}
@media (max-width: 767px) {
  .ad-row {
    flex-wrap: wrap;
  }
  .ad-row__field {
    flex: 1 1 100%;
  }
}
</style>
