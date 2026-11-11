import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect } from '@playwright/test';

/** @typedef {'en' | 'ru'} Language */

export const LANGUAGES = /** @type {const} */ (['en', 'ru']);
const NAMESPACES = ['common', 'auth', 'board', 'task', 'dashboard', 'errors', 'mywork'];
const LOCALES_DIR = join(__dirname, '..', '..', 'src', 'i18n', 'locales');

/** @type {Record<string, Record<string, any>>} */
const resources = Object.fromEntries(
  LANGUAGES.map((lng) => [
    lng,
    Object.fromEntries(
      NAMESPACES.map((ns) => [
        ns,
        JSON.parse(readFileSync(join(LOCALES_DIR, lng, `${ns}.json`), 'utf8')),
      ]),
    ),
  ]),
);

/**
 * The app's own translation of a key, so the tests find controls by the text a user reads.
 * Supports what the app's strings use: nesting, {{placeholders}} and `_one/_few/_other` plurals.
 * @param {Language} lng
 * @param {string} key `namespace:dotted.key`
 * @param {Record<string, string | number>} [params]
 * @returns {string}
 */
export function tr(lng, key, params = {}) {
  const [ns, path] = key.split(':');
  /** @type {any} */
  let node = resources[lng][ns];
  const parts = path.split('.');
  const last = parts.pop() ?? '';
  for (const part of parts) {
    node = node?.[part];
  }
  let value = node?.[last];
  if (value === undefined && typeof params.count === 'number') {
    value = node?.[`${last}_${new Intl.PluralRules(lng).select(params.count)}`];
  }
  if (typeof value !== 'string') {
    throw new Error(`No ${lng} translation for ${key}`);
  }
  return value.replace(/\{\{(\w+)\}\}/g, (_, name) => String(params[name] ?? `{{${name}}}`));
}

/**
 * @param {Record<string, any>} node
 * @param {string} prefix
 * @param {Set<string>} out
 */
function collectKeys(node, prefix, out) {
  for (const [key, value] of Object.entries(node)) {
    const full = prefix === '' ? key : `${prefix}.${key}`;
    if (typeof value === 'string') {
      out.add(full.replace(/_(zero|one|two|few|many|other)$/, ''));
    } else {
      collectKeys(value, full, out);
    }
  }
  return out;
}

/** Every dotted key of the app (a single word such as "title" is also plain English). */
const DOTTED_KEYS = [
  ...collectKeys(resources.en, '', new Set()),
  ...NAMESPACES.flatMap((ns) => [...collectKeys(resources.en[ns], '', new Set())]),
].filter((key) => key.includes('.'));

const NAMESPACED_KEY = /\b(common|auth|board|task|dashboard|errors|mywork):[A-Za-z_.]+/;
const DOTTED_WORDS = /\b[a-z]+\.[a-z]+\.[a-z]+\b/;

/**
 * Fails when the visible text of the page shows an untranslated key: `task:fields.deadline`,
 * a key of the app such as `side.queueWait`, or any `word.word.word` outside a link.
 * @param {import('@playwright/test').Page} page
 */
export async function expectNoRawKeys(page) {
  const text = await page.evaluate(() => document.body.innerText);
  const withoutUrls = text.replace(/https?:\/\/\S+/g, ' ');
  const found = [
    text.match(NAMESPACED_KEY)?.[0],
    withoutUrls.match(DOTTED_WORDS)?.[0],
    DOTTED_KEYS.find((key) =>
      new RegExp(`(^|[^\\w.])${key.replace(/\./g, '\\.')}($|[^\\w.])`).test(text),
    ),
  ].filter((match) => match !== undefined);
  expect(found, `raw i18n keys on ${page.url()}`).toEqual([]);
}

/**
 * The page is in the language: <html lang> says so and so does a string the caller expects.
 * @param {import('@playwright/test').Page} page
 * @param {Language} lng
 */
export async function expectLanguage(page, lng) {
  await expect(page.locator('html')).toHaveAttribute('lang', lng);
  await expect(page).toHaveTitle(tr(lng, 'common:appName'));
}
