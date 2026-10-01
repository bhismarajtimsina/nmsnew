import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import config from '@/config/config';
import { useLayoutStore } from './layout';

beforeEach(() => setActivePinia(createPinia()));

describe('layout store', () => {
  it('starts from the static config, as the Vuex module did', () => {
    const layout = useLayoutStore();
    expect(layout.darkMode).toBe(config.darkMode);
    expect(layout.rtl).toBe(config.rtl);
    expect(layout.topMenu).toBe(config.topMenu);
    expect(layout.main).toBe(config.mainTemplate);
  });

  it('dark mode switches the main template with it, both ways', () => {
    const layout = useLayoutStore();
    layout.setDarkMode(true);
    expect([layout.darkMode, layout.main]).toEqual([true, 'blackMode']);
    layout.setDarkMode(false);
    expect([layout.darkMode, layout.main]).toEqual([false, 'lightMode']);
  });

  it('direction and menu position change on their own, at once', () => {
    const layout = useLayoutStore();
    layout.setRtl(true);
    layout.setTopMenu(true);
    expect([layout.rtl, layout.topMenu, layout.darkMode]).toEqual([true, true, config.darkMode]);
    layout.setRtl(false);
    layout.setTopMenu(false);
    expect([layout.rtl, layout.topMenu]).toEqual([false, false]);
  });
});

describe('the Vuex themeLayout module is gone', () => {
  it('no source file still reads it (Vuex state is untyped, so a stale read would only fail at run time)', async () => {
    const { readdirSync, readFileSync, statSync } = await import('node:fs');
    const { join, resolve } = await import('node:path');
    const root = resolve(__dirname, '..');
    const stale: string[] = [];
    const walk = (dir: string) => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) walk(path);
        else if (/\.(vue|ts|js)$/.test(name) && !name.startsWith('layout.')) {
          if (/themeLayout|changeLayoutMode|changeRtlMode|changeMenuMode/.test(readFileSync(path, 'utf8')))
            stale.push(path);
        }
      }
    };
    walk(root);
    expect(stale).toEqual([]);
  });
});
