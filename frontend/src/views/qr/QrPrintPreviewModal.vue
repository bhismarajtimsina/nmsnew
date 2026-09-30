<script setup lang="ts">
import { ref, watch } from 'vue';
import { notification } from 'ant-design-vue';
import { DataService } from '@/config/dataService/dataService';

interface QrTarget {
  id: number | string;
  description: string;
}

const props = defineProps<{
  visible: boolean;
  type: 'device' | 'interface';
  targets: QrTarget[];
  withLabel: boolean;
  withLogo: boolean;
  size: number;
}>();
const emit = defineEmits<{ (e: 'update:visible', value: boolean): void }>();

interface QrItem {
  id: number | string;
  description: string;
  qr: string | null;
}

const loading = ref(false);
const items = ref<QrItem[]>([]);

// Print layout parameters — same three the original app exposes.
const boxSizeMm = ref(25);
const marginMm = ref(5);
const gapMm = ref(0.5);
const displayDescription = ref(true);

async function loadQrCodes() {
  loading.value = true;
  items.value = props.targets.map((t) => ({ ...t, qr: null }));
  const results = await Promise.allSettled(
    props.targets.map((t) =>
      DataService.get(`/component/qr-generator/qr-code-base64/${props.type}/${t.id}`, {
        size: props.size,
        with_logo: props.withLogo ? 1 : 0,
        with_label: props.withLabel ? 1 : 0,
      }),
    ),
  );
  results.forEach((r, i) => {
    if (r.status === 'fulfilled') {
      items.value[i].qr = r.value.data.data.qr;
    }
  });
  const failed = results.filter((r) => r.status === 'rejected').length;
  if (failed) {
    notification.error({ message: `Could not generate ${failed} of ${props.targets.length} QR code(s)` });
  }
  loading.value = false;
}

watch(
  () => props.visible,
  (v) => {
    if (v) loadQrCodes();
  },
);

function close() {
  emit('update:visible', false);
}
function printNow() {
  window.print();
}
</script>

<template>
  <a-modal
    :visible="visible"
    title="Print QRs preview"
    width="800px"
    wrap-class-name="qr-preview-modal"
    @update:visible="(v: boolean) => emit('update:visible', v)"
  >
    <div class="qr-print-params">
      <div class="qr-print-params__title">Print parameters</div>
      <a-row :gutter="16">
        <a-col :span="6">
          <label>QR size box in mm</label>
          <a-input-number v-model:value="boxSizeMm" :min="10" :max="100" style="width: 100%" />
        </a-col>
        <a-col :span="6">
          <label>Page margin in mm</label>
          <a-input-number v-model:value="marginMm" :min="0" :max="50" style="width: 100%" />
        </a-col>
        <a-col :span="6">
          <label>Gap mm</label>
          <a-input-number v-model:value="gapMm" :min="0" :max="20" :step="0.5" style="width: 100%" />
        </a-col>
        <a-col :span="6">
          <label>Display description</label>
          <div><a-switch v-model:checked="displayDescription" /></div>
        </a-col>
      </a-row>
    </div>

    <div class="qr-print-preview-label">Printing preview</div>
    <a-spin v-if="loading" style="display: block; padding: 40px 0" />
    <div
      v-else
      class="qr-print-grid"
      :style="{ padding: marginMm + 'mm', gap: gapMm + 'mm' }"
    >
      <div v-for="item in items" :key="item.id" class="qr-print-cell" :style="{ width: boxSizeMm + 'mm' }">
        <img v-if="item.qr" :src="item.qr" :alt="item.description" />
        <div v-else class="qr-print-cell__failed">Failed to generate</div>
        <span v-if="displayDescription" class="qr-print-cell__label">{{ item.description }}</span>
      </div>
      <a-empty v-if="!items.length" description="Nothing selected" />
    </div>

    <template #footer>
      <sdButton type="light" @click="close"><unicon name="times"></unicon> Close</sdButton>
      <sdButton type="primary" :disabled="loading || !items.length" @click="printNow">
        <unicon name="print"></unicon> Print
      </sdButton>
    </template>
  </a-modal>
</template>

<style scoped>
.qr-print-params {
  margin-bottom: 20px;
}
.qr-print-params__title {
  font-size: 14px;
  font-weight: 700;
  color: #272b41;
  margin-bottom: 12px;
}
.qr-print-params label {
  display: block;
  font-size: 12px;
  color: #8c90a4;
  margin-bottom: 4px;
}
.qr-print-preview-label {
  font-size: 14px;
  font-weight: 700;
  color: #272b41;
  margin-bottom: 10px;
  border-top: 1px solid #f0f1f5;
  padding-top: 16px;
}
.qr-print-grid {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  max-height: 55vh;
  overflow-y: auto;
  background: #fafbfc;
  border-radius: 8px;
}
.qr-print-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.qr-print-cell img {
  width: 100%;
  height: auto;
}
.qr-print-cell__failed {
  width: 100%;
  aspect-ratio: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: #e5484d;
  border: 1px dashed #e5484d;
}
.qr-print-cell__label {
  font-size: 9px;
  color: #4b5069;
  margin-top: 4px;
  word-break: break-word;
}
</style>

<style>
/* Print only the QR grid, at true physical size — everything else in the
   app (sidebar, header, modal chrome) is hidden for the print pass. */
@media print {
  body * {
    visibility: hidden;
  }
  .qr-preview-modal .qr-print-grid,
  .qr-preview-modal .qr-print-grid * {
    visibility: visible;
  }
  .qr-preview-modal .qr-print-grid {
    position: absolute;
    left: 0;
    top: 0;
    max-height: none;
    overflow: visible;
    background: #fff;
  }
}
</style>
