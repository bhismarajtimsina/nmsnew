<script setup lang="ts">
/**
 * The dashboard under the new login (Plans 23 and 25): the viewer's role dashboards from GET /dashboards, each widget
 * read from its own scoped endpoint (src/views/dashboards.ts). The server decides which dashboards and widgets a
 * viewer has; a pending widget shows why, and a widget that fails says so on its own card. The legacy build keeps
 * DashboardPage.vue; the router picks one or the other (src/router/wcaRoutes.ts).
 */
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import {
  errorMessage,
  loadDashboards,
  loadWidget,
  pickDashboard,
  supported,
  type DashboardInfo,
  type Display,
} from './dashboards';
import { Main } from './styled';

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const failed = ref<string | null>(null);
const dashboards = ref<DashboardInfo[]>([]);
const current = ref<DashboardInfo | null>(null);
const cards = reactive<Record<string, { loading: boolean; display: Display | null; error: string | null }>>({});

async function loadCards(dashboard: DashboardInfo) {
  await Promise.all(
    dashboard.widgets
      .filter((w) => w.state === 'live' && supported(w.key))
      .map(async (w) => {
        cards[w.key] = { loading: true, display: null, error: null };
        try {
          cards[w.key] = { loading: false, display: await loadWidget(api, w.key), error: null };
        } catch (err) {
          cards[w.key] = { loading: false, display: null, error: errorMessage(err) };
        }
      }),
  );
}

const defaultKey = ref<string | null>(null);

async function open(key: string | null) {
  current.value = pickDashboard(dashboards.value, key, defaultKey.value);
  if (!current.value) return;
  if (route.query.dashboard !== current.value.key) router.replace({ query: { dashboard: current.value.key } });
  await loadCards(current.value);
}

async function load() {
  loading.value = true;
  failed.value = null;
  try {
    const list = await loadDashboards(api);
    dashboards.value = list.dashboards;
    defaultKey.value = list.default;
    await open(typeof route.query.dashboard === 'string' ? route.query.dashboard : null);
  } catch (err) {
    failed.value = errorMessage(err);
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <sdPageHeader :routes="[{ path: '', breadcrumbName: 'Dashboard' }]" class="ninjadash-page-header-main">
    <template #buttons>
      <sdButton type="default" :disabled="loading" @click="load"><unicon name="redo"></unicon> Reload</sdButton>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <a-alert v-else-if="failed" type="error" show-icon message="Could not load your dashboards" :description="failed" />
    <a-empty v-else-if="!current" description="No dashboard is available for your role" />
    <template v-else>
      <a-radio-group
        v-if="dashboards.length > 1"
        :value="current.key"
        button-style="solid"
        style="margin-bottom: 16px"
        @change="(e: any) => open(e.target.value)"
      >
        <a-radio-button v-for="d in dashboards" :key="d.key" :value="d.key">{{ d.title }}</a-radio-button>
      </a-radio-group>
      <a-row :gutter="[16, 16]">
        <a-col v-for="w in current.widgets" :key="w.key" :xs="24" :lg="12" :xxl="8">
          <sdCards :title="w.title" class="dash-card">
            <p v-if="w.state !== 'live'" class="dash-pending">
              <unicon name="clock"></unicon> Coming later: {{ w.pendingReason }}
            </p>
            <p v-else-if="!supported(w.key)" class="dash-pending">This widget is not supported by this page yet</p>
            <a-skeleton v-else-if="!cards[w.key] || cards[w.key].loading" active :paragraph="{ rows: 2 }" />
            <a-alert v-else-if="cards[w.key].error" type="warning" show-icon :message="cards[w.key].error" />
            <template v-else-if="cards[w.key].display">
              <div v-if="cards[w.key].display!.kind === 'stats'" class="dash-stats">
                <div
                  v-for="s in (cards[w.key].display as any).stats"
                  :key="s.label"
                  class="dash-stat"
                  :class="s.tone ? `is-${s.tone}` : ''"
                >
                  <div class="dash-stat__value">{{ s.value }}</div>
                  <div class="dash-stat__label">{{ s.label }}</div>
                </div>
              </div>
              <template v-else-if="cards[w.key].display!.kind === 'bars'">
                <a-empty v-if="!(cards[w.key].display as any).items.length" description="Nothing open" />
                <div v-for="item in (cards[w.key].display as any).items" :key="item.label" class="dash-bar">
                  <span class="dash-bar__label">{{ item.label }}</span>
                  <a-progress
                    :percent="Math.round((100 * item.value) / Math.max(...(cards[w.key].display as any).items.map((i: any) => i.value)))"
                    :format="() => item.value"
                    size="small"
                  />
                </div>
              </template>
              <template v-else>
                <a-table
                  :data-source="(cards[w.key].display as any).rows.map((r: any[], i: number) => ({ key: i, cells: r }))"
                  :pagination="false"
                  size="small"
                  :locale="{ emptyText: 'Nothing to show' }"
                >
                  <a-table-column v-for="(c, i) in (cards[w.key].display as any).columns" :key="c" :title="c">
                    <template #default="{ record }">{{ record.cells[i] }}</template>
                  </a-table-column>
                </a-table>
                <p v-if="(cards[w.key].display as any).note" class="dash-note">
                  {{ (cards[w.key].display as any).note }}
                </p>
              </template>
            </template>
          </sdCards>
        </a-col>
      </a-row>
    </template>
  </Main>
</template>

<style scoped>
.dash-pending {
  color: #8c90a4;
  margin: 0;
}
.dash-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.dash-stat {
  flex: 1 1 90px;
  border-radius: 8px;
  background: #f4f5f7;
  padding: 10px 12px;
}
.dash-stat__value {
  font-size: 22px;
  font-weight: 700;
  color: #272b41;
}
.dash-stat__label {
  font-size: 12px;
  color: #5a5f7d;
}
.dash-stat.is-good .dash-stat__value {
  color: #1a7a3a;
}
.dash-stat.is-bad .dash-stat__value {
  color: #a60a0a;
}
.dash-stat.is-warn .dash-stat__value {
  color: #ab7d0a;
}
.dash-bar {
  display: grid;
  grid-template-columns: minmax(80px, 40%) 1fr;
  align-items: center;
  gap: 8px;
}
.dash-bar__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dash-note {
  margin: 8px 0 0;
  font-size: 12px;
  color: #8c90a4;
}
</style>
