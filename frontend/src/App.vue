<script setup lang="ts">
import { RouterView } from 'vue-router';
import { ThemeProvider } from 'vue3-styled-components';
import { themeColor } from './config/theme/themeVariables';
import { computed, onMounted } from 'vue';
import { useLayoutStore } from '@/stores/layout';
import 'v-calendar/dist/style.css';

const layout = useLayoutStore();
const rtl = computed(() => layout.rtl);
const darkMode = computed(() => layout.darkMode);
const topMenu = computed(() => layout.topMenu);
const mainContent = computed(() => layout.main);

onMounted(() => {
  window.addEventListener('load', () => {
    const domHtml = document.getElementsByTagName('html')[0];
    rtl.value ? domHtml.setAttribute('dir', 'rtl') : domHtml.setAttribute('dir', 'ltr');
    darkMode.value ? document.body.classList.add('dark-mode') : '';
  });
});
</script>
<template>
  <ThemeProvider
    :theme="{
      rtl,
      topMenu,
      darkMode,
      mainContent,
      ...themeColor,
    }"
  >
    <Suspense>
      <template #default>
        <RouterView />
      </template>
      <template #fallback>
        <div class="spin">
          <a-spin />
        </div>
      </template>
    </Suspense>
  </ThemeProvider>
</template>
