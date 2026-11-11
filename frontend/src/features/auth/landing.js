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
