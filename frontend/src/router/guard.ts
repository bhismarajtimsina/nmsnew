/**
 * The navigation rule, as a pure function so it can be tested (Plan 25: "route guards behave identically before and
 * after"). Route `meta.auth` is true on the sign-in pages (the `/auth` tree), false on everything that needs a login.
 */
export type GuardDecision = true | { path: '/' } | { name: 'login' };

export function decide(toIsAuthPage: boolean, loggedIn: boolean): GuardDecision {
  if (toIsAuthPage && loggedIn) return { path: '/' };
  if (!toIsAuthPage && !loggedIn) return { name: 'login' };
  return true;
}
