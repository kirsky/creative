import { Navigate } from 'react-router';
import { landingPath } from './landing';
import { useAuth } from './useAuth';

/** The `/` route: sends each role to its landing page. */
export function RoleHome() {
  const { user } = useAuth();
  return <Navigate to={user ? landingPath(user.role) : '/login'} replace />;
}
