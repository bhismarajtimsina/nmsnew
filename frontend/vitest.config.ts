// Unit tests for the typed API layer and the stores (Plans 24 and 25). Kept apart from vite.config.ts so the tests do
// not load the app's build plugins.
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  test: { environment: 'node', setupFiles: ['./src/test-setup.ts'], include: ['src/**/*.test.ts'] },
});
