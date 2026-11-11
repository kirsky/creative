import { Center, Loader } from '@mantine/core';
import { useTranslation } from 'react-i18next';
import { Navigate, Outlet, useLocation } from 'react-router';
import { useAuth } from './useAuth';

/**
 * Layout route: renders its child routes for a signed-in user, otherwise sends the visitor to
 * /login and remembers where they wanted to go in the router state (`state.from`).
 */
export function AuthGuard() {
  const { user, isLoading } = useAuth();
  const { t } = useTranslation('common');
  const location = useLocation();

  if (isLoading) {
    return (
      <Center h="100vh" role="status" aria-label={t('loading')}>
        <Loader />
      </Center>
    );
  }
  if (user === null) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }
  return <Outlet />;
}
