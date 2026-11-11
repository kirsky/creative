const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { checkLocales, NAMESPACES } = require('./check-i18n-keys');

const SCRIPT = path.join(__dirname, 'check-i18n-keys.js');
const REAL_LOCALES = path.join(__dirname, '..', 'src', 'i18n', 'locales');

/** @type {string[]} */
const tempDirs = [];

/**
 * Writes {en: {ns: object|string}, ru: ...} to a temp directory. A string value is written
 * verbatim (to produce broken JSON); null skips the file.
 * @param {Record<string, Record<string, unknown>>} tree
 */
function makeLocales(tree) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'i18n-check-'));
  tempDirs.push(dir);
  for (const [lng, files] of Object.entries(tree)) {
    fs.mkdirSync(path.join(dir, lng), { recursive: true });
    for (const [ns, content] of Object.entries(files)) {
      if (content === null) {
        continue;
      }
      const text = typeof content === 'string' ? content : JSON.stringify(content);
      fs.writeFileSync(path.join(dir, lng, `${ns}.json`), text);
    }
  }
  return dir;
}

afterAll(() => {
  for (const dir of tempDirs) {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

const options = (/** @type {string} */ dir) => ({ dir, namespaces: ['common'] });

describe('checkLocales', () => {
  it('passes when both languages have the same keys, nested keys included', () => {
    const dir = makeLocales({
      en: { common: { a: 'A', nested: { b: 'B {{x}}' }, n_one: '1', n_other: 'many' } },
      ru: {
        common: {
          a: 'А',
          nested: { b: 'Б {{x}}' },
          n_one: '1',
          n_few: '2',
          n_many: '5',
          n_other: '9',
        },
      },
    });
    expect(checkLocales(options(dir))).toEqual([]);
  });

  it('reports a key missing from ru, naming the key and the language', () => {
    const dir = makeLocales({
      en: { common: { a: 'A', nested: { b: 'B' } } },
      ru: { common: { a: 'А' } },
    });
    const problems = checkLocales(options(dir));
    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('common:nested.b');
    expect(problems[0]).toContain('ru');
  });

  it('reports a key missing from en', () => {
    const dir = makeLocales({
      en: { common: { a: 'A' } },
      ru: { common: { a: 'А', extra: 'Лишний' } },
    });
    const problems = checkLocales(options(dir));
    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('common:extra');
    expect(problems[0]).toContain('en');
  });

  it('lists every offending key, not just the first', () => {
    const dir = makeLocales({
      en: { common: { a: 'A', b: 'B', c: 'C' } },
      ru: { common: { x: 'Х', y: 'У' } },
    });
    const text = checkLocales(options(dir)).join('\n');
    for (const key of ['common:a', 'common:b', 'common:c', 'common:x', 'common:y']) {
      expect(text).toContain(key);
    }
  });

  it('reports a plural key that lacks a required form', () => {
    const dir = makeLocales({
      en: { common: { n_one: '1 item' } },
      ru: { common: { n_one: '1', n_few: '2', n_other: '9' } },
    });
    const text = checkLocales(options(dir)).join('\n');
    expect(text).toContain('common:n');
    expect(text).toContain('en');
    expect(text).toContain('_other');
    expect(text).toContain('ru');
    expect(text).toContain('_many');
  });

  it('requires one, few, many, other for Russian and one, other for English', () => {
    const dir = makeLocales({
      en: { common: { n_one: '1', n_other: '2' } },
      ru: { common: { n_one: '1', n_few: '2', n_many: '5' } },
    });
    const problems = checkLocales(options(dir));
    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('_other');
    expect(problems[0]).toContain('ru');
  });

  it('reports a namespace file missing in either language', () => {
    const dir = makeLocales({ en: { common: { a: 'A' } }, ru: { common: null } });
    const problems = checkLocales(options(dir));
    expect(problems.join('\n')).toMatch(/ru[/\\]common\.json/);
    expect(problems.join('\n')).toMatch(/missing/i);
  });

  it('reports a namespace missing in both languages', () => {
    const dir = makeLocales({ en: { common: { a: 'A' } }, ru: { common: { a: 'А' } } });
    const problems = checkLocales({ dir, namespaces: ['common', 'auth'] });
    expect(problems).toHaveLength(2);
  });

  it('reports invalid JSON and empty or non-string values', () => {
    const broken = makeLocales({ en: { common: '{ nope' }, ru: { common: { a: 'А' } } });
    expect(checkLocales(options(broken)).join('\n')).toMatch(/common\.json/);
    const hollow = makeLocales({
      en: { common: { a: '', b: 5 } },
      ru: { common: { a: 'А', b: 'Б' } },
    });
    const text = checkLocales(options(hollow)).join('\n');
    expect(text).toContain('common:a');
    expect(text).toContain('common:b');
  });

  it('reports {{placeholders}} that differ between the languages', () => {
    const dir = makeLocales({
      en: { common: { greet: 'Hello {{name}}' } },
      ru: { common: { greet: 'Привет {{imya}}' } },
    });
    const problems = checkLocales(options(dir));
    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('common:greet');
  });

  it('lets plural forms differ in which placeholders they use', () => {
    const dir = makeLocales({
      en: { common: { n_one: 'One item', n_other: '{{count}} items' } },
      ru: {
        common: {
          n_one: 'Один',
          n_few: '{{count}} шт',
          n_many: '{{count}} шт',
          n_other: '{{count}}',
        },
      },
    });
    expect(checkLocales(options(dir))).toEqual([]);
  });
});

describe('command line', () => {
  /** @param {string} dir */
  const run = (dir) =>
    spawnSync(process.execPath, [SCRIPT, dir], { encoding: 'utf8', env: { ...process.env } });

  it('exits non-zero when a key is missing from ru and prints it', () => {
    const dir = makeLocales({
      en: { common: { a: 'A', b: 'B' } },
      ru: { common: { a: 'А' } },
    });
    const result = spawnSync(process.execPath, [SCRIPT, dir, 'common'], { encoding: 'utf8' });
    expect(result.status).toBe(1);
    expect(result.stderr).toContain('common:b');
  });

  it('exits zero for identical key sets', () => {
    const dir = makeLocales({ en: { common: { a: 'A' } }, ru: { common: { a: 'А' } } });
    const result = spawnSync(process.execPath, [SCRIPT, dir, 'common'], { encoding: 'utf8' });
    expect(result.status).toBe(0);
    expect(result.stdout).toMatch(/ok/i);
  });

  it('exits non-zero when a namespace file is missing', () => {
    const dir = makeLocales({ en: { common: { a: 'A' } }, ru: {} });
    expect(run(dir).status).toBe(1);
  });
});

describe('the real locale files', () => {
  it('pass the check', () => {
    expect(checkLocales({ dir: REAL_LOCALES })).toEqual([]);
  });

  it('are checked for the same namespaces the app loads', () => {
    const { NAMESPACES: appNamespaces } = require('../src/i18n/namespaces');
    expect(NAMESPACES).toEqual(appNamespaces);
  });

  it('the npm script entry point passes on the real files', () => {
    const result = spawnSync(process.execPath, [SCRIPT], { encoding: 'utf8' });
    expect(result.status).toBe(0);
  });
});
