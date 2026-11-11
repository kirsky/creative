import { ComingSoon } from '../components/ComingSoon';
import { NotFound } from '../components/NotFound';
import { TaskPlaceholder } from '../components/TaskPlaceholder';
import { AuthGuard } from '../features/auth/AuthGuard';
import { LoginPage } from '../features/auth/LoginPage';
import { RoleHome } from '../features/auth/RoleHome';
import { RoleRoute } from '../features/auth/RoleRoute';
import { BoardPage } from '../features/board/BoardPage';
import { CreateTaskPage } from '../features/tasks/CreateTaskPage';
import { AppLayout } from './AppLayout';

// Pages are placeholders until their tasks build them: task page, my work and dashboard.
/** @type {import('react-router').RouteObject[]} */
export const routes = [
  { path: '/login', element: <LoginPage /> },
  {
    element: <AuthGuard />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <RoleHome /> },
          { path: '/board', element: <BoardPage /> },
          {
            path: '/tasks/new',
            element: (
              <RoleRoute roles={['buyer']}>
                <CreateTaskPage />
              </RoleRoute>
            ),
          },
          { path: '/tasks/:id', element: <TaskPlaceholder /> },
          {
            path: '/my-work',
            element: (
              <RoleRoute roles={['designer']}>
                <ComingSoon titleKey="common:nav.myWork" />
              </RoleRoute>
            ),
          },
          {
            path: '/dashboard',
            element: (
              <RoleRoute roles={['manager']}>
                <ComingSoon titleKey="dashboard:title" />
              </RoleRoute>
            ),
          },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
];
