import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiRequestError } from '../../api/ApiRequestError';
import { apiFetch, setCsrfToken, setUnauthenticatedHandler } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';
import { changeLanguage, setLocaleSavedHandler } from '../../i18n';
import { isLanguage } from '../../i18n/detect';
import { forgetLoginLanguage, takeLoginLanguage } from '../../i18n/loginLanguage';
import { AuthContext } from './useAuth';

/**
 * @typedef {import('./useAuth').Me} Me
 */

/**
 * After sign-in the language the server has for the user wins. It is applied without sending it
 * back to the server (that would be a pointless PATCH, and a loop waiting to happen). The one
 * exception is in signIn: a language picked by hand on the login page.
 * @param {Me} me
 */
function applyServerLocale(me) {
  if (isLanguage(me.locale)) {
    void changeLanguage(me.locale, { syncServer: false });
  }
}

/**
 * @param {AbortSignal} signal aborted when the query is cancelled (a sign-in has overtaken it)
 * @returns {Promise<Me | null>} null when nobody is signed in
 */
async function fetchMe(signal) {
  try {
    /** @type {Me} */
    const me = await apiFetch('/api/auth/me', { signal });
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
  // The server's language is applied when a session starts (the first /me answer, or sign-in),
  // never on later refetches: those could undo a switch the user has just made.
  const localeApplied = useRef(false);
  const loadMe = useCallback(async (/** @type {AbortSignal} */ signal) => {
    const me = await fetchMe(signal);
    if (me !== null && !localeApplied.current) {
      localeApplied.current = true;
      applyServerLocale(me);
    }
    return me;
  }, []);

  const meQuery = useQuery({
    queryKey: queryKeys.me(),
    queryFn: ({ signal }) => loadMe(signal),
    retry: false,
    staleTime: Infinity,
  });

  const [signedOut, setSignedOut] = useState(false);

  const endSession = useCallback(() => {
    setCsrfToken(null);
    localeApplied.current = false;
    forgetLoginLanguage();
    queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== queryKeys.me()[0] });
    queryClient.setQueryData(queryKeys.me(), null);
  }, [queryClient]);

  useEffect(() => {
    setUnauthenticatedHandler(endSession);
    return () => setUnauthenticatedHandler(null);
  }, [endSession]);

  // Once the server has stored a language switch, the cached user says so too.
  useEffect(() => {
    setLocaleSavedHandler((locale) => {
      queryClient.setQueryData(queryKeys.me(), (/** @type {Me | null | undefined} */ me) =>
        me ? { ...me, locale } : me,
      );
    });
    return () => setLocaleSavedHandler(null);
  }, [queryClient]);

  const signIn = useCallback(
    /** @param {string} username @param {string} password */
    async (username, password) => {
      /** @type {{user: Me}} */
      const { user } = await apiFetch('/api/auth/login', {
        method: 'POST',
        body: { username, password },
      });
      // The first /api/auth/me may still be on its way (the login page is usable meanwhile). Its
      // answer, "nobody" or even a user, is older than this sign-in and must not replace it or
      // clear the CSRF token: cancel it first (apiFetch drops the answer of an aborted request).
      await queryClient.cancelQueries({ queryKey: queryKeys.me() });
      setCsrfToken(user.csrfToken);
      localeApplied.current = true;
      queryClient.setQueryData(queryKeys.me(), user);
      // A language picked by hand on the login page during this visit beats the account's: it is
      // kept and saved on the account (one PATCH, now that there is a CSRF token). Otherwise the
      // account's language is applied.
      const chosen = takeLoginLanguage();
      if (chosen !== null && chosen !== user.locale) {
        void changeLanguage(chosen);
      } else {
        applyServerLocale(user);
      }
      setSignedOut(false);
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
    // Set before the user goes away, so the guard that reacts to that sees it.
    setSignedOut(true);
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
      signedOut,
      signIn,
      signOut,
      refresh,
    }),
    [meQuery.data, meQuery.isPending, signedOut, signIn, signOut, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
