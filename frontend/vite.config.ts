import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import vueJsx from '@vitejs/plugin-vue-jsx';
import Components from 'unplugin-vue-components/vite';
import { AntDesignVueResolver } from 'unplugin-vue-components/resolvers';
import { theme } from './src/config/theme/themeVariables.ts';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    Components({
      resolvers: [AntDesignVueResolver()],
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // The installed svgmap version's package.json "exports" field only
      // whitelists the main entry, blocking the dist/*.css subpath this
      // template imports directly even though the file exists on disk.
      'svgmap/dist/svgMap.min.css': fileURLToPath(
        new URL('./node_modules/svgmap/dist/svgMap.min.css', import.meta.url),
      ),
    },
  },
  css: {
    preprocessorOptions: {
      less: {
        modifyVars: {
          ...theme,
        },
        javascriptEnabled: true,
      },
    },
  },
  base:
    process.env.NODE_ENV === 'production'
      ? process.env.VITE_SUB_ROUTE
        ? process.env.VITE_SUB_ROUTE
        : process.env.BASE_URL
      : process.env.BASE_URL,
  server: {
    // Dev-only proxy to the real WCA backend, avoids CORS and keeps the
    // frontend code talking to a same-origin "/api/v1" path just like the
    // production nginx setup does.
    proxy: {
      '/api/v1': {
        target: 'http://192.168.137.133:8088',
        changeOrigin: true,
      },
      // Device model icons (e.g. `/upload/icons/bdom_gp3600_16b.png`, referenced
      // by `device-model.icon`) are served same-origin in production the same
      // way `/api/v1` is — proxied here too so they resolve in dev instead of
      // 404ing against this dev server itself.
      '/upload': {
        target: 'http://192.168.137.133:8088',
        changeOrigin: true,
      },
      // Real-time updates (wsClient.ts) — same same-origin-in-prod story as
      // /api/v1 above, but needs `ws: true` since this is a WebSocket
      // upgrade, not a plain HTTP request.
      '/ws': {
        target: 'ws://192.168.137.133:8088',
        ws: true,
        changeOrigin: true,
      },
    },
  },
});
