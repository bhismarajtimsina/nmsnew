import { describe, expect, it } from 'vitest';
import { decide } from './guard';

/** The guard exactly as it was written before Plan 25, kept here as the reference the new one must match. */
function legacyGuard(toMetaAuth: boolean, login: boolean): unknown {
  if (toMetaAuth && login) return { path: '/' };
  else if (!toMetaAuth && !login) return { name: 'login' };
  return true;
}

describe('the route guard', () => {
  for (const toIsAuthPage of [true, false]) {
    for (const loggedIn of [true, false]) {
      it(`matches the old guard: auth page=${toIsAuthPage}, logged in=${loggedIn}`, () => {
        expect(decide(toIsAuthPage, loggedIn)).toEqual(legacyGuard(toIsAuthPage, loggedIn));
      });
    }
  }

  it('sends a signed-out user to the sign-in page and a signed-in one away from it', () => {
    expect(decide(false, false)).toEqual({ name: 'login' });
    expect(decide(true, true)).toEqual({ path: '/' });
  });
});
