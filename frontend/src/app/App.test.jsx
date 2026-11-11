import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter } from 'react-router';
import { apiFetch, setCsrfToken } from '../api/client';
import { EMPTY_BOARD_TABLE } from '../test/board';
import { errorResponse, installFetch, makeMe, mockResponse } from '../test/http';
import { App } from './App';
import { routes } from './routes';

/** @param {string} path @param {ReturnType<typeof makeMe>} me */
function renderApp(path, me) {
  const fetchMock = installFetch({
    ...EMPTY_BOARD_TABLE,
    'GET /api/auth/me': mockResponse(200, me),
    'POST /api/auth/logout': mockResponse(204),
    'PATCH /api/me/locale': mockResponse(200, me),
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
    ['buyer', 'Alice Kovacs', 'Media Buyer', '/board', ['Board', 'New task']],
    ['designer', 'Dina Petrova', 'Creative Designer', '/my-work', ['My work', 'Board']],
    ['manager', 'Max Ivanov', 'Manager', '/dashboard', ['Dashboard', 'Board']],
  ])(
    'shows the user, the role and the %s navigation',
    async (role, displayName, label, path, links) => {
      renderApp(
        path,
        makeMe({ role: /** @type {'buyer'|'designer'|'manager'} */ (role), displayName }),
      );
      const banner = await screen.findByRole('banner');
      expect(within(banner).getByText('Creative Tasks')).toBeInTheDocument();
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
    expect(
      within(screen.getByRole('navigation')).getByRole('link', { name: 'New task' }),
    ).not.toHaveAttribute('aria-current');
  });

  it('signs out through the API and lands on /login', async () => {
    const { router } = renderApp('/board', makeMe());
    await userEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    expect(await screen.findByLabelText(/^Username/)).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/login');
  });

  it('a 401 on a later request sends the user to /login', async () => {
    const { router } = renderApp('/board', makeMe());
    await screen.findByRole('banner');
    installFetch({ 'GET /api/tasks': errorResponse(401, 'unauthenticated') });
    await act(() => apiFetch('/api/tasks').catch(() => {}));
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(await screen.findByLabelText(/^Username/)).toBeInTheDocument();
  });
});

describe('language switching', () => {
  it('switches the visible header text between English and Russian', async () => {
    const { fetchMock } = renderApp('/board', makeMe({ displayName: 'Alice Kovacs' }));
    const banner = await screen.findByRole('banner');
    expect(within(banner).getByText('Creative Tasks')).toBeInTheDocument();
    const switcher = within(banner).getByRole('radiogroup', { name: 'Language' });

    await userEvent.click(within(switcher).getByRole('radio', { name: 'RU' }));

    expect(await within(banner).findByText('Креативные задачи')).toBeInTheDocument();
    expect(within(banner).getByText('Медиабайер')).toBeInTheDocument();
    expect(within(banner).getByRole('button', { name: 'Выйти' })).toBeInTheDocument();
    const nav = within(screen.getByRole('navigation'));
    expect(nav.getAllByRole('link').map((link) => link.textContent)).toEqual([
      'Доска',
      'Новая задача',
    ]);
    expect(within(banner).getByRole('radiogroup', { name: 'Язык' })).toBeInTheDocument();
    expect(document.documentElement.lang).toBe('ru');
    expect(screen.getByRole('heading', { level: 1, name: 'Доска' })).toBeInTheDocument();
    expect(
      within(screen.getByRole('main')).getByRole('link', { name: 'Новая задача' }),
    ).toBeInTheDocument();
    const patch = fetchMock.mock.calls.find(([path]) => path === '/api/me/locale');
    expect(JSON.parse(String(patch?.[1].body))).toEqual({ locale: 'ru' });

    await userEvent.click(within(banner).getByRole('radio', { name: 'EN' }));
    expect(await within(banner).findByText('Creative Tasks')).toBeInTheDocument();
    expect(within(banner).getByRole('button', { name: 'Sign out' })).toBeInTheDocument();
  });

  it('shows the app in the language the server has for the user', async () => {
    renderApp('/board', makeMe({ locale: 'ru', role: 'designer', displayName: 'Dina' }));
    const banner = await screen.findByRole('banner');
    expect(within(banner).getByText('Креативный дизайнер')).toBeInTheDocument();
    expect(within(banner).getByText('Креативные задачи')).toBeInTheDocument();
  });

  it('translates the loader label, the not-found page and the task placeholder', async () => {
    const { router } = renderApp('/nowhere', makeMe({ locale: 'ru' }));
    expect(await screen.findByText('Страница не найдена')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Перейти на стартовую страницу' })).toBeInTheDocument();
    await act(() => router.navigate('/tasks/42'));
    expect(await screen.findByTestId('coming-soon')).toHaveTextContent('Задача 42');
  });
});
