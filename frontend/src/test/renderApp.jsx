import { render } from '@testing-library/react';
import { createMemoryRouter } from 'react-router';
import { App } from '../app/App';
import { routes } from '../app/routes';
import { installFetch, makeMe, mockResponse } from './http';

/** The answer of GET /api/options in tests: a few codes in API order. */
export function makeOptions() {
  return {
    creativeTypes: ['static_image', 'video', 'carousel', 'animated_gif', 'html5_banner', 'other'],
    priorities: ['low', 'normal', 'high', 'urgent'],
    regions: ['WW', 'EU', 'LATAM', 'MENA', 'CIS'],
    countries: ['DE', 'US', 'FR', 'BR', 'AT', 'PL'],
  };
}

/**
 * Renders the real app (real providers and routes) on a memory router for a signed-in user.
 * @param {string} path
 * @param {{me?: ReturnType<typeof makeMe>, table?: Parameters<typeof installFetch>[0]}} [options]
 */
export function renderApp(path, { me = makeMe(), table = {} } = {}) {
  const fetchMock = installFetch({
    'GET /api/auth/me': mockResponse(200, me),
    'PATCH /api/me/locale': mockResponse(200, me),
    ...table,
  });
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<App router={router} />);
  return { router, fetchMock };
}
