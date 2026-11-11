import { MantineProvider } from '@mantine/core';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { ApiRequestError } from '../api/ApiRequestError';
import { AuthProvider } from '../features/auth/AuthProvider';
import { theme } from '../styles/theme';

export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // A 4xx answer will not change by asking again.
        retry: (failureCount, error) =>
          !(error instanceof ApiRequestError && error.status >= 400 && error.status < 500) &&
          failureCount < 2,
      },
    },
  });
}

/**
 * Everything the app needs above the router: Mantine (light or dark following the OS), TanStack
 * Query and the session. The 401 handling lives in AuthProvider.
 * @param {{children: import('react').ReactNode}} props
 */
export function AppProviders({ children }) {
  const [queryClient] = useState(createQueryClient);
  return (
    <MantineProvider theme={theme} defaultColorScheme="auto">
      <QueryClientProvider client={queryClient}>
        <AuthProvider>{children}</AuthProvider>
      </QueryClientProvider>
    </MantineProvider>
  );
}
