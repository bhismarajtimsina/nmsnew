<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification } from 'ant-design-vue';
import { Main } from './styled';

interface NearbyResult {
  type: 'device' | 'interface' | 'box';
  distance_m: number;
  data: any;
}

const router = useRouter();
const loading = ref(false);
const results = ref<NearbyResult[]>([]);
const searched = ref(false);
const coords = ref<[number, number] | null>(null);

const filters = reactive({
  distanceKm: 1,
  type: 'all' as 'all' | 'device' | 'interface' | 'box',
  limit: 100,
});

const typeIcon: Record<string, string> = { device: 'server-network', interface: 'wifi-router', box: 'box' };

function titleOf(item: NearbyResult): string {
  const d = item.data;
  if (item.type === 'device') return d.name || d.ip || 'Device';
  if (item.type === 'interface') return d.name || 'Interface';
  return d.name || d.title || 'Box';
}
function subtitleOf(item: NearbyResult): string {
  const d = item.data;
  if (item.type === 'device') return [d.model?.vendor, d.model?.model || d.model?.name].filter(Boolean).join(' ') || d.ip || '';
  if (item.type === 'interface') return d.device ? `on device ${d.device.name || d.device.ip}` : '';
  return d.description || '';
}
function distanceLabel(m: number): string {
  if (m == null) return '';
  return m < 1000 ? `${Math.round(m)} m away` : `${(m / 1000).toFixed(2)} km away`;
}
function goTo(item: NearbyResult) {
  const deviceId = item.type === 'device' ? item.data.id : item.data.device_id || item.data.device?.id;
  if (deviceId) router.push({ name: 'device-detail', params: { id: deviceId } });
}

function useMyLocation() {
  if (!navigator.geolocation) {
    notification.error({ message: 'Geolocation is not supported by this browser' });
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (position) => {
      coords.value = [position.coords.latitude, position.coords.longitude];
      search();
    },
    () => notification.error({ message: 'Location access denied' }),
    { enableHighAccuracy: false, timeout: 8000 },
  );
}

function setFromMap(e: any) {
  // vue-leaflet doesn't declare `click` as a formal component emit, so Vue
  // falls through and also attaches this handler as a native DOM listener
  // on LMap's root element — that invocation gets a plain browser
  // MouseEvent with no `.latlng`, alongside the real Leaflet-level
  // invocation that does. Guard rather than crash on the native one.
  if (!e?.latlng) return;
  coords.value = [e.latlng.lat, e.latlng.lng];
}

async function search() {
  if (!coords.value) {
    notification.error({ message: 'Set coordinates first — click the map or use your current location' });
    return;
  }
  loading.value = true;
  searched.value = true;
  try {
    const { data } = await DataService.get('/portal/nearest-elements', {
      lat: coords.value[0],
      lon: coords.value[1],
      distance: filters.distanceKm * 1000,
      limit: filters.limit,
      ...(filters.type !== 'all' ? { filter: filters.type } : {}),
    });
    results.value = data.data || [];
  } catch (err: any) {
    notification.error({ message: 'Could not load nearby objects', description: err?.response?.data?.error?.description || 'Please try again.' });
  } finally {
    loading.value = false;
  }
}

