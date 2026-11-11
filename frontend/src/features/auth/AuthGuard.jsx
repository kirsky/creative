import { Center, Loader } from '@mantine/core';
import { Navigate, Outlet, useLocation } from 'react-router';
import { useAuth } from './useAuth';

// All user-visible text of this component.
const TEXT = { loading: 'Loading' };

/**
 * Layout route: renders its child routes for a signed-in user, otherwise sends the visitor to
 * /login and remembers where they wanted to go in the router state (`state.from`).
 */
export function AuthGuard() {
  const { user, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <Center h="100vh" role="status" aria-label={TEXT.loading}>
        <Loader />
      </Center>
    );
  }
  if (user === null) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }
  return <Outlet />;
}
