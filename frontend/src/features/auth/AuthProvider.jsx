import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo } from 'react';
import { ApiRequestError } from '../../api/ApiRequestError';
import { apiFetch, setCsrfToken, setUnauthenticatedHandler } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';
import { changeLanguage } from '../../i18n';
import { isLanguage } from '../../i18n/detect';
import { AuthContext } from './useAuth';

/**
 * @typedef {import('./useAuth').Me} Me
 */

/**
 * After sign-in the language the server has for the user wins. It is applied without sending it
 * back to the server (that would be a pointless PATCH, and a loop waiting to happen).
 * @param {Me} me
 */
function applyServerLocale(me) {
  if (isLanguage(me.locale)) {
    void changeLanguage(me.locale, { syncServer: false });
  }
}

/** @returns {Promise<Me | null>} null when nobody is signed in */
async function fetchMe() {
  try {
    /** @type {Me} */
    const me = await apiFetch('/api/auth/me');
    setCsrfToken(me.csrfToken);
    applyServerLocale(me);
    return me;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      setCsrfToken(null);
      return null;
    }
    throw error;
  }
}

/**
 * Owns the session: loads the user on mount, signs in and out, and ends the session when any
 * API call answers 401. AuthGuard turns "no user" into a redirect to /login.
 * @param {{children: import('react').ReactNode}} props
 */
export function AuthProvider({ children }) {
  const queryClient = useQueryClient();
  const meQuery = useQuery({
    queryKey: queryKeys.me(),
    queryFn: fetchMe,
    retry: false,
    staleTime: Infinity,
  });

  const endSession = useCallback(() => {
    setCsrfToken(null);
    queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== queryKeys.me()[0] });
    queryClient.setQueryData(queryKeys.me(), null);
  }, [queryClient]);

  useEffect(() => {
    setUnauthenticatedHandler(endSession);
    return () => setUnauthenticatedHandler(null);
  }, [endSession]);

  const signIn = useCallback(
    /** @param {string} username @param {string} password */
    async (username, password) => {
      /** @type {{user: Me}} */
      const { user } = await apiFetch('/api/auth/login', {
        method: 'POST',
        body: { username, password },
      });
      setCsrfToken(user.csrfToken);
      applyServerLocale(user);
      queryClient.setQueryData(queryKeys.me(), user);
      return user;
    },
    [queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      await apiFetch('/api/auth/logout', { method: 'POST' });
    } catch (error) {
      // A 401 means the session is already gone, which is what was asked for.
      if (!(error instanceof ApiRequestError && error.status === 401)) {
        throw error;
      }
    }
    endSession();
  }, [endSession]);

  // The query result object is new on every render; its refetch function is stable.
  const { refetch } = meQuery;
  const refresh = useCallback(async () => {
    await refetch();
  }, [refetch]);

  const value = useMemo(
    () => ({
      user: meQuery.data ?? null,
      isLoading: meQuery.isPending,
      signIn,
      signOut,
      refresh,
    }),
    [meQuery.data, meQuery.isPending, signIn, signOut, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
