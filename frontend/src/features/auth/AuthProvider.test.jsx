import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { queryKeys } from '../../api/queryKeys';
import { apiFetch, getCsrfToken, setCsrfToken } from '../../api/client';
import { errorResponse, installFetch, makeMe, mockResponse } from '../../test/http';
import i18n, { changeLanguage } from '../../i18n';
import { AuthProvider } from './AuthProvider';
import { useAuth } from './useAuth';

function Probe() {
  const { user, isLoading, signIn, signOut, refresh } = useAuth();
  if (isLoading) {
    return <p>loading</p>;
  }
  return (
    <div>
      <p>{user ? `user:${user.username}:${user.role}` : 'anonymous'}</p>
      <button onClick={() => signIn('alice', 'secret').catch(() => {})}>sign in</button>
      <button onClick={() => signOut()}>sign out</button>
      <button onClick={() => refresh()}>refresh</button>
    </div>
  );
}

function renderProbe() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Probe />
      </AuthProvider>
    </QueryClientProvider>,
  );
  return queryClient;
}

beforeEach(() => {
  setCsrfToken(null);
});

describe('AuthProvider', () => {
  it('shows loading, then loads /api/auth/me on mount', async () => {
    const me = makeMe({ csrfToken: 'b'.repeat(64) });
    const fetchMock = installFetch({ 'GET /api/auth/me': mockResponse(200, me) });
    renderProbe();
    expect(screen.getByText('loading')).toBeInTheDocument();
    expect(await screen.findByText('user:alice:buyer')).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(getCsrfToken()).toBe('b'.repeat(64));
  });

  it('401 from /api/auth/me means user null, not an error state', async () => {
    installFetch({ 'GET /api/auth/me': errorResponse(401, 'unauthenticated') });
    renderProbe();
    expect(await screen.findByText('anonymous')).toBeInTheDocument();
    expect(getCsrfToken()).toBeNull();
  });

  it('signIn posts JSON credentials, stores the user and the CSRF token', async () => {
    const me = makeMe({ role: 'designer', username: 'dina', csrfToken: 'c'.repeat(64) });
    const fetchMock = installFetch({
      'GET /api/auth/me': errorResponse(401, 'unauthenticated'),
      'POST /api/auth/login': mockResponse(200, { user: me }),
    });
    renderProbe();
    await userEvent.click(await screen.findByText('sign in'));
    expect(await screen.findByText('user:dina:designer')).toBeInTheDocument();
    const loginCall = fetchMock.mock.calls.find(([path]) => path === '/api/auth/login');
    expect(JSON.parse(String(loginCall?.[1].body))).toEqual({
      username: 'alice',
      password: 'secret',
    });
    expect(getCsrfToken()).toBe('c'.repeat(64));
  });

  it('a failed sign-in rejects and leaves the user null', async () => {
    installFetch({
      'GET /api/auth/me': errorResponse(401, 'unauthenticated'),
      'POST /api/auth/login': errorResponse(401, 'invalid_credentials'),
    });
    renderProbe();
    await userEvent.click(await screen.findByText('sign in'));
    await waitFor(() => expect(screen.getByText('anonymous')).toBeInTheDocument());
  });

  it('signOut posts the logout with the CSRF token and clears the user', async () => {
    const me = makeMe();
    const fetchMock = installFetch({
      'GET /api/auth/me': mockResponse(200, me),
      'POST /api/auth/logout': mockResponse(204),
    });
    renderProbe();
    await userEvent.click(await screen.findByText('sign out'));
    expect(await screen.findByText('anonymous')).toBeInTheDocument();
    const logoutCall = fetchMock.mock.calls.find(([path]) => path === '/api/auth/logout');
    expect(logoutCall?.[1].headers).toMatchObject({ 'X-CSRF-Token': me.csrfToken });
    expect(getCsrfToken()).toBeNull();
  });

  it('a 401 from any later request clears the session', async () => {
    installFetch({
      'GET /api/auth/me': mockResponse(200, makeMe()),
      'GET /api/tasks': errorResponse(401, 'unauthenticated'),
    });
    renderProbe();
    expect(await screen.findByText('user:alice:buyer')).toBeInTheDocument();
    await apiFetch('/api/tasks').catch(() => {});
    expect(await screen.findByText('anonymous')).toBeInTheDocument();
    expect(getCsrfToken()).toBeNull();
  });
});

