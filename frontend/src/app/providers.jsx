import { MantineProvider } from '@mantine/core';
import { DatesProvider } from '@mantine/dates';
import { Notifications } from '@mantine/notifications';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../api/ApiRequestError';
import '../i18n';
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
 * Mantine's date components follow the interface language (dayjs locales are loaded by i18n).
 * @param {{children: import('react').ReactNode}} props
 */
function LocalizedDates({ children }) {
  const { i18n } = useTranslation();
  return <DatesProvider settings={{ locale: i18n.language }}>{children}</DatesProvider>;
}

/**
 * Everything the app needs above the router: Mantine (light or dark following the OS, dates in the
 * interface language, toasts), TanStack Query and the session. The 401 handling lives in AuthProvider.
 * @param {{children: import('react').ReactNode}} props
 */
export function AppProviders({ children }) {
  const [queryClient] = useState(createQueryClient);
  return (
    <MantineProvider theme={theme} defaultColorScheme="auto">
      <Notifications position="bottom-right" />
      <LocalizedDates>
        <QueryClientProvider client={queryClient}>
          <AuthProvider>{children}</AuthProvider>
        </QueryClientProvider>
      </LocalizedDates>
    </MantineProvider>
  );
}
