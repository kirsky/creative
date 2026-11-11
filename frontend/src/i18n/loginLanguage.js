/** @typedef {import('./detect').Language} Language */

/** The language the visitor picked by hand on the login page during this visit, if any. */
let chosen = /** @type {Language | null} */ (null);

/**
 * Remembers a language picked by hand on the login page (its language switch), so that it wins
 * over the account's language at the next sign-in. Detection from the browser or localStorage is
 * not a choice and never calls this. Memory only: a reload forgets it.
 * @param {Language} language
 */
export function rememberLoginLanguage(language) {
  chosen = language;
}

/**
 * The remembered choice, forgotten at the same time: it is used by one sign-in only.
 * @returns {Language | null}
 */
export function takeLoginLanguage() {
  const language = chosen;
  chosen = null;
  return language;
}

/** Forgets the choice (sign-out). */
export function forgetLoginLanguage() {
  chosen = null;
}
