import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { apiFetch, getCsrfToken, setCsrfToken } from '../../api/client';
import { AppProviders } from '../../app/providers';
import { routes } from '../../app/routes';
import { EMPTY_BOARD_TABLE } from '../../test/board';
import { errorResponse, installFetch, makeMe, mockResponse } from '../../test/http';

/** @typedef {'buyer' | 'designer' | 'manager'} Role */

/**
 * Renders the real app (real providers and routes) on a memory router.
 * @param {import('react-router').InitialEntry} entry
 * @param {Parameters<typeof installFetch>[0]} table the API; /api/auth/me defaults to signed out
 */
function renderAt(entry, table = {}) {
  const fetchMock = installFetch({
    ...EMPTY_BOARD_TABLE,
    'GET /api/auth/me': errorResponse(401, 'unauthenticated'),
    ...table,
  });
  const router = createMemoryRouter(routes, { initialEntries: [entry] });
  render(
    <AppProviders>
      <RouterProvider router={router} />
    </AppProviders>,
  );
  return { router, fetchMock };
}

const usernameField = () => screen.findByLabelText(/^(Username|Имя пользователя)/);
const passwordField = () => screen.getByLabelText(/^(Password|Пароль)/);
const submitButton = () => screen.getByRole('button', { name: /^(Sign in|Войти)$/ });

/**
 * @param {string} username
 * @param {string} password
 */
async function fillAndSubmit(username, password) {
  await userEvent.type(await usernameField(), username);
  await userEvent.type(passwordField(), password);
  await userEvent.click(submitButton());
}

/** @param {ReturnType<typeof installFetch>} fetchMock */
const loginCalls = (fetchMock) =>
  fetchMock.mock.calls.filter(([path]) => path === '/api/auth/login');

beforeEach(() => {
  setCsrfToken(null);
});

