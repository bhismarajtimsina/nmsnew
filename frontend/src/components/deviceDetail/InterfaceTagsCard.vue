<!--
  Per-interface "Marks" card — favorite toggle + tag list, reverse
  engineered from Marks in UpwardTopology-BurABP73.js. The page header
  already has its own favorite button (built earlier), so this card
  focuses on tags; its favorite star mirrors that same state via the
  `favorite`/`toggle-favorite` props so the two stay in sync.
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';

const props = defineProps<{ interfaceDbId: number; favorite: boolean }>();
const emit = defineEmits<{ (e: 'toggle-favorite'): void }>();

const loading = ref(true);
const tags = ref<string[]>([]);
const existingTags = ref<string[]>([]);
const saving = ref(false);

async function loadMarks() {
  loading.value = true;
  try {
    const { data } = await DataService.get(`/interface-marks/marks/${props.interfaceDbId}`);
    tags.value = data.data?.tags || [];
  } catch {
    tags.value = [];
  } finally {
    loading.value = false;
  }
}
async function loadExistingTags() {
  try {
    const { data } = await DataService.get('/interface-marks/existed-tags', { query: '' });
    existingTags.value = data.data || [];
  } catch {
    existingTags.value = [];
  }
}
async function saveTags() {
  saving.value = true;
  try {
    await DataService.put(`/interface-marks/tags/${props.interfaceDbId}`, tags.value);
  } catch (err: any) {
    notification.error({ message: 'Could not save tags', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    saving.value = false;
  }
}
function onTagsChange() {
  saveTags();
}

onMounted(async () => {
  await Promise.all([loadMarks(), loadExistingTags()]);
});
</script>

<template>
  <sdCards title="Tags" style="margin-bottom: 20px">
    <div class="itc-row">
      <a class="itc-star" title="Favorite" @click="emit('toggle-favorite')">
        <unicon name="star" width="32" height="32" :fill="favorite ? 'darkgoldenrod' : '#cfd3e0'"></unicon>
      </a>
      <a-select
        v-model:value="tags"
        mode="tags"
        style="flex: 1"
        placeholder="No tags"
        :options="existingTags.map((t) => ({ value: t, label: t }))"
        :loading="loading"
        @change="onTagsChange"
      />
    </div>
  </sdCards>
</template>

<style scoped>
.itc-row {
  display: flex;
  align-items: center;
  gap: 14px;
}
.itc-star {
  flex: 0 0 auto;
  display: flex;
}
.itc-star :deep(svg) {
  fill: v-bind('favorite ? "darkgoldenrod" : "#cfd3e0"') !important;
}
</style>
