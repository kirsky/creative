'use strict';

/**
 * Translation parity check, run by `npm run i18n:check` (part of `npm run check`).
 *
 * Fails when, across src/i18n/locales/{en,ru}/<namespace>.json:
 *  - a namespace file is missing or is not valid JSON in either language,
 *  - a key exists in one language but not the other (nested keys are flattened to a.b.c),
 *  - a plural key lacks a form its language needs (en: one, other; ru: one, few, many, other),
 *  - a value is empty or not a string,
 *  - the {{placeholders}} of a key differ between the languages.
 *
 * Usage: node scripts/check-i18n-keys.js [localesDir] [namespace,namespace,...]
 */

const fs = require('node:fs');
const path = require('node:path');

/** Keep in step with src/i18n/namespaces.js (a test compares the two). */
const NAMESPACES = ['common', 'auth', 'board', 'task', 'dashboard', 'errors'];
const LANGUAGES = ['en', 'ru'];
const DEFAULT_DIR = path.join(__dirname, '..', 'src', 'i18n', 'locales');

/** The plural forms a language's key must have (CLDR categories used by Intl.PluralRules). */
const REQUIRED_PLURAL_FORMS = {
  en: ['one', 'other'],
  ru: ['one', 'few', 'many', 'other'],
};
const PLURAL_SUFFIX = /^(.+)_(zero|one|two|few|many|other)$/;

/**
 * @param {unknown} node
 * @param {string} prefix
 * @param {Map<string, unknown>} out
 * @returns {Map<string, unknown>} dotted key -> leaf value
 */
function flatten(node, prefix = '', out = new Map()) {
  if (typeof node === 'object' && node !== null && !Array.isArray(node)) {
    for (const [key, value] of Object.entries(node)) {
      flatten(value, prefix === '' ? key : `${prefix}.${key}`, out);
    }
  } else {
    out.set(prefix, node);
  }
  return out;
}

/** @param {string} text */
function placeholders(text) {
  return new Set([...text.matchAll(/\{\{\s*([^}\s,]+)[^}]*\}\}/g)].map((match) => match[1]));
}

/**
 * Groups plural forms under their base key.
 * @param {Map<string, unknown>} flat
 * @returns {{singles: Map<string, string>, plurals: Map<string, Map<string, string>>}}
 */
function classify(flat) {
  /** @type {Map<string, string>} */
  const singles = new Map();
  /** @type {Map<string, Map<string, string>>} */
  const plurals = new Map();
  for (const [key, value] of flat) {
    const text = typeof value === 'string' ? value : '';
    const match = PLURAL_SUFFIX.exec(key);
    if (match === null) {
      singles.set(key, text);
    } else {
      const forms = plurals.get(match[1]) ?? new Map();
      forms.set(match[2], text);
      plurals.set(match[1], forms);
    }
  }
  return { singles, plurals };
}

/**
 * @param {string} dir
 * @param {string} language
 * @param {string} namespace
 * @param {string[]} problems
 * @returns {Map<string, unknown> | null} the flattened file, or null when it cannot be used
 */
function load(dir, language, namespace, problems) {
  const file = path.join(dir, language, `${namespace}.json`);
  const shown = path.join(language, `${namespace}.json`);
  if (!fs.existsSync(file)) {
    problems.push(`${shown}: file is missing`);
    return null;
  }
  try {
    return flatten(JSON.parse(fs.readFileSync(file, 'utf8')));
  } catch (error) {
    problems.push(`${shown}: not valid JSON (${error instanceof Error ? error.message : error})`);
    return null;
  }
}

/** @param {Set<string>} set */
const sorted = (set) => [...set].sort().join(', ');

/**
 * @param {{dir?: string, namespaces?: string[]}} [options]
 * @returns {string[]} one line per problem; empty when the locales are in step
 */
function checkLocales({ dir = DEFAULT_DIR, namespaces = NAMESPACES } = {}) {
  /** @type {string[]} */
  const problems = [];

  for (const namespace of namespaces) {
    /** @type {Record<string, ReturnType<typeof classify>>} */
    const parsed = {};
    for (const language of LANGUAGES) {
      const flat = load(dir, language, namespace, problems);
      if (flat === null) {
        continue;
      }
      for (const [key, value] of flat) {
        if (typeof value !== 'string' || value.trim() === '') {
          problems.push(`${language} ${namespace}:${key}: value is empty or not a string`);
        }
      }
      parsed[language] = classify(flat);
    }

    for (const language of LANGUAGES) {
      const current = parsed[language];
      if (current === undefined) {
        continue;
      }
      for (const [base, forms] of current.plurals) {
        const missing = REQUIRED_PLURAL_FORMS[language].filter((form) => !forms.has(form));
        if (missing.length > 0) {
          problems.push(
            `${language} ${namespace}:${base}: plural is missing ${missing.map((form) => `_${form}`).join(', ')}`,
          );
        }
      }
    }

    if (parsed.en === undefined || parsed.ru === undefined) {
      continue;
    }
    for (const [language, other] of [
      ['en', 'ru'],
      ['ru', 'en'],
    ]) {
      const have = parsed[language];
      const against = parsed[other];
      const keys = new Set([...have.singles.keys(), ...have.plurals.keys()]);
      const otherKeys = new Set([...against.singles.keys(), ...against.plurals.keys()]);
      for (const key of [...otherKeys].filter((candidate) => !keys.has(candidate))) {
        problems.push(`${language} ${namespace}:${key}: key is missing (it exists in ${other})`);
      }
    }

    // Only keys both languages have, and only once, from the English side.
    const bothPlaceholders = (/** @type {ReturnType<typeof classify>} */ side, key) => {
      const texts = side.singles.has(key)
        ? [side.singles.get(key) ?? '']
        : [...(side.plurals.get(key)?.values() ?? [])];
      return new Set(texts.flatMap((text) => [...placeholders(text)]));
    };
    const keys = [...parsed.en.singles.keys(), ...parsed.en.plurals.keys()];
    for (const key of keys) {
      const isShared = parsed.ru.singles.has(key) || parsed.ru.plurals.has(key);
      if (!isShared) {
        continue;
      }
      const enSet = bothPlaceholders(parsed.en, key);
      const ruSet = bothPlaceholders(parsed.ru, key);
      if (sorted(enSet) !== sorted(ruSet)) {
        problems.push(
          `${namespace}:${key}: placeholders differ (en: ${sorted(enSet) || 'none'}; ru: ${sorted(ruSet) || 'none'})`,
        );
      }
    }
  }
  return problems;
}

if (require.main === module) {
  const dir = process.argv[2] === undefined ? DEFAULT_DIR : path.resolve(process.argv[2]);
  const namespaces = process.argv[3] === undefined ? NAMESPACES : process.argv[3].split(',');
  const problems = checkLocales({ dir, namespaces });
  if (problems.length > 0) {
    console.error(`i18n:check found ${problems.length} problem(s):`);
    for (const problem of problems) {
      console.error(`  - ${problem}`);
    }
    process.exit(1);
  }
  console.log(`i18n:check ok - ${namespaces.length} namespace(s) in step across en and ru`);
}

module.exports = { checkLocales, NAMESPACES };
