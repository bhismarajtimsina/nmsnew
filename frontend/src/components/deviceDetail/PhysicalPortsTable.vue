<!--
  The physical/PON port table itself, extracted so it can be reused both
  flat (models without chassis-card support, e.g. this system's BDCOM OLT)
  and nested inside each card's collapsible panel (chassis models, e.g.
  this system's Huawei OLTs — see CardInterfacesTab.vue).
-->
<script setup lang="ts">
import { tempColor } from '@/utility/opticalColors';
import { formatBytes } from '@/utility/formatters';

interface PhysicalPort {
  interface: { id: number; name: string; type: string; parent?: number };
  oper_status: string;
  nway_status: string;
  description: string;
  counters: { in_errors: string; out_errors: string; in_octets: string; out_octets: string } | null;
  onts: { count: number; online: number; offline: number } | null;
  // GE/SFP ports report {tx_power,rx_power,temp,vcc,tx_bias}; PON ports in
  // this same list report just {tx,temp} — both optional per port.
  optical: { tx_power?: number | null; rx_power?: number | null; tx?: number | null; temp?: number | null; vcc?: number | null } | null;
}

defineProps<{ deviceId: number; interfaces: PhysicalPort[] }>();
const emit = defineEmits<{
  (e: 'go-to-tree', search: string): void;
  (e: 'show-chart', iface: PhysicalPort): void;
  (e: 'show-live-traffic', iface: PhysicalPort): void;
}>();

// Plain abbreviated count (no unit suffix) — for the Errors column, which
// counts discrete error packets, not bytes. The octets/Counters column
// below uses the shared formatBytes() instead, since those genuinely are
// a cumulative byte total.
function fmtCount(n: number) {
  if (!n) return '0';
  if (n > 1e12) return (n / 1e12).toFixed(2) + 'T';
  if (n > 1e9) return (n / 1e9).toFixed(2) + 'G';
  if (n > 1e6) return (n / 1e6).toFixed(2) + 'M';
  if (n > 1e3) return (n / 1e3).toFixed(2) + 'K';
  return String(n);
}
function portColor(onts: PhysicalPort['onts']) {
  if (!onts) return 'gray';
  return onts.online === onts.count ? '#0E4D00' : onts.offline === onts.count ? '#8F0000' : '#b37100';
}
function goToTree(iface: PhysicalPort) {
  const m = iface.interface.name.match(/.*?(\d{1,3})\/(\d{1,3})/i);
  emit('go-to-tree', m ? `${m[1]}/${m[2]}` : iface.interface.name);
}
</script>

<template>
  <a-table :data-source="interfaces" row-key="interface.id" size="small" :pagination="false" :scroll="{ x: 900 }">
    <a-table-column title="Name" :width="160">
      <template #default="{ record }">
        <router-link :to="{ name: 'device-interface-detail', params: { id: deviceId, interface: record.interface.id }, query: { type: 'PHYSICAL' } }">
          <strong>{{ record.interface.name }}</strong>
        </router-link>
        <span v-if="record.interface.type === 'PON'" class="ppt-type">PON</span>
        <span v-if="record.optical?.temp != null" class="ppt-temp" :style="{ background: tempColor(record.optical.temp) }" title="Temperature">
          {{ Math.round(record.optical.temp) }}°C
        </span>
      </template>
    </a-table-column>
    <a-table-column title="Description" data-index="description" :width="140" :ellipsis="true" />
    <a-table-column title="Status" :width="100">
      <template #default="{ record }">
        <span :style="{ fontWeight: 700, color: record.oper_status === 'Up' ? 'darkgreen' : 'darkred' }">{{ record.oper_status }}</span>
      </template>
    </a-table-column>
    <a-table-column title="Speed" :width="90">
      <template #default="{ record }">{{ record.nway_status && record.nway_status !== 'Down' ? record.nway_status : '-' }}</template>
    </a-table-column>
    <a-table-column title="ONTs" :width="100">
      <template #default="{ record }">
        <a v-if="record.onts" class="ppt-badge" :style="{ background: portColor(record.onts) }" @click="goToTree(record)">
          <unicon name="globe" width="12" height="12"></unicon> {{ record.onts.online }}/{{ record.onts.count }}
        </a>
      </template>
    </a-table-column>
    <a-table-column title="Optical" :width="120">
      <template #default="{ record }">
        <template v-if="record.optical">
          <span v-if="record.optical.rx_power != null" class="ppt-optical" title="RX power">{{ record.optical.rx_power }}dBm</span>
          <span v-if="record.optical.tx_power != null" class="ppt-optical" title="TX power">{{ record.optical.tx_power }}dBm</span>
          <span v-if="record.optical.tx != null" class="ppt-optical" title="TX">{{ record.optical.tx }}dBm</span>
        </template>
      </template>
    </a-table-column>
    <a-table-column title="Errors" :width="110">
      <template #default="{ record }">
        <span v-if="record.counters" class="ppt-badge" :style="{ background: record.counters.in_errors !== '0' || record.counters.out_errors !== '0' ? 'darkred' : 'gray' }">
          {{ fmtCount(Number(record.counters.in_errors)) }}/{{ fmtCount(Number(record.counters.out_errors)) }}
        </span>
      </template>
    </a-table-column>
    <a-table-column title="Counters" :width="110">
      <template #default="{ record }">
        <span v-if="record.counters">{{ formatBytes(record.counters.in_octets) }}/{{ formatBytes(record.counters.out_octets) }}</span>
      </template>
    </a-table-column>
    <a-table-column title="" :width="90">
      <template #default="{ record }">
        <a class="ppt-icon-btn ppt-icon-btn--chart" title="Traffic history" @click="emit('show-chart', record)"><unicon name="chart-bar" width="16" height="16"></unicon></a>
        <a class="ppt-icon-btn ppt-icon-btn--live" title="Live traffic" @click="emit('show-live-traffic', record)"><unicon name="heart-rate" width="16" height="16"></unicon></a>
      </template>
    </a-table-column>
  </a-table>
</template>

<style scoped>
.ppt-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
}
.ppt-optical {
  display: inline-block;
  font-size: 11px;
  color: #5a5f7d;
  margin-right: 6px;
  white-space: nowrap;
}
.ppt-type {
  margin-left: 6px;
  font-size: 10px;
  font-weight: 700;
  color: #8c90a4;
  border: 1px solid #e6e9f1;
  border-radius: 3px;
  padding: 0 4px;
}
.ppt-temp {
  display: inline-block;
  margin-left: 6px;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 999px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}
.ppt-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  color: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  transition: transform 0.1s ease, box-shadow 0.1s ease;
}
/* The app's global `.unicon svg { fill: <theme gray> }` rule otherwise
   wins over these buttons' own color, leaving the glyph a barely-visible
   grey on a solid background — force it white here. */
.ppt-icon-btn :deep(svg) {
  fill: #fff !important;
}
.ppt-icon-btn + .ppt-icon-btn {
  margin-left: 6px;
}
.ppt-icon-btn:hover {
  color: #fff;
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.22);
}
.ppt-icon-btn--chart {
  background: #1868db;
}
.ppt-icon-btn--live {
  background: #30a46c;
  animation: ppt-live-pulse 1.8s ease-in-out infinite;
}
@keyframes ppt-live-pulse {
  0%,
  100% {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15), 0 0 0 0 rgba(48, 164, 108, 0.5);
  }
  50% {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15), 0 0 0 5px rgba(48, 164, 108, 0);
  }
}
</style>
