import app from './config/configApp';
import router from './router/index';
import store from './vuex/store';
import { createPinia } from 'pinia';
import { checkSession } from './auth/session';
import 'ant-design-vue/dist/antd.css';
import './assets/main.css';
// Vue 3rd party plugins
import '@/core/plugins/ant-design';
import '@/core/plugins/unicons';
import '@/core/plugins/apexcharts';
import '@/core/plugins/fonts';
import '@/core/plugins/maps';
import '@/core/components/custom';
import '@/core/components/style';

app.use(createPinia());
app.use(store);
app.use(router);

// Validate any stored auth key against the API before the router guard runs
// its first navigation, so a hard refresh with an expired/invalid key drops
// back to the sign-in page instead of showing a stale "logged in" shell.
// Legacy Vuex or the new Pinia store, per VITE_AUTH_BACKEND (src/auth/session.ts).
checkSession().finally(() => {
  app.mount('#app');
});
