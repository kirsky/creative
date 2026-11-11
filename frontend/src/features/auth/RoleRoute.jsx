import { Navigate, Outlet } from 'react-router';
import { useAuth } from './useAuth';

/**
 * Lets only the given roles through; everyone else goes to `/`, which sends them to their own
 * landing page. Use inside AuthGuard, as a wrapper (children) or as a layout route.
 * @param {{roles: import('./useAuth').Me['role'][], children?: import('react').ReactNode}} props
 */
export function RoleRoute({ roles, children }) {
  const { user } = useAuth();
  if (user === null || !roles.includes(user.role)) {
    return <Navigate to="/" replace />;
  }
  return children ?? <Outlet />;
}
