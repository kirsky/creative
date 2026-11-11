/** Where each role starts after signing in. */
const LANDING_PATHS = {
  designer: '/my-work',
  buyer: '/board',
  manager: '/dashboard',
};

/** @param {import('./useAuth').Me['role']} role */
export function landingPath(role) {
  return LANDING_PATHS[role];
}

/** The login page itself is never a place to go back to. */
const LOGIN_PATH = '/login';

/**
 * Where a visitor stopped by AuthGuard asked to go, from the router state of the login page.
 * Only an in-app path is accepted: a router location object (not a string, so nothing a link or
 * a query parameter could have put there) whose pathname starts with exactly one "/" and is not
 * the login page. Anything else gives null and the caller uses the role landing page.
 * @param {unknown} state `location.state` of /login
 * @returns {string | null} path with search and hash
 */
export function returnPath(state) {
  if (typeof state !== 'object' || state === null || !('from' in state)) {
    return null;
  }
  const from = state.from;
  if (typeof from !== 'object' || from === null || !('pathname' in from)) {
    return null;
  }
  const { pathname } = from;
  if (
    typeof pathname !== 'string' ||
    !/^\/(?![/\\])/.test(pathname) ||
    pathname === LOGIN_PATH ||
    pathname.startsWith(`${LOGIN_PATH}/`)
  ) {
    return null;
  }
  // A search or hash that does not start with its marker could turn "/" into "//host".
  const search =
    'search' in from && typeof from.search === 'string' && from.search.startsWith('?')
      ? from.search
      : '';
  const hash =
    'hash' in from && typeof from.hash === 'string' && from.hash.startsWith('#') ? from.hash : '';
  return `${pathname}${search}${hash}`;
}
