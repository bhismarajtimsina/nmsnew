<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { wsClient } from '@/services/wsClient';
import { mergeById, removeById } from '@/utility/listMerge';
import { Main } from '../styled';

const props = defineProps<{
  variant: 'macros' | 'onts-registration';
}>();

const router = useRouter();
const apiBase = props.variant === 'macros' ? '/component/macros/control' : '/component/onts_registration/control';
const pageTitle = props.variant === 'macros' ? 'Macros' : 'List macros for ONTs registration';

interface MacroRow {
  id: number;
  name: string;
  description?: string;
  display_for?: string[];
  models: { id: number; key: string; name: string; vendor?: string }[];
  user_roles?: { id: number; name: string }[];
  created_at?: string;
  updated_at?: string;
  enabled?: boolean;
}

const loading = ref(true);
const rows = ref<MacroRow[]>([]);
const filters = reactive({ name: '', description: '' });

const filteredRows = computed(() =>
  rows.value.filter(
    (r) =>
      (!filters.name || r.name.toLowerCase().includes(filters.name.toLowerCase())) &&
      (!filters.description || (r.description || '').toLowerCase().includes(filters.description.toLowerCase())),
  ),
);

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get(apiBase, { limit: 200 });
    rows.value = data.data || [];
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// Real-time: CreateMacros/UpdateMacros now resolve models/user_roles via
// real storage lookups (fixed alongside this — they used to build bare
// id-only stubs, which would have serialized as near-empty objects here),
// so the pushed record is safe to merge directly. One naming quirk: the
// raw Macros model's property is `allowed_roles`, not `user_roles` (the
// REST endpoint's `user_roles` key is a manual rename in the controller,
// not something getAsArray() does) — mapped below so it still lands on
// the right field instead of silently not updating.
function toMacroRow(data: any): MacroRow {
  const { allowed_roles, ...rest } = data;
  return { ...rest, user_roles: allowed_roles ?? data.user_roles };
}
const unsubAdded = wsClient.subscribe('event:storage:c_macros:added', (msg) => mergeById(rows, toMacroRow(msg.data)));
const unsubUpdated = wsClient.subscribe('event:storage:c_macros:updated', (msg) => mergeById(rows, toMacroRow(msg.data)));
const unsubDeleted = wsClient.subscribe('event:storage:c_macros:deleted', (msg) => removeById(rows, msg.data.id));
onBeforeUnmount(() => {
  unsubAdded();
  unsubUpdated();
  unsubDeleted();
});

function goCreate() {
  router.push({ name: props.variant === 'macros' ? 'macros-create' : 'onts-registration-create' });
}
function goEdit(row: MacroRow) {
  router.push({ name: props.variant === 'macros' ? 'macros-edit' : 'onts-registration-edit', params: { id: row.id } });
}