describe('LoginPage', () => {
  it('renders username and password fields and no list of demo accounts', async () => {
    renderAt('/login');
    const username = await usernameField();
    expect(username).toHaveAttribute('autocomplete', 'username');
    expect(passwordField()).toHaveAttribute('type', 'password');
    expect(passwordField()).toHaveAttribute('autocomplete', 'current-password');
    expect(screen.getByRole('heading', { name: 'Creative Tasks' })).toBeInTheDocument();
    expect(submitButton()).toBeEnabled();
    expect(screen.getByRole('radiogroup', { name: 'Language' })).toBeInTheDocument();
    // No account list, no one-click login, no credential hints.
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
    expect(screen.getAllByRole('button')).toHaveLength(2); // the password visibility toggle and the submit button
    expect(document.body).not.toHaveTextContent(/demo|alice|dina|ivanov|password:/i);
  });

  it('posts JSON credentials with the JSON content type', async () => {
    const { fetchMock } = renderAt('/login', {
      'POST /api/auth/login': mockResponse(200, { user: makeMe() }),
    });
    await fillAndSubmit('alice', 'correct horse');
    await waitFor(() => expect(loginCalls(fetchMock)).toHaveLength(1));
    const [path, init] = loginCalls(fetchMock)[0];
    expect(path).toBe('/api/auth/login');
    expect(init.method).toBe('POST');
    expect(init.headers).toMatchObject({ 'Content-Type': 'application/json' });
    expect(JSON.parse(String(init.body))).toEqual({ username: 'alice', password: 'correct horse' });
  });

  it('submits with Enter from the password field', async () => {
    const { fetchMock } = renderAt('/login', {
      'POST /api/auth/login': mockResponse(200, { user: makeMe() }),
    });
    await userEvent.type(await usernameField(), 'alice');
    await userEvent.type(passwordField(), 'secret{Enter}');
    await waitFor(() => expect(loginCalls(fetchMock)).toHaveLength(1));
  });

  describe('client-side validation', () => {
    it('shows translated required errors in English and sends nothing', async () => {
      const { fetchMock } = renderAt('/login');
      await usernameField();
      await userEvent.click(submitButton());
      const required = await screen.findAllByText('This field is required.');
      expect(required).toHaveLength(2);
      expect(await usernameField()).toBeInvalid();
      expect(passwordField()).toBeInvalid();
      expect(loginCalls(fetchMock)).toHaveLength(0);
    });

    it('shows translated required errors in Russian', async () => {
      renderAt('/login');
      await usernameField();
      await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
      await userEvent.click(submitButton());
      expect(await screen.findAllByText('Обязательное поле.')).toHaveLength(2);
    });

    it('re-translates a shown error when the language changes', async () => {
      renderAt('/login');
      await usernameField();
      await userEvent.click(submitButton());
      await screen.findAllByText('This field is required.');
      await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
      expect(await screen.findAllByText('Обязательное поле.')).toHaveLength(2);
    });

    it('rejects a username longer than 100 and a password longer than 1000 characters', async () => {
      const { fetchMock } = renderAt('/login');
      await userEvent.click(await usernameField());
      await userEvent.paste('u'.repeat(101));
      await userEvent.click(passwordField());
      await userEvent.paste('p'.repeat(1001));
      await userEvent.click(submitButton());
      expect(await screen.findByText('Must be at most 100 characters')).toBeInTheDocument();
      expect(screen.getByText('Must be at most 1000 characters')).toBeInTheDocument();
      expect(loginCalls(fetchMock)).toHaveLength(0);
    });
  });

  describe('failed sign-in', () => {
    it.each([
      ['an unknown user', 'nobody'],
      ['a wrong password', 'alice'],
      ['an account without a password', 'seeded'],
    ])(
      'invalid_credentials for %s shows one generic alert, keeps the username, clears the password',
      async (_cause, username) => {
        renderAt('/login', {
          'POST /api/auth/login': errorResponse(401, 'invalid_credentials'),
        });
        await fillAndSubmit(username, 'whatever');
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Incorrect username or password.');
        expect(alert.textContent).toBe('Incorrect username or password.');
        expect(await usernameField()).toHaveValue(username);
        expect(passwordField()).toHaveValue('');
        expect(submitButton()).toBeEnabled();
      },
    );

    it('shows the alert in Russian when the language is Russian', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(401, 'invalid_credentials'),
      });
      await userEvent.click(await screen.findByRole('radio', { name: 'RU' }));
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Неверное имя пользователя или пароль.',
      );
    });

    it('clears the previous alert when submitting again', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(401, 'invalid_credentials'),
      });
      await fillAndSubmit('alice', 'x');
      await screen.findByRole('alert');
      let resolveLogin = () => {};
      installFetch({
        'GET /api/auth/me': errorResponse(401, 'unauthenticated'),
        'POST /api/auth/login': () =>
          new Promise((resolve) => {
            resolveLogin = () => resolve(errorResponse(401, 'invalid_credentials'));
          }),
      });
      await userEvent.type(passwordField(), 'y{Enter}');
      await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
      await act(async () => resolveLogin());
      expect(await screen.findByRole('alert')).toBeInTheDocument();
    });

    it('rate_limited shows the wait rounded up to minutes in English', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(429, 'rate_limited', {
          params: { retryAfterSeconds: 125 },
        }),
      });
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Too many attempts. Try again in 3 minutes.',
      );
    });

    it('rate_limited shows the wait in Russian', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(429, 'rate_limited', {
          params: { retryAfterSeconds: 125 },
        }),
      });
      await userEvent.click(await screen.findByRole('radio', { name: 'RU' }));
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Слишком много попыток. Повторите через 3 минуты.',
      );
    });

    it('maps 422 field errors onto the matching inputs', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(422, 'validation_failed', {
          fields: [
            { field: 'username', code: 'too_long', params: { max: 100 } },
            { field: 'password', code: 'required', params: {} },
          ],
        }),
      });
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByText('Must be at most 100 characters')).toBeInTheDocument();
      expect(screen.getByText('This field is required.')).toBeInTheDocument();
      expect(await usernameField()).toBeInvalid();
      expect(passwordField()).toBeInvalid();
      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('puts a field error for an unknown field, or an unreadable body, in the form alert', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(422, 'validation_failed', {
          fields: [
            { field: 'remember', code: 'unknown_field', params: {} },
            { field: '_invalid', code: 'invalid', params: {} },
          ],
        }),
      });
      await fillAndSubmit('alice', 'x');
      const alert = await screen.findByRole('alert');
      expect(alert).toHaveTextContent('This field is not expected here.');
      expect(alert).toHaveTextContent('The form contains data that could not be read.');
      expect(await usernameField()).toBeValid();
    });

    it('shows a generic alert for a validation_failed answer without fields', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(422, 'validation_failed'),
      });
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent('Some fields are not valid.');
    });

    it('shows a translated alert for a network failure', async () => {
      renderAt('/login', {
        'POST /api/auth/login': () => {
          throw new TypeError('Failed to fetch');
        },
      });
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Could not connect to the server.',
      );
      expect(await usernameField()).toHaveValue('alice');
      expect(passwordField()).toHaveValue('');
    });

    it('shows a translated alert for a server error', async () => {
      renderAt('/login', {
        'POST /api/auth/login': errorResponse(500, 'internal_error'),
      });
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Something went wrong on our side.',
      );
    });

    it('never shows server-sent text', async () => {
      renderAt('/login', {
        'POST /api/auth/login': mockResponse(500, '<html>nginx secret</html>'),
      });
      await fillAndSubmit('alice', 'x');
      expect(await screen.findByRole('alert')).toHaveTextContent('Something went wrong.');
      expect(document.body).not.toHaveTextContent('nginx secret');
    });
  });

  describe('double submit', () => {
    it('sends one request while the first is pending and disables the button', async () => {
      /** @type {() => void} */
      let release = () => {};
      const { fetchMock } = renderAt('/login', {
        'POST /api/auth/login': () =>
          new Promise((resolve) => {
            release = () => resolve(mockResponse(200, { user: makeMe() }));
          }),
      });
      await userEvent.type(await usernameField(), 'alice');
      await userEvent.type(passwordField(), 'secret');
      await userEvent.click(submitButton());
      await waitFor(() => expect(submitButton()).toBeDisabled());
      await userEvent.click(submitButton());
      await userEvent.type(passwordField(), '{Enter}{Enter}');
      expect(loginCalls(fetchMock)).toHaveLength(1);
      await act(async () => release());
      await waitFor(() => expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull());
      expect(loginCalls(fetchMock)).toHaveLength(1);
    });
  });

  describe('where a successful sign-in leads', () => {
    it.each([
      ['designer', '/my-work'],
      ['buyer', '/board'],
      ['manager', '/dashboard'],
    ])('sends a %s to %s', async (role, landing) => {
      const { router } = renderAt('/login', {
        'POST /api/auth/login': mockResponse(200, {
          user: makeMe({ role: /** @type {Role} */ (role) }),
        }),
      });
      await fillAndSubmit('someone', 'secret');
      await screen.findByRole('heading', { level: 1 });
      expect(router.state.location.pathname).toBe(landing);
    });

    it('honours a valid location in state.from, with its search and hash', async () => {
      const { router } = renderAt(
        {
          pathname: '/login',
          state: { from: { pathname: '/tasks/42', search: '?tab=files', hash: '#top' } },
        },
        { 'POST /api/auth/login': mockResponse(200, { user: makeMe({ role: 'designer' }) }) },
      );
      await fillAndSubmit('dina', 'secret');
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/tasks/42');
      expect(router.state.location.search).toBe('?tab=files');
      expect(router.state.location.hash).toBe('#top');
    });

    it.each([
      ['a protocol-relative path', { from: { pathname: '//evil.example' } }],
      ['an absolute URL', { from: { pathname: 'https://evil.example/x' } }],
      ['a backslash path', { from: { pathname: '/\\evil.example' } }],
      ['the login page itself', { from: { pathname: '/login' } }],
      ['a string instead of a location', { from: '/tasks/42' }],
      ['a string that looks like a URL', { from: '//evil.example' }],
      ['null', { from: null }],
      ['a number', { from: 7 }],
      ['a location without a pathname', { from: { search: '?x=1' } }],
      ['a non-string pathname', { from: { pathname: ['/tasks/1'] } }],
      [
        'a search that could turn / into //host',
        { from: { pathname: '/', search: '/evil.example' } },
      ],
      ['non-object state', 'https://evil.example'],
    ])('ignores %s in state.from', async (_name, state) => {
      const { router } = renderAt(
        { pathname: '/login', state },
        { 'POST /api/auth/login': mockResponse(200, { user: makeMe({ role: 'designer' }) }) },
      );
      await fillAndSubmit('dina', 'secret');
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/my-work');
    });

    it('never reads the destination from a URL query parameter', async () => {
      const { router } = renderAt('/login?from=/tasks/42&redirect=https://evil.example', {
        'POST /api/auth/login': mockResponse(200, { user: makeMe({ role: 'buyer' }) }),
      });
      await fillAndSubmit('alice', 'secret');
      await screen.findByRole('heading', { level: 1, name: 'Board' });
      expect(router.state.location.pathname).toBe('/board');
    });

    it('sends someone who is already signed in to their landing page', async () => {
      const { router } = renderAt('/login', {
        'GET /api/auth/me': mockResponse(200, makeMe({ role: 'designer' })),
      });
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/my-work');
    });

    it('sends someone who is already signed in to the page they came from', async () => {
      const { router } = renderAt(
        { pathname: '/login', state: { from: { pathname: '/tasks/9' } } },
        { 'GET /api/auth/me': mockResponse(200, makeMe({ role: 'designer' })) },
      );
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/tasks/9');
    });
  });

  describe('after an explicit sign-out', () => {
    /**
     * Signs in as a designer on /tasks/5 (the guard sends nobody there: the session exists
     * from the start), signs out through the header button and returns the router.
     */
    async function signOutFrom(path = '/tasks/5') {
      const rendered = renderAt(path, {
        'GET /api/auth/me': mockResponse(200, makeMe({ role: 'designer' })),
        'POST /api/auth/logout': mockResponse(204),
        'POST /api/auth/login': mockResponse(200, { user: makeMe({ role: 'designer' }) }),
      });
      await userEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
      await usernameField();
      return rendered;
    }

    it('does not carry the page the user left in the router state', async () => {
      const { router } = await signOutFrom();
      expect(router.state.location.pathname).toBe('/login');
      expect(router.state.location.state?.from).toBeUndefined();
    });

    it('signs the next user in at their landing page, not at the old page', async () => {
      const { router } = await signOutFrom();
      await fillAndSubmit('dina', 'secret');
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/my-work');
    });

    it('ignores a from that is put back after the sign-out', async () => {
      const { router } = await signOutFrom();
      await act(() =>
        router.navigate('/login', { state: { from: { pathname: '/tasks/5' } }, replace: true }),
      );
      await fillAndSubmit('dina', 'secret');
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/my-work');
    });

    it('still remembers the page when the session merely expired', async () => {
      const { router } = renderAt('/tasks/5', {
        'GET /api/auth/me': mockResponse(200, makeMe({ role: 'designer' })),
      });
      await screen.findByTestId('coming-soon');
      installFetch({ 'GET /api/tasks': errorResponse(401, 'unauthenticated') });
      await act(() => apiFetch('/api/tasks').catch(() => {}));
      await usernameField();
      expect(router.state.location.pathname).toBe('/login');
      expect(router.state.location.state.from.pathname).toBe('/tasks/5');
    });
  });

  describe('language', () => {
    it('re-renders the labels in Russian before sign-in, keeps the choice, sends no PATCH', async () => {
      const { fetchMock } = renderAt('/login');
      await usernameField();
      await userEvent.click(screen.getByRole('radio', { name: 'RU' }));

      expect(await screen.findByLabelText(/^Имя пользователя/)).toBeInTheDocument();
      expect(passwordField()).toHaveAttribute('type', 'password');
      expect(screen.getByRole('button', { name: 'Войти' })).toBeInTheDocument();
      expect(screen.getByRole('heading', { name: 'Креативные задачи' })).toBeInTheDocument();
      expect(screen.getByRole('radiogroup', { name: 'Язык' })).toBeInTheDocument();
      expect(document.documentElement.lang).toBe('ru');
      expect(localStorage.getItem('creative.lang')).toBe('ru');
      expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false);

      await userEvent.click(screen.getByRole('radio', { name: 'EN' }));
      expect(await screen.findByLabelText(/^Username/)).toBeInTheDocument();
      expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false);
    });

    it('keeps the typed username when the language changes', async () => {
      renderAt('/login');
      await userEvent.type(await usernameField(), 'alice');
      await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
      expect(await screen.findByLabelText(/^Имя пользователя/)).toHaveValue('alice');
    });

    it('applies the language the server has for the user on sign-in, with no PATCH', async () => {
      const { fetchMock } = renderAt('/login', {
        'POST /api/auth/login': mockResponse(200, { user: makeMe({ locale: 'ru' }) }),
      });
      await fillAndSubmit('alice', 'secret');
      await screen.findByRole('heading', { level: 1, name: 'Доска' });
      expect(document.documentElement.lang).toBe('ru');
      expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false);
    });
  });

  describe('the sign-in racing the first /api/auth/me', () => {
    it('is not undone when the slow first /me answers 401 after the sign-in', async () => {
      const me = makeMe({ role: 'designer', csrfToken: 'd'.repeat(64) });
      /** @type {(response: ReturnType<typeof mockResponse>) => void} */
      let answerMe = () => {};
      const { router } = renderAt('/login', {
        'GET /api/auth/me': () =>
          new Promise((resolve) => {
            answerMe = resolve;
          }),
        'POST /api/auth/login': mockResponse(200, { user: me }),
      });
      // /me is still pending, so the form is already usable.
      await fillAndSubmit('dina', 'secret');
      await screen.findByTestId('coming-soon');
      expect(router.state.location.pathname).toBe('/my-work');
      expect(getCsrfToken()).toBe('d'.repeat(64));

      await act(async () => answerMe(errorResponse(401, 'unauthenticated')));
      await act(async () => {});

      expect(router.state.location.pathname).toBe('/my-work');
      expect(getCsrfToken()).toBe('d'.repeat(64));
      expect(screen.getByTestId('coming-soon')).toBeInTheDocument();
      expect(within(await screen.findByRole('banner')).getByText(me.displayName)).toBeVisible();
    });

    it('is not undone when the slow first /me answers 200 for someone else', async () => {
      const me = makeMe({ role: 'designer', csrfToken: 'd'.repeat(64) });
      /** @type {(response: ReturnType<typeof mockResponse>) => void} */
      let answerMe = () => {};
      renderAt('/login', {
        'GET /api/auth/me': () =>
          new Promise((resolve) => {
            answerMe = resolve;
          }),
        'POST /api/auth/login': mockResponse(200, { user: me }),
      });
      await fillAndSubmit('dina', 'secret');
      await screen.findByTestId('coming-soon');
      await act(async () =>
        answerMe(
          mockResponse(200, makeMe({ displayName: 'Stale Person', csrfToken: 'e'.repeat(64) })),
        ),
      );
      await act(async () => {});
      expect(getCsrfToken()).toBe('d'.repeat(64));
      expect(screen.queryByText('Stale Person')).not.toBeInTheDocument();
    });
  });
});
