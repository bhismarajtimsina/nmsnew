<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { PerfectScrollbar } from 'vue3-perfect-scrollbar';
import 'vue3-perfect-scrollbar/style.css';
import { NinjadashTopDropdown } from './auth-info-style';
import { DataService } from '@/config/dataService/dataService';
import { wsClient } from '@/services/wsClient';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

interface WcaEvent {
  id: number;
  annotation: string;
  description: string;
  severity: 'INFO' | 'WARNING' | 'CRITICAL';
  created_at: string;
  device?: { name: string };
}

const events = ref<WcaEvent[]>([]);
const badgeCount = ref(0);
const loading = ref(true);

const severityMeta: Record<string, { icon: string; class: string }> = {
  CRITICAL: { icon: 'exclamation-triangle', class: 'bg-danger' },
  WARNING: { icon: 'bell', class: 'bg-warning' },
  INFO: { icon: 'info-circle', class: 'bg-primary' },
};

const hasEvents = computed(() => events.value.length > 0);

// Same close-on-navigate fix as the account menu: this popover stays open
// after a router-link inside it is clicked, since routing to /logs/events
// doesn't unmount AdminLayout. Popup.vue exposes its internal `visible` ref.
const notifPopoverRef = ref<any>(null);
const closeNotifDropdown = () => {
  if (notifPopoverRef.value) notifPopoverRef.value.visible = false;
};

async function load() {
  try {
    const [statRes, listRes] = await Promise.all([
      DataService.get('/component/events/severity-stat'),
      DataService.get('/component/events', { not_resolved: true, limit: 6 }),
    ]);
    const stat = statRes.data.data;
    badgeCount.value = (stat.WARNING || 0) + (stat.CRITICAL || 0);
    events.value = (listRes.data.data || []).slice(0, 6);
  } catch (err) {
    // Top-header widget — fail quietly rather than blocking the whole shell.
    console.error('Failed to load event notifications', err);
  } finally {
    loading.value = false;
  }
}

onMounted(load);

// Real-time: this bell had no refresh mechanism at all before — a fresh
// alert (or a change to the events table, e.g. someone resolving one)
// updates the badge/list immediately instead of only on next page load.
const unsubWebhook = wsClient.subscribe('event:webhook:alertmanager', () => load());
const unsubAdded = wsClient.subscribe('event:storage:c_events:added', () => load());
const unsubUpdated = wsClient.subscribe('event:storage:c_events:updated', () => load());
onBeforeUnmount(() => {
  unsubWebhook();
  unsubAdded();
  unsubUpdated();
});
</script>

<template>
  <div class="ninjadash-nav-actions__item ninjadash-nav-actions__notification">
    <sdPopover ref="notifPopoverRef" placement="bottomLeft" action="click">
      <template v-slot:content>
        <NinjadashTopDropdown class="ninjadash-top-dropdown">
          <sdHeading as="h5" class="ninjadash-top-dropdown__title">
            <span class="title-text">Unresolved events</span>
            <a-badge v-if="badgeCount" class="badge-danger" :count="badgeCount" :overflow-count="99" />
          </sdHeading>
          <perfect-scrollbar
            :options="{
              wheelSpeed: 1,
              swipeEasing: true,
              suppressScrollX: true,
            }"
          >
            <ul v-if="hasEvents" class="ninjadash-top-dropdown__nav notification-list">
              <li v-for="ev in events" :key="ev.id">
                <router-link :to="{ name: 'events' }" @click="closeNotifDropdown">
                  <div class="ninjadash-top-dropdown__content notifications">
                    <div class="notification-icon" :class="severityMeta[ev.severity]?.class || 'bg-primary'">
                      <unicon :name="severityMeta[ev.severity]?.icon || 'bell'"></unicon>
                    </div>
                    <div class="notification-content d-flex">
                      <div class="notification-text">
                        <sdHeading as="h5">{{ ev.annotation }}</sdHeading>
                        <p>{{ ev.device?.name ? ev.device.name + ' · ' : '' }}{{ dayjs(ev.created_at).fromNow() }}</p>
                      </div>
                      <div class="notification-status">
                        <a-badge dot />
                      </div>
                    </div>
                  </div>
                </router-link>
              </li>
            </ul>
            <div v-else-if="!loading" class="notification-empty">No unresolved events right now.</div>
          </perfect-scrollbar>
          <router-link class="btn-seeAll" :to="{ name: 'events' }" @click="closeNotifDropdown"> See all events </router-link>
        </NinjadashTopDropdown>
      </template>
      <a-badge :dot="badgeCount > 0" :offset="[-8, -5]">
        <a to="#" class="ninjadash-nav-action-link">
          <img src="/src/assets/img/icon/alarm.svg" />
        </a>
      </a-badge>
    </sdPopover>
  </div>
</template>

<style scoped>
.ps {
  height: 200px;
}
.notification-empty {
  padding: 24px 20px;
  text-align: center;
  font-size: 13px;
  color: #8c90a4;
}
.badge-danger :deep(.ant-badge-count) {
  background: #e5484d;
  box-shadow: none;
}
</style>