const mapCenter = computed<[number, number]>(() => coords.value || [27.7, 85.3]);
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Nearby objects' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards title="Nearby objects" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <label class="log-filter-label">Distance</label>
              <a-select v-model:value="filters.distanceKm" style="width: 100%">
                <a-select-option :value="0.1">0.1 km</a-select-option>
                <a-select-option :value="0.5">0.5 km</a-select-option>
                <a-select-option :value="1">1.0 km</a-select-option>
                <a-select-option :value="2">2.0 km</a-select-option>
                <a-select-option :value="5">5.0 km</a-select-option>
                <a-select-option :value="10">10.0 km</a-select-option>
              </a-select>
            </a-col>
            <a-col :xs="12" :md="6" style="margin-bottom: 12px">
              <label class="log-filter-label">Object type</label>
              <a-select v-model:value="filters.type" style="width: 100%">
                <a-select-option value="all">All objects</a-select-option>
                <a-select-option value="device">Device</a-select-option>
                <a-select-option value="interface">Interface</a-select-option>
                <a-select-option value="box">Boxes</a-select-option>
              </a-select>
            </a-col>
            <a-col :xs="12" :md="5" style="margin-bottom: 12px">
              <label class="log-filter-label">Limit</label>
              <a-input-number v-model:value="filters.limit" :min="1" :max="500" style="width: 100%" />
            </a-col>
            <a-col :xs="12" :md="4" style="margin-bottom: 12px; display: flex; align-items: flex-end; gap: 8px">
              <sdButton type="primary" block :loading="loading" @click="search"><unicon name="search"></unicon></sdButton>
            </a-col>
            <a-col :xs="24" :md="4" style="margin-bottom: 12px; display: flex; align-items: flex-end">
              <sdButton block @click="useMyLocation"><unicon name="location-point"></unicon> My location</sdButton>
            </a-col>
          </a-row>
          <p class="log-subtext">
            <unicon name="location-point"></unicon>
            {{ coords ? `${coords[0].toFixed(6)}, ${coords[1].toFixed(6)}` : 'Click the map below to set coordinates, or use your current location.' }}
          </p>
          <l-map :center="mapCenter" :zoom="coords ? 15 : 6" class="nearby-map" @click="setFromMap">
            <l-tile-layer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" attribution="&copy; OpenStreetMap contributors" />
            <l-marker v-if="coords" :lat-lng="coords" />
          </l-map>
        </sdCards>

        <sdCards title="Nearby results">
          <a-skeleton v-if="loading" active />
          <div v-else-if="!searched" class="no-state">
            <unicon name="location-point"></unicon>
            <span>Set coordinates or use your current location</span>
          </div>
          <div v-else-if="!results.length" class="no-state">
            <unicon name="map-marker-alt"></unicon>
            <span>No objects found nearby.</span>
          </div>
          <div v-else class="nearby-list">
            <button v-for="(item, idx) in results" :key="idx" type="button" class="nearby-row" @click="goTo(item)">
              <span class="nearby-row__icon"><unicon :name="typeIcon[item.type] || 'map-marker-alt'"></unicon></span>
              <span class="nearby-row__content">
                <span class="nearby-row__title">{{ titleOf(item) }}</span>
                <span class="nearby-row__subtitle">{{ subtitleOf(item) }}</span>
              </span>
              <span class="nearby-row__distance">{{ distanceLabel(item.distance_m) }}</span>
            </button>
          </div>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.log-filter-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #5a5f7d;
  margin-bottom: 6px;
}
.log-subtext {
  font-size: 12px;
  color: #8c90a4;
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 4px 0 12px;
}
.log-subtext :deep(svg) {
  width: 13px;
  height: 13px;
}
.nearby-map {
  /* vue-leaflet's own root div carries a higher-specificity height rule —
     confirmed the same fix already needed in DeviceFormPage.vue's map. */
  height: 320px !important;
  width: 100%;
  border-radius: 6px;
  overflow: hidden;
}
.no-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 48px 20px;
  color: #8c90a4;
  font-size: 13px;
}
.no-state :deep(svg) {
  width: 22px;
  height: 22px;
}
.nearby-list {
  display: flex;
  flex-direction: column;
}
.nearby-row {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 4px;
  border: none;
  border-bottom: 1px solid #f0f1f5;
  background: transparent;
  cursor: pointer;
  text-align: left;
}
.nearby-row:hover {
  background: #f8f9fb;
}
.nearby-row__icon {
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
.nearby-row__icon :deep(svg) {
  width: 18px;
  height: 18px;
}
.nearby-row__content {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.nearby-row__title {
  font-size: 14px;
  font-weight: 600;
  color: #272b41;
}
.nearby-row__subtitle {
  font-size: 12px;
  color: #8c90a4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nearby-row__distance {
  font-size: 12px;
  font-weight: 600;
  color: #1868db;
  flex-shrink: 0;
  white-space: nowrap;
}
</style>
