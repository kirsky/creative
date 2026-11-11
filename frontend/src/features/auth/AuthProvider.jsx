import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo } from 'react';
import { ApiRequestError } from '../../api/ApiRequestError';
import { apiFetch, setCsrfToken, setUnauthenticatedHandler } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';
import { AuthContext } from './useAuth';

/**
 * @typedef {import('./useAuth').Me} Me
 */

/** @returns {Promise<Me | null>} null when nobody is signed in */
async function fetchMe() {
  try {
    /** @type {Me} */
    const me = await apiFetch('/api/auth/me');
    setCsrfToken(me.csrfToken);
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

  const refresh = useCallback(async () => {
    await meQuery.refetch();
  }, [meQuery]);

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
