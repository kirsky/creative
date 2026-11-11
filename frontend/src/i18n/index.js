import dayjs from 'dayjs';
import 'dayjs/locale/en';
import 'dayjs/locale/ru';
import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';
import { apiFetch, getCsrfToken } from '../api/client';
import { DEFAULT_LANGUAGE, detectLanguage, isLanguage, storeLanguage } from './detect';
import enAuth from './locales/en/auth.json';
import enBoard from './locales/en/board.json';
import enCommon from './locales/en/common.json';
import enDashboard from './locales/en/dashboard.json';
import enErrors from './locales/en/errors.json';
import enTask from './locales/en/task.json';
import ruAuth from './locales/ru/auth.json';
import ruBoard from './locales/ru/board.json';
import ruCommon from './locales/ru/common.json';
import ruDashboard from './locales/ru/dashboard.json';
import ruErrors from './locales/ru/errors.json';
import ruTask from './locales/ru/task.json';
import { NAMESPACES } from './namespaces';

export { NAMESPACES };

/** @typedef {import('./detect').Language} Language */

// The resources are bundled, not loaded on demand: they are a few kilobytes and bundling means
// the first paint is already translated.
const resources = {
  en: {
    common: enCommon,
    auth: enAuth,
    board: enBoard,
    task: enTask,
    dashboard: enDashboard,
    errors: enErrors,
  },
  ru: {
    common: ruCommon,
    auth: ruAuth,
    board: ruBoard,
    task: ruTask,
    dashboard: ruDashboard,
    errors: ruErrors,
  },
};

const i18n = i18next.createInstance();

/** What follows a language change, whoever caused it. */
function applyLanguage() {
  const language = isLanguage(i18n.language) ? i18n.language : DEFAULT_LANGUAGE;
  document.documentElement.lang = language;
  dayjs.locale(language);
  document.title = i18n.t('common:appName');
}

i18n.on('languageChanged', applyLanguage);

i18n.use(initReactI18next).init({
  resources,
  lng: detectLanguage(),
  fallbackLng: DEFAULT_LANGUAGE,
  supportedLngs: ['en', 'ru'],
  ns: NAMESPACES,
  defaultNS: 'common',
  // Resources are in memory, so initialisation finishes synchronously.
  initAsync: false,
  // React escapes what it renders.
  interpolation: { escapeValue: false },
});

/** Sends the saved language to the server in order, so quick switches cannot land reversed. */
let serverSync = Promise.resolve();

/** @param {Language} language */
function saveOnServer(language) {
  serverSync = serverSync.then(async () => {
    try {
      await apiFetch('/api/me/locale', { method: 'PATCH', body: { locale: language } });
    } catch (error) {
      // The switch itself has worked; the server will simply still hold the old value.
      if (process.env.NODE_ENV !== 'production') {
        console.warn('Could not save the language on the server', error);
      }
    }
  });
  return serverSync;
}

/**
 * Switches the interface language: i18n, <html lang>, the dayjs locale (and through it Mantine's
 * date components), the page title and localStorage. For a signed-in user it also saves the
 * choice on the account; that request failing never undoes or blocks the switch.
 * @param {Language} language
 * @param {{syncServer?: boolean}} [options] pass `syncServer: false` when the value comes from
 *   the server (after sign-in), so it is not sent straight back
 * @returns {Promise<void>}
 */
export async function changeLanguage(language, { syncServer = true } = {}) {
  storeLanguage(language);
  if (i18n.language !== language) {
    await i18n.changeLanguage(language);
  }
  if (syncServer && getCsrfToken() !== null) {
    await saveOnServer(language);
  }
}

applyLanguage();

export default i18n;
