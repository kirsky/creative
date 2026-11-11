import { ComingSoon } from '../components/ComingSoon';
import { NotFound } from '../components/NotFound';
import { TaskPlaceholder } from '../components/TaskPlaceholder';
import { AuthGuard } from '../features/auth/AuthGuard';
import { RoleHome } from '../features/auth/RoleHome';
import { RoleRoute } from '../features/auth/RoleRoute';
import { AppLayout } from './AppLayout';

// Pages are placeholders until their tasks build them: the login page (Task 20), board,
// task page, my work and dashboard.
/** @type {import('react-router').RouteObject[]} */
export const routes = [
  { path: '/login', element: <ComingSoon name="Login" /> },
  {
    element: <AuthGuard />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <RoleHome /> },
          { path: '/board', element: <ComingSoon name="Board" /> },
          {
            path: '/tasks/new',
            element: (
              <RoleRoute roles={['buyer']}>
                <ComingSoon name="New task" />
              </RoleRoute>
            ),
          },
          { path: '/tasks/:id', element: <TaskPlaceholder /> },
          {
            path: '/my-work',
            element: (
              <RoleRoute roles={['designer']}>
                <ComingSoon name="My work" />
              </RoleRoute>
            ),
          },
          {
            path: '/dashboard',
            element: (
              <RoleRoute roles={['manager']}>
                <ComingSoon name="Dashboard" />
              </RoleRoute>
            ),
          },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
];
