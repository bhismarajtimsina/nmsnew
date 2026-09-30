<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import HeaderQuickModal from './HeaderQuickModal.vue';

interface NearbyResult {
  type: 'device' | 'interface' | 'box';
  distance_m: number;
  data: any;
}

const open = ref(false);
const filter = ref('all');
const loading = ref(false);
const errorMessage = ref('');
const results = ref<NearbyResult[]>([]);
const router = useRouter();

const DEFAULT_DISTANCE_M = 500;

const typeIcon: Record<string, string> = {
  device: 'server-network',
  interface: 'wifi-router',
  box: 'box',
};

function titleOf(item: NearbyResult): string {
  const d = item.data;
  if (item.type === 'device') return d.name || d.ip || 'Device';
  if (item.type === 'interface') return d.name || 'Interface';
  return d.name || d.title || 'Box';
}

function subtitleOf(item: NearbyResult): string {
  const d = item.data;
  if (item.type === 'device') {
    const model = [d.model?.vendor, d.model?.model || d.model?.name].filter(Boolean).join(' ');
    return model || d.ip || '';
  }
  if (item.type === 'interface') {
    const device = d.device;
    return device ? `on device ${device.name || device.ip}` : '';
  }
  return d.description || '';
}

function distanceLabel(m: number): string {
  if (m == null) return '';
  return m < 1000 ? `${Math.round(m)} m away` : `${(m / 1000).toFixed(2)} km away`;
}

function goTo(item: NearbyResult) {
  const deviceId = item.type === 'device' ? item.data.id : item.data.device_id || item.data.device?.id;
  if (deviceId) {
    router.push({ name: 'device-detail', params: { id: deviceId } });
  }
  open.value = false;
}

function fetchNearby() {
  errorMessage.value = '';
  if (!navigator.geolocation) {
    errorMessage.value = 'Geolocation is not supported by this browser';
    return;
  }
  loading.value = true;
  navigator.geolocation.getCurrentPosition(
    async (position) => {
      try {
        const { data } = await DataService.get('/portal/nearest-elements', {
          lat: position.coords.latitude,
          lon: position.coords.longitude,
          distance: DEFAULT_DISTANCE_M,
          ...(filter.value !== 'all' ? { filter: filter.value } : {}),
        });
        results.value = data.data || [];
      } catch {
        errorMessage.value = 'Could not load nearby objects — please try again.';
      } finally {
        loading.value = false;
      }
    },
    () => {
      errorMessage.value = 'Location access denied';
      loading.value = false;
    },
    { enableHighAccuracy: false, timeout: 8000 },
  );
}

watch(filter, () => {
  if (open.value) fetchNearby();
});

function onOpened() {
  results.value = [];
  errorMessage.value = '';
  fetchNearby();
}
</script>

<template>
  <a href="#" class="header-launcher-btn" title="Nearby objects" @click.prevent="open = true">
    <unicon name="crosshair"></unicon>
  </a>

  <HeaderQuickModal v-model="open" ariaLabel="Nearby objects" @opened="onOpened">
    <template #header="{ close }">
      <div class="no-header">
        <unicon name="crosshair"></unicon>
        <span class="no-title">Nearby objects</span>
        <select v-model="filter" class="no-filter" aria-label="Object type">
          <option value="all">All objects</option>
          <option value="device">Device</option>
          <option value="interface">Interface</option>
          <option value="box">Boxes</option>
        </select>
        <button type="button" class="no-close" aria-label="Close" @click="close">
          <unicon name="times"></unicon>
        </button>
      </div>
    </template>

    <div class="no-results">
      <div v-if="loading" class="no-state">
        <a-spin size="small" />
        <span>Locating…</span>
      </div>
      <div v-else-if="errorMessage" class="no-state error">
        <unicon name="exclamation-circle"></unicon>
        <span>{{ errorMessage }}</span>
        <sdButton size="small" type="primary" @click="fetchNearby">Retry</sdButton>
      </div>
      <div v-else-if="results.length === 0" class="no-state">
        <unicon name="map-marker-alt"></unicon>
        <span>No objects found nearby.</span>
      </div>
      <button v-for="(item, idx) in results" :key="idx" type="button" class="no-result-row" @click="goTo(item)">
        <span class="no-result-icon"><unicon :name="typeIcon[item.type] || 'map-marker-alt'"></unicon></span>
        <span class="no-result-content">
          <span class="no-result-title">{{ titleOf(item) }}</span>
          <span class="no-result-subtitle">{{ subtitleOf(item) }}</span>
        </span>
        <span class="no-result-distance">{{ distanceLabel(item.distance_m) }}</span>
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

.no-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 20px;
  border-bottom: 1px solid #f0f1f5;
}
.no-header > :deep(svg) {
  width: 20px;
  height: 20px;
  color: #1868db;
  flex-shrink: 0;
}
.no-title {
  font-size: 15px;
  font-weight: 700;
  color: #272b41;
  flex: 1;
}
.no-filter {
  border: 1px solid #e3e6ef;
  border-radius: 6px;
  padding: 5px 10px;
  font-size: 13px;
  color: #272b41;
  background: #fff;
}
.no-close {
  border: none;
  background: transparent;
  cursor: pointer;
  display: flex;
  color: #8c90a4;
}

.no-results {
  padding: 6px 0;
}
.no-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  justify-content: center;
  padding: 48px 20px;
  color: #8c90a4;
  font-size: 13px;
  text-align: center;
}
.no-state.error :deep(svg) {
  color: #e5484d;
}
.no-state :deep(svg) {
  width: 22px;
  height: 22px;
}

.no-result-row {
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
.no-result-row:hover {
  background: #f8f9fb;
}
.no-result-icon {
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
.no-result-icon :deep(svg) {
  width: 18px;
  height: 18px;
}
.no-result-content {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.no-result-title {
  font-size: 14px;
  font-weight: 600;
  color: #272b41;
}
.no-result-subtitle {
  font-size: 12px;
  color: #8c90a4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.no-result-distance {
  font-size: 12px;
  font-weight: 600;
  color: #1868db;
  flex-shrink: 0;
  white-space: nowrap;
}
</style>
