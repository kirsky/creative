import { NotFound } from '../components/NotFound';
import { AuthGuard } from '../features/auth/AuthGuard';
import { LoginPage } from '../features/auth/LoginPage';
import { RoleHome } from '../features/auth/RoleHome';
import { RoleRoute } from '../features/auth/RoleRoute';
import { BoardPage } from '../features/board/BoardPage';
import { DashboardPage } from '../features/dashboard/DashboardPage';
import { MyWorkPage } from '../features/mywork/MyWorkPage';
import { TaskPage } from '../features/task/TaskPage';
import { CreateTaskPage } from '../features/tasks/CreateTaskPage';
import { AppLayout } from './AppLayout';

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
          { path: '/tasks/:id', element: <TaskPage /> },
          {
            path: '/my-work',
            element: (
              <RoleRoute roles={['designer']}>
                <MyWorkPage />
              </RoleRoute>
            ),
          },
          {
            path: '/dashboard',
            element: (
              <RoleRoute roles={['manager']}>
                <DashboardPage />
              </RoleRoute>
            ),
          },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
];