describe('AuthProvider and the interface language', () => {
  it('applies the locale the server has for the user, without sending it back', async () => {
    const fetchMock = installFetch({
      'GET /api/auth/me': mockResponse(200, makeMe({ locale: 'ru' })),
    });
    renderProbe();
    await screen.findByText('user:alice:buyer');
    expect(i18n.language).toBe('ru');
    expect(document.documentElement.lang).toBe('ru');
    expect(localStorage.getItem('creative.lang')).toBe('ru');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('the server value beats the language chosen before sign-in, with no PATCH', async () => {
    await i18n.changeLanguage('en');
    const fetchMock = installFetch({
      'GET /api/auth/me': errorResponse(401, 'unauthenticated'),
      'POST /api/auth/login': mockResponse(200, { user: makeMe({ locale: 'ru' }) }),
    });
    renderProbe();
    await userEvent.click(await screen.findByText('sign in'));
    await screen.findByText('user:alice:buyer');
    expect(i18n.language).toBe('ru');
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual(['/api/auth/me', '/api/auth/login']);
  });
});

describe('a local language switch survives auth refetches', () => {
  it('keeps Russian after refresh() although /me still says English', async () => {
    const me = makeMe({ locale: 'en' });
    installFetch({
      'GET /api/auth/me': mockResponse(200, me),
      'PATCH /api/me/locale': mockResponse(200, { ...me, locale: 'ru' }),
    });
    renderProbe();
    await screen.findByText('user:alice:buyer');
    await act(() => changeLanguage('ru'));
    await userEvent.click(screen.getByText('refresh'));
    await waitFor(() => expect(screen.getByText('user:alice:buyer')).toBeInTheDocument());
    expect(i18n.language).toBe('ru');
    expect(document.documentElement.lang).toBe('ru');
    expect(localStorage.getItem('creative.lang')).toBe('ru');
  });

  it('keeps the chosen language when the PATCH failed and /me is refetched', async () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchMock = installFetch({
      'GET /api/auth/me': mockResponse(200, makeMe({ locale: 'en' })),
      'PATCH /api/me/locale': errorResponse(500, 'internal_error'),
    });
    renderProbe();
    await screen.findByText('user:alice:buyer');
    await act(() => changeLanguage('ru'));
    await userEvent.click(screen.getByText('refresh'));
    await waitFor(() =>
      expect(fetchMock.mock.calls.filter(([path]) => path === '/api/auth/me')).toHaveLength(2),
    );
    expect(i18n.language).toBe('ru');
    expect(document.documentElement.lang).toBe('ru');
    warn.mockRestore();
  });

  it('is not undone by a /me request that was already in flight during the switch', async () => {
    const me = makeMe({ locale: 'en' });
    /** @type {(() => void) | null} */
    let release = null;
    let meCalls = 0;
    installFetch({
      'GET /api/auth/me': () => {
        meCalls += 1;
        if (meCalls === 1) {
          return Promise.resolve(mockResponse(200, me));
        }
        return new Promise((resolve) => {
          release = () => resolve(mockResponse(200, me));
        });
      },
      'PATCH /api/me/locale': mockResponse(200, { ...me, locale: 'ru' }),
    });
    renderProbe();
    await screen.findByText('user:alice:buyer');
    await userEvent.click(screen.getByText('refresh'));
    await waitFor(() => expect(release).not.toBeNull());
    await act(() => changeLanguage('ru'));
    await act(async () => {
      release?.();
    });
    await waitFor(() => expect(meCalls).toBe(2));
    expect(i18n.language).toBe('ru');
    expect(document.documentElement.lang).toBe('ru');
  });

  it('updates the cached user after a successful PATCH, not after a failed one', async () => {
    const me = makeMe({ locale: 'en' });
    installFetch({
      'GET /api/auth/me': mockResponse(200, me),
      'PATCH /api/me/locale': mockResponse(200, { ...me, locale: 'ru' }),
    });
    const queryClient = renderProbe();
    await screen.findByText('user:alice:buyer');
    expect(queryClient.getQueryData(queryKeys.me())).toMatchObject({ locale: 'en' });
    await act(() => changeLanguage('ru'));
    expect(queryClient.getQueryData(queryKeys.me())).toMatchObject({ locale: 'ru' });

    jest.spyOn(console, 'warn').mockImplementation(() => {});
    installFetch({ 'PATCH /api/me/locale': errorResponse(500, 'internal_error') });
    await act(() => changeLanguage('en'));
    expect(queryClient.getQueryData(queryKeys.me())).toMatchObject({ locale: 'ru' });
  });

  it('sign-in after a sign-out applies the server locale again', async () => {
    const fetchMock = installFetch({
      'GET /api/auth/me': mockResponse(200, makeMe({ locale: 'ru' })),
      'POST /api/auth/logout': mockResponse(204),
      'POST /api/auth/login': mockResponse(200, { user: makeMe({ locale: 'en' }) }),
    });
    renderProbe();
    await screen.findByText('user:alice:buyer');
    expect(i18n.language).toBe('ru');
    await userEvent.click(screen.getByText('sign out'));
    await screen.findByText('anonymous');
    await userEvent.click(screen.getByText('sign in'));
    await screen.findByText('user:alice:buyer');
    expect(i18n.language).toBe('en');
    expect(fetchMock.mock.calls.some(([path]) => path === '/api/me/locale')).toBe(false);
  });
});

describe('AuthProvider context value', () => {
  it('keeps its identity across re-renders that change nothing it owns', async () => {
    installFetch({ 'GET /api/auth/me': mockResponse(200, makeMe()) });
    /** @type {import('./useAuth').AuthContextValue[]} */
    const seen = [];
    function Spy() {
      seen.push(useAuth());
      return null;
    }
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    /** @param {{tick: number}} props */
    function Tree({ tick }) {
      return (
        <QueryClientProvider client={queryClient}>
          <AuthProvider>
            <Spy />
            <span>{tick}</span>
          </AuthProvider>
        </QueryClientProvider>
      );
    }
    const { rerender } = render(<Tree tick={0} />);
    await waitFor(() => expect(seen.at(-1)?.user).not.toBeNull());
    const settled = seen.at(-1);
    for (let tick = 1; tick <= 3; tick += 1) {
      await act(async () => rerender(<Tree tick={tick} />));
    }
    expect(seen.at(-1)).toBe(settled);
    expect(seen.at(-1)?.refresh).toBe(settled?.refresh);
  });

  it('refresh reloads the user from the API', async () => {
    const fetchMock = installFetch({ 'GET /api/auth/me': mockResponse(200, makeMe()) });
    /** @type {import('./useAuth').AuthContextValue[]} */
    const seen = [];
    function Grab() {
      seen.push(useAuth());
      return null;
    }
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <Grab />
        </AuthProvider>
      </QueryClientProvider>,
    );
    await waitFor(() => expect(seen.at(-1)?.user).not.toBeNull());
    await act(async () => {
      await seen.at(-1)?.refresh();
    });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });
});

describe('useAuth', () => {
  it('throws outside an AuthProvider', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<Probe />)).toThrow(/AuthProvider/);
    spy.mockRestore();
  });
});
