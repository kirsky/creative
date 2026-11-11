import { createContext, useContext } from 'react';

/**
 * The signed-in user as GET /api/auth/me returns it.
 * @typedef {object} Me
 * @property {number} id
 * @property {string} username
 * @property {string} displayName
 * @property {'buyer' | 'designer' | 'manager'} role
 * @property {'en' | 'ru'} locale
 * @property {string} csrfToken
 */

/**
 * @typedef {object} AuthContextValue
 * @property {Me | null} user null when signed out
 * @property {boolean} isLoading true until the first /api/auth/me answer
 * @property {boolean} signedOut true after the user explicitly signed out and until the next
 *   sign-in: then nothing remembers the page they left (a session that merely expired does)
 * @property {(username: string, password: string) => Promise<Me>} signIn rejects with ApiRequestError
 * @property {() => Promise<void>} signOut
 * @property {() => Promise<void>} refresh reloads the user from /api/auth/me
 */

export const AuthContext = createContext(/** @type {AuthContextValue | null} */ (null));

/** @returns {AuthContextValue} */
export function useAuth() {
  const value = useContext(AuthContext);
  if (value === null) {
    throw new Error('useAuth must be used inside an AuthProvider');
  }
  return value;
}
