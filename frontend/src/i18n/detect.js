/** @typedef {'en' | 'ru'} Language */

/** @type {readonly Language[]} */
export const LANGUAGES = ['en', 'ru'];
export const DEFAULT_LANGUAGE = /** @type {Language} */ ('en');
export const STORAGE_KEY = 'creative.lang';

/**
 * @param {unknown} value
 * @returns {value is Language}
 */
export function isLanguage(value) {
  return value === 'en' || value === 'ru';
}

/**
 * The language kept in browser storage, or null when there is none, it is not a supported
 * language, or storage cannot be read (private mode, blocked site data).
 * @returns {Language | null}
 */
export function readStoredLanguage() {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return isLanguage(stored) ? stored : null;
  } catch {
    return null;
  }
}

/** @param {Language} language */
export function storeLanguage(language) {
  try {
    window.localStorage.setItem(STORAGE_KEY, language);
  } catch {
    // Storage is a convenience; the choice still applies for this visit.
  }
}

/**
 * The language to start in: the stored choice, else Russian for a Russian browser, else English.
 * @returns {Language}
 */
export function detectLanguage() {
  const stored = readStoredLanguage();
  if (stored !== null) {
    return stored;
  }
  const browser = typeof navigator.language === 'string' ? navigator.language.toLowerCase() : '';
  return browser.startsWith('ru') ? 'ru' : DEFAULT_LANGUAGE;
}
