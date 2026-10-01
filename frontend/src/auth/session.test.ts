import { describe, expect, it } from 'vitest';
import { authBackend } from './session';

describe('the sign-in backend switch', () => {
  it('defaults to legacy, so a build without the setting behaves exactly as before', () => {
    expect(authBackend(undefined)).toBe('legacy');
    expect(authBackend('')).toBe('legacy');
    expect(authBackend('something-else')).toBe('legacy');
  });

  it('uses the new API only when explicitly set', () => {
    expect(authBackend('cybersathy')).toBe('cybersathy');
  });
});
