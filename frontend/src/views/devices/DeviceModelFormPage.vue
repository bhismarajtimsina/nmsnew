<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from '../styled';

// The real app's "Edit model" page is itself mostly read-only — Key, Type,
// Vendor and Controller are all disabled fields there too, since a model's
// identity/kind is fixed by whichever component registered it. Only Name
// (plus a poller-config toggle and an advanced "Additional parameters"
// editor, both left out here as lower-value scope) is actually editable
// via PUT /device-model/{id}. There's also no visible Add/Delete control on
// the real page for this catalog, so this rebuild doesn't add one either.
const route = useRoute();
const router = useRouter();
const modelId = computed(() => Number(route.params.id));

const loading = ref(true);
const saving = ref(false);
const form = reactive({
  key: '',
  name: '',
  type: '',
  vendor: '',
  controller: '',
  // Object mapping poller name -> its default interval in seconds, not a
  // plain string array — confirmed against the real API. Was typed/read as
  // an array here too, so `.length` on the real object was always
  // undefined and this always rendered "—" even with real poller data.
  pollers: {} as Record<string, number>,
});

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get(`/device-model/${modelId.value}`);
    const m = data.data;
    Object.assign(form, {
      key: m.key,
      name: m.name,
      type: m.type,
      vendor: m.vendor,
      controller: m.controller || '',
      pollers: m.pollers || {},
    });
  } catch (err: any) {
    notification.error({
      message: 'Could not load model',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    loading.value = false;
  }
}
onMounted(load);

async function submit() {
  if (!form.name.trim()) {
    notification.error({ message: 'Name is required' });
    return;
  }
  saving.value = true;
  try {
    await DataService.put(`/device-model/${modelId.value}`, { name: form.name });
    notification.success({ message: 'Model updated' });
    router.push({ name: 'device-model' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save model',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <sdPageHeader
    :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '/management/device-model', breadcrumbName: 'Device models' }, { path: '', breadcrumbName: 'Edit model' }]"
    :title="loading ? 'Edit model' : `Edit model ${form.name}`"
    class="ninjadash-page-header-main"
  />
  <Main>
    <a-skeleton v-if="loading" active />
    <a-row v-else :gutter="25">
      <a-col :xs="24" :md="12">
        <sdCards title="Main">
          <a-form layout="vertical">
            <a-form-item label="Key">
              <a-input :value="form.key" disabled />
            </a-form-item>
            <a-form-item label="Name">
              <a-input v-model:value="form.name" />
            </a-form-item>
            <a-form-item label="Type">
              <a-input :value="form.type" disabled />
            </a-form-item>
            <a-form-item label="Vendor">
              <a-input :value="form.vendor" disabled />
            </a-form-item>
            <a-form-item v-if="form.controller" label="Controller">
              <a-input :value="form.controller" disabled />
            </a-form-item>
            <a-form-item label="Default pollers">
              <ul v-if="Object.keys(form.pollers).length" class="model-pollers">
                <li v-for="(seconds, name) in form.pollers" :key="name">{{ name }} <em>({{ seconds }}s)</em></li>
              </ul>
              <span v-else>—</span>
            </a-form-item>
          </a-form>
          <sdButton type="light" @click="router.push({ name: 'device-model' })"><unicon name="arrow-left"></unicon> Back</sdButton>
          <sdButton type="primary" :loading="saving" @click="submit"><unicon name="save"></unicon> Save</sdButton>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.model-pollers {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
  color: #5a5f7d;
}
:deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
</style>
