import { render, screen } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { AppProviders } from '../../app/providers';
import { routes } from '../../app/routes';
import { setCsrfToken } from '../../api/client';
import { EMPTY_BOARD_TABLE } from '../../test/board';
import { dashboardPath, makeDashboard } from '../../test/dashboard';
import { errorResponse, installFetch, makeMe, mockResponse } from '../../test/http';
import { TASK_PAGE_HEADING, TASK_PAGE_TABLE } from '../../test/taskDetail';

/**
 * @param {string} path
 * @param {import('./useAuth').Me | null} me null means signed out
 */
function renderAt(path, me) {
  installFetch({
    ...EMPTY_BOARD_TABLE,
    ...TASK_PAGE_TABLE,
    [`GET ${dashboardPath('all')}`]: mockResponse(200, makeDashboard()),
    'GET /api/auth/me': me ? mockResponse(200, me) : errorResponse(401, 'unauthenticated'),
  });
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(
    <AppProviders>
      <RouterProvider router={router} />
    </AppProviders>,
  );
  return router;
}

beforeEach(() => {
  setCsrfToken(null);
});

describe('AuthGuard', () => {
  it('shows a loader while the session is loading', () => {
    renderAt('/board', makeMe());
    expect(screen.getByRole('status', { name: /loading/i })).toBeInTheDocument();
  });

  it('redirects to /login when no user', async () => {
    const router = renderAt('/board', null);
    expect(await screen.findByLabelText(/^Username/)).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/login');
  });

  it('keeps the intended location in router state', async () => {
    const router = renderAt('/tasks/42?x=1', null);
    await screen.findByLabelText(/^Username/);
    expect(router.state.location.pathname).toBe('/login');
    expect(router.state.location.state.from.pathname).toBe('/tasks/42');
    expect(router.state.location.state.from.search).toBe('?x=1');
  });

  it('lets /login be reached without a session', async () => {
    const router = renderAt('/login', null);
    expect(await screen.findByLabelText(/^Username/)).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/login');
  });

  it('renders the guarded page for a signed-in user', async () => {
    renderAt('/board', makeMe());
    expect(await screen.findByRole('heading', { level: 1, name: 'Board' })).toBeInTheDocument();
  });
});

describe('RoleRoute', () => {
  it('sends a designer away from /dashboard', async () => {
    const router = renderAt('/dashboard', makeMe({ role: 'designer' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'My work' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/my-work');
  });

  it('sends a buyer away from /my-work and a manager away from /tasks/new', async () => {
    const buyerRouter = renderAt('/my-work', makeMe({ role: 'buyer' }));
    await screen.findByRole('heading', { level: 1, name: 'Board' });
    expect(buyerRouter.state.location.pathname).toBe('/board');
  });

  it('sends a manager away from /tasks/new', async () => {
    const router = renderAt('/tasks/new', makeMe({ role: 'manager' }));
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Team overview' }),
    ).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/dashboard');
  });

  it('lets the matching role in', async () => {
    renderAt('/dashboard', makeMe({ role: 'manager' }));
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Team overview' }),
    ).toBeInTheDocument();
  });

  it('lets every role open the board and a task page', async () => {
    renderAt('/tasks/5', makeMe({ role: 'designer' }));
    expect(await screen.findByRole('heading', TASK_PAGE_HEADING)).toBeInTheDocument();
  });
});

describe('landing redirect', () => {
  it.each([
    ['designer', '/my-work'],
    ['buyer', '/board'],
    ['manager', '/dashboard'],
  ])('/ sends a %s to %s', async (role, landing) => {
    const router = renderAt(
      '/',
      makeMe({ role: /** @type {'buyer'|'designer'|'manager'} */ (role) }),
    );
    await screen.findByRole('heading', { level: 1 });
    expect(router.state.location.pathname).toBe(landing);
  });
});
