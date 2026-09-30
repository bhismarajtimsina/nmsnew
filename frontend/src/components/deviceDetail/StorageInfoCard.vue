<!--
  Per-interface "Storage info" card — the stored record itself (created,
  updated, local id), plus the two switches and free-text comment the
  original lets you edit here. Reverse engineered from StorageInfo in
  UpwardTopology-BurABP73.js. The original also shows a billing-info block
  when an external billing integration (utels/mikbill/all_ok) is
  configured, plus a legacy `agreement` fallback row — none of those are
  populated on this system, so left out here rather than showing an
  always-empty section.
-->
<script setup lang="ts">
import { ref, watch } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';

interface StoredInterface {
  id: number;
  comment: string;
  poll_enabled: boolean;
  params: Record<string, any> | null;
  created_at: string;
  updated_at: string;
}
const props = defineProps<{ storedInterface: StoredInterface }>();
const emit = defineEmits<{ (e: 'saved'): void }>();

const comment = ref(props.storedInterface.comment || '');
const pollEnabled = ref(props.storedInterface.poll_enabled);
const saveFdbHistory = ref(!props.storedInterface.params?.disable_saving_fdb);
const dirty = ref(false);
const saving = ref(false);

watch(
  () => props.storedInterface,
  (v) => {
    comment.value = v.comment || '';
    pollEnabled.value = v.poll_enabled;
    saveFdbHistory.value = !v.params?.disable_saving_fdb;
    dirty.value = false;
  },
);
watch([comment, pollEnabled, saveFdbHistory], () => (dirty.value = true));

async function save() {
  saving.value = true;
  try {
    await DataService.put(`/device-interface/${props.storedInterface.id}`, {
      comment: comment.value,
      poll_enabled: pollEnabled.value,
      params: { ...(props.storedInterface.params || {}), disable_saving_fdb: !saveFdbHistory.value },
    });
    notification.success({ message: 'Saved' });
    dirty.value = false;
    emit('saved');
  } catch (err: any) {
    notification.error({ message: 'Could not save', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <sdCards title="Storage info" style="margin-bottom: 20px">
    <table class="si-kv">
      <tbody>
        <tr><th>Created at</th><td>{{ storedInterface.created_at }}</td></tr>
        <tr><th>Local ID</th><td>{{ storedInterface.id }}</td></tr>
        <tr><th>Updated at</th><td>{{ storedInterface.updated_at }}</td></tr>
        <tr><td colspan="2"><hr /></td></tr>
        <tr>
          <th>Poll enabled</th>
          <td><a-switch v-model:checked="pollEnabled" size="small" /></td>
        </tr>
        <tr>
          <th>Save FDB history</th>
          <td><a-switch v-model:checked="saveFdbHistory" size="small" /></td>
        </tr>
        <tr><td colspan="2"><hr /></td></tr>
        <tr>
          <th>Comment</th>
          <td><a-textarea v-model:value="comment" :rows="3" placeholder="No comment" /></td>
        </tr>
        <tr>
          <td></td>
          <td><sdButton type="primary" size="small" :disabled="!dirty" :loading="saving" @click="save"><unicon name="save"></unicon> Save</sdButton></td>
        </tr>
      </tbody>
    </table>
  </sdCards>
</template>

<style scoped>
.si-kv {
  width: 100%;
  border-collapse: collapse;
}
.si-kv th {
  text-align: left;
  font-weight: 600;
  color: #5a5f7d;
  font-size: 12.5px;
  padding: 5px 10px 5px 0;
  vertical-align: top;
  white-space: nowrap;
}
.si-kv td {
  font-size: 13px;
  padding: 5px 0;
}
.si-kv hr {
  margin: 3px 0;
  border: none;
  border-top: 1px solid #eef0f6;
}
</style>
