import { lazy, Suspense } from 'react';
import { NotFound } from '../components/NotFound';
import { PageLoader } from '../components/PageLoader';
import { AuthGuard } from '../features/auth/AuthGuard';
import { LoginPage } from '../features/auth/LoginPage';
import { RoleHome } from '../features/auth/RoleHome';
import { RoleRoute } from '../features/auth/RoleRoute';
import { BoardPage } from '../features/board/BoardPage';
import { MyWorkPage } from '../features/mywork/MyWorkPage';
import { TaskPage } from '../features/task/TaskPage';
import { CreateTaskPage } from '../features/tasks/CreateTaskPage';
import { AppLayout } from './AppLayout';

// Only managers open the dashboard, and it brings the chart library: its own chunk, downloaded
// when a manager gets there (the RoleRoute renders it, so nobody else ever asks for it).
const DashboardPage = lazy(() =>
  import('../features/dashboard/DashboardPage').then((module) => ({
    default: module.DashboardPage,
  })),
);

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
                <Suspense fallback={<PageLoader />}>
                  <DashboardPage />
                </Suspense>
              </RoleRoute>
            ),
          },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
];
