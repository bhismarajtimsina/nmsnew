import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { MENU_RULES, SUBMENUS, menuVisibility } from './menu';

const aside = readFileSync(resolve(__dirname, '../layout/Aside.vue'), 'utf8');
const itemKeys = [...aside.matchAll(/<a-menu-item v-if="show\('([^']+)'\)"/g)].map((m) => m[1]);
const submenuKeys = [...aside.matchAll(/<a-sub-menu v-if="show\('([^']+)'\)"/g)].map((m) => m[1]);
const externalKeys = [...aside.matchAll(/\{ key: '([^']+)', label:/g)].map((m) => m[1]);

const holding =
  (...granted: string[]) =>
  (p: string) =>
    granted.includes(p);

describe('menu rules', () => {
  it('cover every entry in the sidebar, and nothing that is not in it', () => {
    expect(itemKeys.length).toBeGreaterThan(30);
    expect(externalKeys).toEqual(['oxidized', 'grafana', 'prometheus', 'alertmanager', 'phpmyadmin']);
    expect([...itemKeys, ...externalKeys].sort()).toEqual(Object.keys(MENU_RULES).sort());
  });

  it('describe the same submenus as the sidebar', () => {
    expect(submenuKeys.sort()).toEqual(Object.keys(SUBMENUS).sort());
    for (const [submenu, children] of Object.entries(SUBMENUS)) {
      const block = aside.split(`<a-sub-menu v-if="show('${submenu}')"`)[1].split('</a-sub-menu>')[0];
      const inBlock = [...block.matchAll(/<a-menu-item v-if="show\('([^']+)'\)"/g)].map((m) => m[1]);
      expect(inBlock).toEqual(children);
    }
  });

  it('every entry is guarded, so none shows unconditionally with the new login', () => {
    expect(aside).not.toMatch(/<a-(menu-item|sub-menu) key=/);
  });
});

describe('menuVisibility', () => {
  it('shows everything in the legacy build, whatever the permissions', () => {
    const show = menuVisibility(false, () => false);
    for (const key of [...Object.keys(MENU_RULES), ...Object.keys(SUBMENUS)]) expect(show(key)).toBe(true);
  });

  it('with the new login, shows an entry only to holders of one of its permissions', () => {
    const show = menuVisibility(true, holding('users.view', 'onus.view'));
    expect(show('users')).toBe(true);
    expect(show('ont-list')).toBe(true);
    expect(show('user-roles')).toBe(false);
    expect(show('devices-list')).toBe(false);
    expect(show('dashboard')).toBe(false);
  });

  it('accepts any one of several permissions', () => {
    expect(menuVisibility(true, holding('notifications.contacts.self'))('notifications-config')).toBe(true);
    expect(menuVisibility(true, holding('olts.view'))('ont-list')).toBe(true);
  });

  it('shows a submenu when at least one of its entries is visible, and hides an empty one', () => {
    const show = menuVisibility(true, holding('roles.view'));
    expect(show('user-mgmt')).toBe(true);
    expect(show('config')).toBe(false);
    expect(show('qr')).toBe(false);
  });

  it('hides a page with no counterpart in the new API, and any key without a rule', () => {
    const everything = () => true;
    const show = menuVisibility(true, everything);
    expect(show('phpmyadmin')).toBe(false);
    expect(show('not-a-menu-key')).toBe(false);
    expect(show('dashboard')).toBe(true);
  });

  it('a user with no permissions sees nothing', () => {
    const show = menuVisibility(true, () => false);
    for (const key of [...Object.keys(MENU_RULES), ...Object.keys(SUBMENUS)]) expect(show(key)).toBe(false);
  });
});
