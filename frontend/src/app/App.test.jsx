import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter } from 'react-router';
import { apiFetch, setCsrfToken } from '../api/client';
import { errorResponse, installFetch, makeMe, mockResponse } from '../test/http';
import { App } from './App';
import { routes } from './routes';

/** @param {string} path @param {ReturnType<typeof makeMe>} me */
function renderApp(path, me) {
  const fetchMock = installFetch({
    'GET /api/auth/me': mockResponse(200, me),
    'POST /api/auth/logout': mockResponse(204),
  });
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<App router={router} />);
  return { router, fetchMock };
}

beforeEach(() => {
  setCsrfToken(null);
});

describe('App shell', () => {
  it.each([
    ['buyer', 'Alice Kovacs', 'Media buyer', '/board', ['Board', 'New task']],
    ['designer', 'Dina Petrova', 'Designer', '/my-work', ['My work', 'Board']],
    ['manager', 'Max Ivanov', 'Manager', '/dashboard', ['Dashboard', 'Board']],
  ])(
    'shows the user, the role and the %s navigation',
    async (role, displayName, label, path, links) => {
      renderApp(
        path,
        makeMe({ role: /** @type {'buyer'|'designer'|'manager'} */ (role), displayName }),
      );
      const banner = await screen.findByRole('banner');
      expect(within(banner).getByText('Creative tasks')).toBeInTheDocument();
      expect(within(banner).getByText(displayName)).toBeInTheDocument();
      expect(within(banner).getByText(label)).toBeInTheDocument();
      const nav = within(screen.getByRole('navigation'));
      expect(nav.getAllByRole('link').map((link) => link.textContent)).toEqual(links);
      expect(within(banner).getByRole('button', { name: 'Sign out' })).toBeInTheDocument();
    },
  );

  it('marks the current page link', async () => {
    renderApp('/board', makeMe());
    const link = await screen.findByRole('link', { name: 'Board' });
    expect(link).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'New task' })).not.toHaveAttribute('aria-current');
  });

  it('signs out through the API and lands on /login', async () => {
    const { router } = renderApp('/board', makeMe());
    await userEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    expect(await screen.findByTestId('coming-soon')).toHaveTextContent('Login');
    expect(router.state.location.pathname).toBe('/login');
  });

  it('a 401 on a later request sends the user to /login', async () => {
    const { router } = renderApp('/board', makeMe());
    await screen.findByRole('banner');
    installFetch({ 'GET /api/tasks': errorResponse(401, 'unauthenticated') });
    await act(() => apiFetch('/api/tasks').catch(() => {}));
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(screen.getByTestId('coming-soon')).toHaveTextContent('Login');
  });
});
