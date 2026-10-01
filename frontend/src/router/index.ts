import { createWebHistory, createRouter } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import adminRoutes from './adminRoutes';
import authRoutes from './authRoutes';
import { decide } from './guard';
import { isLoggedIn } from '@/auth/session';

const routes: Array<RouteRecordRaw> = [
  {
    name: 'Admin',
    path: '/',
    component: () => import('../layout/AdminLayout.vue'),
    children: [...adminRoutes],
    meta: { auth: false },
  },
  {
    name: 'Auth',
    path: '/auth',
    component: () => import('../layout/AuthLayout.vue'),
    children: [...authRoutes],
    meta: { auth: true },
  },
];

const router = createRouter({
  history: createWebHistory(),
  linkExactActiveClass: 'active',
  routes,
});

router.beforeEach((to, from, next) => {
  const decision = decide(!!to.meta.auth, isLoggedIn());
  if (decision === true) next();
  else next(decision);
  window.scrollTo(0, 0); // reset scroll position to top of page
});

export default router;