function confirmDelete(row: MacroRow) {
  Modal.confirm({
    title: `Delete macro "${row.name}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      try {
        await DataService.delete(`${apiBase}/${row.id}`);
        rows.value = rows.value.filter((r) => r.id !== row.id);
        notification.success({ message: 'Macro deleted' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete macro',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      }
    },
  });
}

async function cloneRow(row: MacroRow) {
  try {
    await DataService.put(`${apiBase}/clone/${row.id}`, {});
    notification.success({ message: 'Macro cloned' });
    await load();
  } catch (err: any) {
    notification.error({
      message: 'Could not clone macro',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  }
}

// --- Export/Import — the real app's row "download" icon and header "Import"
// button both work on plain JSON files (its hidden <input type=file
// accept="application/json"> confirms the format; there's no dedicated
// bulk-import API route in this component). Export downloads a row exactly
// as returned by GET /control/{id}.
//
// On the real production app, picking a file for Import POSTs and creates
// the macro immediately with zero review step — confirmed directly (that's
// how a stray "test-imported-macro" ended up created on the live instance
// during verification, then cleaned up). This rebuild deliberately adds a
// review step instead: the file is parsed and the Create page is opened
// pre-filled, and the user still has to click Create themselves.
function exportRow(row: MacroRow) {
  const blob = new Blob([JSON.stringify(row, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${row.name || 'macro'}.json`;
  a.click();
  URL.revokeObjectURL(url);
}

const importInput = ref<HTMLInputElement | null>(null);
function triggerImport() {
  importInput.value?.click();
}
function onImportFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    try {
      JSON.parse(reader.result as string);
    } catch {
      notification.error({ message: 'Invalid JSON file' });
      return;
    }
    sessionStorage.setItem(`macro-import-${props.variant}`, reader.result as string);
    notification.success({ message: 'Imported — review and click Create to save' });
    router.push({ name: props.variant === 'macros' ? 'macros-create' : 'onts-registration-create', query: { imported: '1' } });
  };
  reader.readAsText(file);
  (e.target as HTMLInputElement).value = '';
}
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: pageTitle }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <div class="macro-toolbar">
          <sdButton type="primary" @click="goCreate"><unicon name="plus"></unicon> Add {{ variant === 'macros' ? 'new' : 'macro' }}</sdButton>
          <sdButton type="light" @click="triggerImport"><unicon name="import"></unicon> Import{{ variant === 'macros' ? '' : ' macro' }}</sdButton>
          <input ref="importInput" type="file" accept="application/json" style="display: none" @change="onImportFile" />
        </div>
      </a-col>
    </a-row>

    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :headless="true">
          <div class="macro-filter-row">
            <a-input v-model:value="filters.name" placeholder="Filter by name" style="max-width: 260px" />
            <a-input
              v-if="variant === 'macros'"
              v-model:value="filters.description"
              placeholder="Filter by description"
              style="max-width: 260px"
            />
          </div>
          <a-skeleton v-if="loading" active />
          <a-table v-else :data-source="filteredRows" row-key="id" size="small" :scroll="{ x: 700 }" :pagination="{ pageSize: 20 }">
            <a-table-column title="Id" data-index="id" :width="70" />
            <a-table-column title="Name" data-index="name" />
            <template v-if="variant === 'macros'">
              <a-table-column title="Description" data-index="description" />
              <a-table-column title="Display for">
                <template #default="{ record }">{{ (record.display_for || []).join(', ') || '—' }}</template>
              </a-table-column>
              <a-table-column title="User roles">
                <template #default="{ record }">{{ (record.user_roles || []).map((r: any) => r.name).join(', ') || '—' }}</template>
              </a-table-column>
            </template>
            <template v-else>
              <a-table-column title="Created at" data-index="created_at" :width="170" />
              <a-table-column title="Updated at" data-index="updated_at" :width="170" />
              <a-table-column title="Enabled" :width="90">
                <template #default="{ record }">{{ record.enabled ? 'Yes' : 'No' }}</template>
              </a-table-column>
            </template>
            <a-table-column title="Models">
              <template #default="{ record }">{{ (record.models || []).map((m: any) => m.name).join(', ') || '—' }}</template>
            </a-table-column>
            <a-table-column title="" :width="150">
              <template #default="{ record }">
                <a @click="goEdit(record)" title="Edit"><unicon name="edit"></unicon></a>
                <a class="macro-row__delete" @click="confirmDelete(record)" title="Delete"><unicon name="trash-alt"></unicon></a>
                <a @click="cloneRow(record)" title="Clone"><unicon name="copy"></unicon></a>
                <a @click="exportRow(record)" title="Export"><unicon name="import"></unicon></a>
              </template>
            </a-table-column>
          </a-table>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.macro-toolbar {
  display: flex;
  gap: 10px;
}
.macro-toolbar :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.macro-filter-row {
  display: flex;
  gap: 16px;
  padding: 16px 16px 16px 0;
}
:deep(.ant-table) a {
  margin-right: 12px;
  color: #8c90a4;
}
:deep(.ant-table) a:hover {
  color: #1868db;
}
:deep(.ant-table) .macro-row__delete:hover {
  color: #e5484d;
}
</style>
