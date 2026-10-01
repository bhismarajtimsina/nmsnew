/**
 * Theme and layout state (Plan 25): dark mode, text direction and menu position. Replaces the legacy `themeLayout`
 * Vuex module with the same fields and the same starting values from `src/config/config.ts`.
 *
 * One deliberate difference: the Vuex actions set `loading` and applied the change 10 ms later, and App.vue swapped
 * the whole application for a spinner while `loading` was set, unmounting every page on each toggle. The setters
 * here apply the change at once and there is no `loading`; the theme provider re-renders with the new values.
 */
import { defineStore } from 'pinia';
import config from '@/config/config';

export type MainTemplate = 'lightMode' | 'blackMode';

export const useLayoutStore = defineStore('layout', {
  state: () => ({
    darkMode: config.darkMode,
    rtl: config.rtl,
    topMenu: config.topMenu,
    main: config.mainTemplate as MainTemplate | string,
  }),

  actions: {
    setDarkMode(value: boolean) {
      this.darkMode = value;
      this.main = value ? 'blackMode' : 'lightMode';
    },
    setRtl(value: boolean) {
      this.rtl = value;
    },
    setTopMenu(value: boolean) {
      this.topMenu = value;
    },
  },
});
