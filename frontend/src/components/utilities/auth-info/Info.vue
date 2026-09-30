<script setup lang="ts">
import { computed, ref } from 'vue';
import { InfoWraper } from './auth-info-style';
import Notification from './Notification.vue';
import { useStore } from 'vuex';
import { useRouter } from 'vue-router';

const { dispatch, state } = useStore();
const { push } = useRouter();

const user = computed(() => state.auth.user);
const userName = computed(() => user.value?.name || 'Unknown user');
const userLogin = computed(() => user.value?.login || '');
const userRole = computed(() => user.value?.role?.name || '');
const userInitial = computed(() => (userName.value?.[0] || '?').toUpperCase());

// sdPopover only closes itself on trigger click / outside click — a link
// clicked *inside* its content (e.g. "Account settings") navigates but
// leaves the popover open on top of the new page, since navigating within
// AdminLayout doesn't unmount it. Popup.vue exposes its internal `visible`
// ref on the instance, so reach in and close it explicitly on click.
const accountMenuRef = ref<any>(null);
const closeAccountMenu = () => {
  if (accountMenuRef.value) accountMenuRef.value.visible = false;
};

const SignOut = async (e: any) => {
  e.preventDefault();
  closeAccountMenu();
  await dispatch('logOut');
  push('/auth/login');
};
</script>

<template>
  <InfoWraper>
    <Notification />

    <div class="ninjadash-nav-actions__item ninjadash-nav-actions__author">
      <sdPopover ref="accountMenuRef" placement="bottomRight" action="click" overlay-class-name="account-menu-popover">
        <template v-slot:content>
          <div class="account-menu">
            <div class="account-menu__header">
              <span class="account-menu__avatar">{{ userInitial }}</span>
              <div class="account-menu__identity">
                <p class="account-menu__name">{{ userName }}</p>
                <p v-if="userLogin" class="account-menu__login">@{{ userLogin }}</p>
                <span v-if="userRole" class="account-menu__role">
                  <unicon name="shield"></unicon>
                  {{ userRole }}
                </span>
              </div>
            </div>

            <div class="account-menu__divider"></div>

            <ul class="account-menu__list">
              <li>
                <router-link :to="{ name: 'account-settings' }" class="account-menu__item" @click="closeAccountMenu">
                  <span class="account-menu__item-icon"><unicon name="setting"></unicon></span>
                  <span>Account settings</span>
                </router-link>
              </li>
            </ul>

            <div class="account-menu__divider"></div>

            <button type="button" class="account-menu__item account-menu__item--danger" @click="SignOut">
              <span class="account-menu__item-icon"><unicon name="signout"></unicon></span>
              <span>Sign out</span>
            </button>
          </div>
        </template>
        <a to="#" class="ninjadash-nav-action-link">
          <span class="ninjadash-nav-actions__author-avatar">{{ userInitial }}</span>
          <span class="ninjadash-nav-actions__author--name">{{ userName }}</span>
          <unicon name="angle-down"></unicon>
        </a>
      </sdPopover>
    </div>
  </InfoWraper>
</template>

<style scoped>
.ninjadash-nav-actions__author-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, #1868db, #2f6fed);
  color: #ffffff;
  font-size: 13px;
  font-weight: 700;
  margin-right: 8px;
}

.account-menu {
  width: 268px;
  background: #ffffff;
  border-radius: 12px;
  overflow: hidden;
}

.account-menu__header {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 20px;
  background: linear-gradient(135deg, rgba(24, 104, 219, 0.08), rgba(47, 111, 237, 0.03));
}
.account-menu__avatar {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: linear-gradient(135deg, #1868db, #2f6fed);
  color: #ffffff;
  font-size: 18px;
  font-weight: 700;
  box-shadow: 0 4px 10px rgba(24, 104, 219, 0.3);
}
.account-menu__identity {
  min-width: 0;
}
.account-menu__name {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #272b41;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.account-menu__login {
  margin: 1px 0 6px;
  font-size: 12px;
  color: #8c90a4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.account-menu__role {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 9px;
  border-radius: 999px;
  background: rgba(24, 104, 219, 0.12);
  color: #1868db;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.account-menu__role :deep(svg) {
  width: 11px;
  height: 11px;
  fill: #1868db;
}

.account-menu__divider {
  height: 1px;
  margin: 4px 0;
  background: #f0f1f5;
}

.account-menu__list {
  margin: 0;
  padding: 6px;
  list-style: none;
}

.account-menu__item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  margin: 0 6px 0 0;
  border: none;
  background: transparent;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  color: #4b5069;
  text-align: left;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}
.account-menu__list .account-menu__item {
  margin: 0;
}
.account-menu__item:hover {
  background: rgba(24, 104, 219, 0.08);
  color: #1868db;
}
.account-menu__item:hover .account-menu__item-icon :deep(svg) {
  fill: #1868db;
}
.account-menu__item-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 18px;
  height: 18px;
}
.account-menu__item-icon :deep(svg) {
  width: 17px;
  height: 17px;
  fill: #8c90a4;
  transition: fill 0.15s ease;
}

.account-menu__item--danger {
  margin: 6px;
  width: calc(100% - 12px);
}
.account-menu__item--danger:hover {
  background: rgba(229, 72, 77, 0.08);
  color: #e5484d;
}
.account-menu__item--danger:hover .account-menu__item-icon :deep(svg) {
  fill: #e5484d;
}
</style>

<style>
/* The account-menu card renders its own background/shadow/radius edge to
   edge — the template's global .ant-popover-inner padding (main.css) would
   otherwise leave a 15px band of empty space around it. This popover is
   rendered outside the component tree (vc-trigger, not <Teleport>), so a
   scoped style can't reach it — hence the unscoped block + overlayClassName. */
.account-menu-popover .ant-popover-inner {
  padding: 0;
  border-radius: 12px;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.14);
}
/* main.css forces every popover to full viewport width under 480px (built
   for the old edge-to-edge mobile dropdown design) — this card is meant to
   stay a compact fixed-width menu instead, so opt back out of that. */
@media only screen and (max-width: 480px) {
  .account-menu-popover.ant-popover {
    width: auto;
  }
}
</style>
