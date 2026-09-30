<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import HeaderQuickModal from './HeaderQuickModal.vue';

interface SearchResult {
  type: 'agreement' | 'description' | 'interface' | 'ont_ident' | 'fdb_history' | 'device' | 'tags';
  data: any;
}

const open = ref(false);
const query = ref('');
const results = ref<SearchResult[]>([]);
const loading = ref(false);
const searched = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);
const router = useRouter();

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const statusMeta: Record<string, { label: string; class: string }> = {
  ONLINE: { label: 'Up', class: 'ok' },
  OFFLINE: { label: 'Down', class: 'down' },
  DISABLED: { label: 'Disabled', class: 'muted' },
  ERROR: { label: 'Error', class: 'down' },
  UNKNOWN: { label: 'Unknown', class: 'muted' },
};

const typeIcon: Record<string, string> = {
  device: 'server-network',
  interface: 'wifi-router',
  description: 'comment-alt-message',
  agreement: 'tag-alt',
  ont_ident: 'wifi',
  fdb_history: 'history',
  tags: 'tag-alt',
};

function ifaceOf(item: SearchResult) {
  if (item.type === 'ont_ident' || item.type === 'fdb_history' || item.type === 'tags') {
    return item.data.interface;
  }
  return item.data;
}

function titleOf(item: SearchResult): string {
  const d = item.data;
  switch (item.type) {
    case 'device':
      return `Device: ${d.name || d.ip}`;
    case 'description':
      return `Description: ${d.description || '—'}`;
    case 'agreement':
      return `Agreement: ${d.agreement || '—'}`;
    case 'interface':
      return `Interface: ${d.name || '—'}`;
    case 'ont_ident':
      return `ONT ident: ${d.ident || d.serial || d.value || '—'}`;
    case 'fdb_history':
      return `MAC: ${d.mac_address || '—'}`;
    case 'tags':
      return `Tag: ${d.tags || '—'}`;
    default:
      return 'Result';
  }
}

function subtitleOf(item: SearchResult): string {
  const iface = ifaceOf(item);
  if (item.type === 'device') {
    const model = [d(item).model?.vendor, d(item).model?.model || d(item).model?.name].filter(Boolean).join(' ');
    return model ? `Model: ${model}` : d(item).ip || '';
  }
  const device = iface?.device;
  const devicePart = device ? ` on device ${device.name || device.ip} (${device.ip || ''})` : '';
  const vlanPart = item.type === 'fdb_history' && item.data.vlan_id != null ? `VLAN ${item.data.vlan_id} · ` : '';
  return `${vlanPart}Interface: ${iface?.name || '—'}${devicePart}`;
}

function d(item: SearchResult) {
  return item.data;
}

function statusOf(item: SearchResult) {
  const iface = ifaceOf(item);
  return iface?.status ? statusMeta[iface.status] : null;
}

function goTo(item: SearchResult) {
  const device = item.type === 'device' ? item.data : ifaceOf(item)?.device;
  const deviceId = item.type === 'device' ? item.data.id : ifaceOf(item)?.device_id || device?.id;
  if (deviceId) {
    router.push({ name: 'device-detail', params: { id: deviceId } });
  }
  open.value = false;
}

async function runSearch() {
  const q = query.value.trim();
  if (q.length < 3) {
    results.value = [];
    searched.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await DataService.get('/portal/search', { query: q });
    results.value = data.data || [];
  } catch {
    results.value = [];
  } finally {
    loading.value = false;
    searched.value = true;
  }
}

watch(query, () => {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(runSearch, 350);
});

function onOpened() {
  query.value = '';
  results.value = [];
  searched.value = false;
  setTimeout(() => inputRef.value?.focus(), 50);
}
</script>

