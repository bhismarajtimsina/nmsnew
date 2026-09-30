<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { Main } from './styled';

const route = useRoute();
const title = computed(() => (route.meta.title as string) || (route.name as string) || 'Untitled page');
const apiPaths = computed(() => (route.meta.apiPaths as string[]) || []);
const pageRoutes = computed(() => [{ path: route.path, breadcrumbName: title.value }]);
</script>

<template>
  <sdPageHeader :routes="pageRoutes" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24">
        <sdCards :title="title">
          <a-empty description="Not built yet — route and navigation are wired, page content is next." />
          <ul v-if="apiPaths.length" style="margin-top: 16px; font-size: 12px; color: #999">
            <li>Backend endpoint(s) this page will use:</li>
            <li v-for="p in apiPaths" :key="p"><code>{{ p }}</code></li>
          </ul>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>
