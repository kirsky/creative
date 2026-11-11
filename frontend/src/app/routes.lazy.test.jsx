import { screen, waitFor } from '@testing-library/react';
import { setCsrfToken } from '../api/client';
import { EMPTY_BOARD_TABLE } from '../test/board';
import { dashboardPath, makeDashboard } from '../test/dashboard';
import { makeMe, mockResponse } from '../test/http';
import { renderApp } from '../test/renderApp';

/** How many times the dashboard module has been required (counted outside the module scope, since a static import runs the factory before this file's variables exist). */
function loads() {
  return /** @type {any} */ (globalThis).dashboardModuleLoads ?? 0;
}

// The factory runs when the dashboard module is first required: a static import in the route
// table would run it before any test, a lazy one only when a manager opens the page.
jest.mock('../features/dashboard/DashboardPage', () => {
  const g = /** @type {any} */ (globalThis);
  g.dashboardModuleLoads = (g.dashboardModuleLoads ?? 0) + 1;
  return { DashboardPage: () => <h1>Dashboard page</h1> };
});

// How many times the module had been required once the route table was imported.
const loadedWithRoutes = loads();

const table = {
  ...EMPTY_BOARD_TABLE,
  [`GET ${dashboardPath('all')}`]: mockResponse(200, makeDashboard()),
};

beforeEach(() => {
  setCsrfToken(null);
  /** @type {any} */ (globalThis).dashboardModuleLoads = 0;
});

describe('the dashboard chunk', () => {
  it('is not loaded at startup', async () => {
    renderApp('/board', { me: makeMe({ role: 'buyer' }), table });
    await screen.findByRole('heading', { level: 1, name: 'Board' });
    expect(loadedWithRoutes).toBe(0);
    expect(loads()).toBe(0);
  });

  it.each(['designer', 'buyer'])('is never loaded for a %s who opens /dashboard', async (role) => {
    const { router } = renderApp('/dashboard', {
      me: makeMe({ role: /** @type {'designer' | 'buyer'} */ (role) }),
      table,
    });
    await waitFor(() => expect(router.state.location.pathname).not.toBe('/dashboard'));
    await screen.findByRole('heading', { level: 1 });
    expect(loads()).toBe(0);
  });

  it('is loaded for a manager who opens /dashboard', async () => {
    renderApp('/dashboard', { me: makeMe({ role: 'manager' }), table });
    expect(await screen.findByRole('heading', { name: 'Dashboard page' })).toBeInTheDocument();
    expect(loads()).toBe(1);
  });
});