<template>
  <a href="#" class="header-launcher-btn" title="Search" @click.prevent="open = true">
    <unicon name="search"></unicon>
  </a>

  <HeaderQuickModal v-model="open" ariaLabel="Search" @opened="onOpened">
    <template #header="{ close }">
      <div class="ps-input-wrap">
        <unicon name="search"></unicon>
        <input
          ref="inputRef"
          v-model="query"
          type="search"
          class="ps-input"
          autocomplete="off"
          placeholder="Searching..."
        />
        <button type="button" class="ps-close" aria-label="Close" @click="close">
          <unicon name="times"></unicon>
        </button>
      </div>
    </template>

    <div class="ps-results">
      <div v-if="loading" class="ps-state">
        <a-spin size="small" />
        <span>Searching…</span>
      </div>
      <div v-else-if="query.trim().length > 0 && query.trim().length < 3" class="ps-state">
        <unicon name="keyboard"></unicon>
        <span>Type more {{ 3 - query.trim().length }} symbols for start searching...</span>
      </div>
      <div v-else-if="searched && results.length === 0" class="ps-state">
        <unicon name="search-alt"></unicon>
        <span>No matches for "{{ query }}"</span>
      </div>
      <div v-else-if="!query" class="ps-state">
        <unicon name="keyboard"></unicon>
        <span>Type more 3 symbols for start searching...</span>
      </div>
      <button
        v-for="(item, idx) in results"
        :key="idx"
        type="button"
        class="ps-result-row"
        @click="goTo(item)"
      >
        <span class="ps-result-icon"><unicon :name="typeIcon[item.type] || 'search'"></unicon></span>
        <span class="ps-result-content">
          <span class="ps-result-title">
            {{ titleOf(item) }}
            <span v-if="statusOf(item)" class="ps-badge" :class="statusOf(item)!.class">{{ statusOf(item)!.label }}</span>
          </span>
          <span class="ps-result-subtitle">{{ subtitleOf(item) }}</span>
        </span>
      </button>
    </div>
  </HeaderQuickModal>
</template>

<style scoped>
.header-launcher-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  color: inherit;
  opacity: 0.85;
}
.header-launcher-btn:hover {
  opacity: 1;
  background: rgba(0, 0, 0, 0.05);
}
.header-launcher-btn :deep(svg) {
  width: 19px;
  height: 19px;
}

.ps-input-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid #f0f1f5;
}
.ps-input-wrap :deep(svg) {
  width: 20px;
  height: 20px;
  color: #8c90a4;
  flex-shrink: 0;
}
.ps-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 16px;
  color: #272b41;
  background: transparent;
}
.ps-close {
  border: none;
  background: transparent;
  cursor: pointer;
  display: flex;
  color: #8c90a4;
}

.ps-results {
  padding: 6px 0;
}
.ps-state {
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: center;
  padding: 40px 20px;
  color: #8c90a4;
  font-size: 13px;
}
.ps-state :deep(svg) {
  width: 18px;
  height: 18px;
}

.ps-result-row {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 20px;
  border: none;
  background: transparent;
  cursor: pointer;
  text-align: left;
}
.ps-result-row:hover {
  background: #f8f9fb;
}
.ps-result-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: rgba(24, 104, 219, 0.1);
  color: #1868db;
  flex-shrink: 0;
}
.ps-result-icon :deep(svg) {
  width: 18px;
  height: 18px;
}
.ps-result-content {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.ps-result-title {
  font-size: 14px;
  font-weight: 600;
  color: #272b41;
  display: flex;
  align-items: center;
  gap: 8px;
}
.ps-result-subtitle {
  font-size: 12px;
  color: #8c90a4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ps-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 1px 8px;
  border-radius: 999px;
  text-transform: uppercase;
}
.ps-badge.ok {
  background: rgba(38, 179, 87, 0.12);
  color: #1a9c50;
}
.ps-badge.down {
  background: rgba(255, 77, 79, 0.12);
  color: #e5484d;
}
.ps-badge.muted {
  background: rgba(140, 144, 164, 0.15);
  color: #8c90a4;
}
</style>
