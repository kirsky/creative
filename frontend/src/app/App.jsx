import { useState } from 'react';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { AppProviders } from './providers';
import { routes } from './routes';

/**
 * @param {{router?: ReturnType<typeof createBrowserRouter>}} props tests pass a memory router
 */
export function App({ router }) {
  const [appRouter] = useState(() => router ?? createBrowserRouter(routes));
  return (
    <AppProviders>
      <RouterProvider router={appRouter} />
    </AppProviders>
  );
}
