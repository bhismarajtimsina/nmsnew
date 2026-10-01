<script setup lang="ts">
import { Layout } from 'ant-design-vue';
import { Div, TopMenuSearch } from './style';
import PortalSearch from '../components/header-search/PortalSearch.vue';
import NearbyObjects from '../components/header-search/NearbyObjects.vue';
import AuthInfo from '../components/utilities/auth-info/Info.vue';
import AsideItems from './Aside.vue';
import TopMenu from './TopMenuItems.vue';
import { PerfectScrollbar } from 'vue3-perfect-scrollbar';
import 'vue3-perfect-scrollbar/style.css';
import { computed, ref } from 'vue';
import { useLayoutStore } from '@/stores/layout';
//import Customizer from './overview/Customizer.vue';

const { Header, Footer, Sider, Content } = Layout;
const collapsed = ref(false);
//const customizerAction = ref(false);

const layout = useLayoutStore();

const rtl = computed(() => layout.rtl);
const darkMode = computed(() => layout.darkMode);
const topMenu = computed(() => layout.topMenu);
const innerWidth: number = window.innerWidth;
collapsed.value = window.innerWidth <= 1200 && true;

const toggleCollapsed = (e: Event) => {
  e.preventDefault();
  collapsed.value = !collapsed.value;
};
const toggleCollapsedMobile = () => {
  // const aside = document.querySelector(".ps--active-y");
  // aside.scrollTop = 0;

  if (innerWidth <= 990) {
    collapsed.value = !collapsed.value;
  }
};
if (innerWidth <= 990) {
  document.body.addEventListener('click', (e: any) => {
    if (!e.target.closest('.ant-layout-sider') && !e.target.closest('.navbar-brand .ant-btn')) {
      collapsed.value = true;
    }
  });
}

const onRtlChange = () => {
  const html: any = document.querySelector('html');
  html.setAttribute('dir', 'rtl');
  layout.setRtl(true);
};

const onLtrChange = () => {
  const html: any = document.querySelector('html');
  html.setAttribute('dir', 'ltr');
  layout.setRtl(false);
};

const modeChangeDark = () => {
  layout.setDarkMode(true);
};

const modeChangeLight = () => {
  layout.setDarkMode(false);
};

const modeChangeTopNav = () => {
  layout.setTopMenu(true);
};

const modeChangeSideNav = () => {
  layout.setTopMenu(false);
};

const onEventChange = {
  onRtlChange,
  onLtrChange,
  modeChangeDark,
  modeChangeLight,
  modeChangeTopNav,
  modeChangeSideNav,
};
</script>

<template>
  <Div :darkMode="darkMode">
    <Layout class="layout">
      <Header
        :style="{
          position: 'fixed',
          width: '100%',
          top: 0,
          [!rtl ? 'left' : 'right']: 0,
        }"
      >
        <div class="ninjadash-header-content d-flex">
          <div class="ninjadash-header-content__left">
            <div class="navbar-brand align-cener-v">
              <router-link :class="topMenu && innerWidth > 991 ? 'ninjadash-logo top-menu' : 'ninjadash-logo'" to="/">
                <img
                  v-if="!collapsed"
                  src="/src/assets/img/logo-cybersathy-full.png"
                  alt="CyberSathy"
                  style="height: 55px; width: auto"
                />
                <img
                  v-else
                  src="/src/assets/img/logo-cybersathy-icon.png"
                  alt="CyberSathy"
                  style="height: 40px; width: auto"
                />
              </router-link>
              <sdButton v-if="!topMenu || innerWidth <= 991" @click="toggleCollapsed" type="white">
                <img src="/src/assets/img/icon/align-center-alt.svg" alt="menu" />
              </sdButton>
            </div>
          </div>
          <div class="ninjadash-header-launchers d-flex align-center-v">
            <PortalSearch />
            <NearbyObjects />
          </div>
          <div class="ninjadash-header-content__right d-flex">
            <div class="ninjadash-navbar-menu d-flex align-center-v">
              <TopMenu v-if="topMenu && innerWidth > 991" />
            </div>
            <div class="ninjadash-nav-actions">
              <TopMenuSearch v-if="topMenu && innerWidth > 991">
                <div class="top-right-wrap d-flex">
                  <AuthInfo />
                </div>
              </TopMenuSearch>
              <AuthInfo v-else />
            </div>
          </div>
        </div>
      </Header>
      <Layout>
        <template v-if="!topMenu || innerWidth <= 991">
          <Sider
            :width="280"
            :style="{
              margin: '72px 0 0 0',
              padding: `${!rtl ? '20px 20px 55px 0px' : '20px 0px 55px 20px'}`,
              overflowY: 'auto',
              height: '100vh',
              position: 'fixed',
              [!rtl ? 'left' : 'right']: 0,
              zIndex: 998,
            }"
            :collapsed="collapsed"
            :theme="!darkMode ? 'light' : 'dark'"
          >
            <perfect-scrollbar
              :options="{
                wheelSpeed: 1,
                swipeEasing: true,
                suppressScrollX: true,
              }"
            >
              <AsideItems
                :toggleCollapsed="toggleCollapsedMobile"
                :topMenu="topMenu"
                :rtl="rtl"
                :darkMode="darkMode"
                :events="onEventChange"
              />
            </perfect-scrollbar>
          </Sider>
        </template>
        <Layout class="ninjadash-main-layout">
          <Content>
            <Suspense>
              <template #default>
                <router-view></router-view>
              </template>
              <template #fallback>
                <div class="spin">
                  <a-spin />
                </div>
              </template>
            </Suspense>

            <Footer
              class="admin-footer"
              :style="{
                padding: '20px 30px 18px',
                color: 'rgba(0, 0, 0, 0.65)',
                fontSize: '14px',
                background: 'rgba(255, 255, 255, .90)',
                width: '100%',
                boxShadow: '0 -5px 10px rgba(146,153,184, 0.05)',
              }"
            >
              <a-row>
                <a-col :span="24">
                  <span class="admin-footer__copyright">© 2026 CyberSathy IT and Technology Pvt LTD</span>
                </a-col>
              </a-row>
            </Footer>
          </Content>
        </Layout>
      </Layout>
    </Layout>
  </Div>
</template>

<style scoped>
.ninjadash-header-launchers {
  gap: 4px;
  margin-left: 8px;
  color: #5a5f7d;
}
@media only screen and (max-width: 767px) {
  .ninjadash-header-launchers {
    margin-left: 4px;
  }
  /* The template hides nav-actions (notification bell + account menu) below
     767px in favor of its own collapsible "..." panel, which we removed —
     keep the real notification/account controls visible directly instead. */
  :deep(.ninjadash-nav-actions) {
    display: flex !important;
  }
}
.ps {
  height: calc(100vh - 100px);
}
.ant-layout-sider-collapsed .ps {
  height: calc(100vh - 70px);
}
</style>
