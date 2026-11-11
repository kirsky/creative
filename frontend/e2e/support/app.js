import { expect } from '@playwright/test';
import { ACCOUNTS, passwordOf } from './credentials';
import { expectLanguage, expectNoRawKeys } from './i18n';

/** @typedef {import('./i18n').Language} Language */
/** @typedef {import('./credentials').Username} Username */

/**
 * Where each role lands after signing in (src/features/auth/landing.js).
 * @type {Record<string, string>}
 */
export const LANDING = { buyer: '/board', designer: '/my-work', manager: '/dashboard' };

/** @param {Language} lng */
export function browserLocale(lng) {
  return lng === 'ru' ? 'ru-RU' : 'en-US';
}

/**
 * Types a secret into an input without Playwright's `fill`/`type`, whose step titles carry the
 * text into reports: the value is set the way a browser would and React is told by an input event.
 * @param {import('@playwright/test').Locator} input
 * @param {string} secret
 */
export async function fillSecret(input, secret) {
  await input.evaluate((element, value) => {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    setter?.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
  }, secret);
}

/**
 * Empties every password field of the page, so neither a screenshot nor the accessibility
 * snapshot of a failure report (which lists input values) can show it.
 * @param {import('@playwright/test').Page} page
 */
export async function blankPasswords(page) {
  await page
    .locator('input[type="password"]')
    .evaluateAll((inputs) => {
      for (const input of inputs) {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        setter?.call(input, '');
      }
    })
    .catch(() => undefined);
}

/**
 * A screenshot that never shows a password field's content.
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
export async function safeScreenshot(page, path) {
  await blankPasswords(page);
  await page.screenshot({ path, fullPage: true, animations: 'disabled' });
}

/** @param {import('@playwright/test').Page} page */
function loginForm(page) {
  return {
    username: page.locator('input[autocomplete="username"]'),
    password: page.locator('input[autocomplete="current-password"]'),
    submit: page.locator('form button[type="submit"]'),
  };
}

/**
 * Signs in through the login page and waits for the role's landing page.
 * @param {import('@playwright/test').Page} page
 * @param {Username} username
 * @param {{navigate?: boolean}} [options] `navigate: false` uses the login page already open (a
 *   fresh load would forget where the app came from)
 */
export async function signIn(page, username, { navigate = true } = {}) {
  if (navigate) {
    await page.goto('/login');
  }
  await expect(page).toHaveURL(/\/login$/);
  const form = loginForm(page);
  await form.username.fill(username);
  try {
    await fillSecret(form.password, passwordOf(username));
    await form.submit.click();
    await expect(page).toHaveURL(new RegExp(`${LANDING[ACCOUNTS[username].role]}$`));
  } catch (error) {
    // Playwright's failure report holds an accessibility snapshot of the page, and that
    // snapshot includes the value of a password field.
    await blankPasswords(page);
    throw error;
  }
  await expect(page.locator('header')).toContainText(ACCOUNTS[username].displayName);
}

/**
 * Signs in with a wrong password (once: the per-IP rate limit counts every failure).
 * @param {import('@playwright/test').Page} page
 * @param {Username} username
 */
export async function signInWithWrongPassword(page, username) {
  const form = loginForm(page);
  await form.username.fill(username);
  await fillSecret(form.password, `wrong-${Date.now()}`);
  await form.submit.click();
}

/**
 * Switches the language with the header switcher and waits until the server has stored it.
 * @param {import('@playwright/test').Page} page
 * @param {Language} lng
 */
export async function switchLanguage(page, lng) {
  const saved = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/me/locale') && response.request().method() === 'PATCH',
  );
  await page.locator('header').getByText(lng.toUpperCase(), { exact: true }).click();
  expect((await saved).status()).toBe(200);
  await expectLanguage(page, lng);
}

/**
 * Makes the interface use `lng`, through the switcher; when it already does, switches away and
 * back, so the switch and the server's copy of the choice are exercised in every run.
 * @param {import('@playwright/test').Page} page
 * @param {Language} lng
 */
export async function useLanguage(page, lng) {
  const current = await page.locator('html').getAttribute('lang');
  if (current === lng) {
    await switchLanguage(page, lng === 'en' ? 'ru' : 'en');
  }
  await switchLanguage(page, lng);
  const me = await page.request.get('/api/auth/me');
  expect((await me.json()).locale).toBe(lng);
}

/**
 * A new browser context in the language, signed in as the user, interface switched to `lng`.
 * @param {import('@playwright/test').Browser} browser
 * @param {Username} username
 * @param {Language} lng
 * @param {import('@playwright/test').BrowserContextOptions} [options]
 */
export async function openAs(browser, username, lng, options = {}) {
  const context = await browser.newContext({ locale: browserLocale(lng), ...options });
  const page = await context.newPage();
  await signIn(page, username);
  await useLanguage(page, lng);
  return { context, page };
}

/**
 * What every visited page must pass: right language, no raw keys, no broken values.
 * @param {import('@playwright/test').Page} page
 * @param {Language} lng
 */
export async function checkPage(page, lng) {
  await expectLanguage(page, lng);
  await expectNoRawKeys(page);
  const text = await page.evaluate(() => document.body.innerText);
  expect(text.match(/\bNaN\b|\bundefined\b|\[object Object\]|\bnull\b/g), page.url()).toBeNull();
}

/**
 * The CSRF token of the signed-in page (the SPA keeps it from /api/me), for direct API calls.
 * @param {import('@playwright/test').Page} page
 */
export async function csrfToken(page) {
  const me = await page.request.get('/api/auth/me');
  expect(me.status()).toBe(200);
  return /** @type {string} */ ((await me.json()).csrfToken);
}
