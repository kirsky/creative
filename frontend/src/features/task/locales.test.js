import enCommon from '../../i18n/locales/en/common.json';
import enTask from '../../i18n/locales/en/task.json';
import ruCommon from '../../i18n/locales/ru/common.json';
import ruTask from '../../i18n/locales/ru/task.json';

/**
 * Every string of a resource file.
 * @param {unknown} node
 * @returns {string[]}
 */
function strings(node) {
  if (typeof node === 'string') {
    return [node];
  }
  if (typeof node === 'object' && node !== null) {
    return Object.values(node).flatMap(strings);
  }
  return [];
}

describe('wording of the task namespace', () => {
  it('has strings to scan', () => {
    expect(strings(enTask).length).toBeGreaterThan(50);
    expect(strings(ruTask).length).toBeGreaterThan(50);
  });

  it.each([
    ['en', [...strings(enTask), ...strings(enCommon)]],
    ['ru', [...strings(ruTask), ...strings(ruCommon)]],
  ])('never says Owner in %s', (_lng, texts) => {
    for (const text of texts) {
      expect(text).not.toMatch(/\bowner|владел/i);
    }
  });

  it.each([
    ['en', strings(enTask), /working time|time spent|hours spent|time worked/i],
    [
      'ru',
      strings(ruTask),
      /рабочее время|рабочего времени|затраченное время|затраченного времени/i,
    ],
  ])('never calls elapsed time working time or time spent in %s', (_lng, texts, banned) => {
    for (const text of texts) {
      expect(text).not.toMatch(banned);
    }
  });

  it('keeps the note that elapsed time is not hours worked', () => {
    expect(enTask.side.elapsedNote).toBe('Elapsed time, not hours worked.');
    expect(ruTask.side.elapsedNote).toBe('Прошедшее время, а не затраченные часы.');
  });
});
