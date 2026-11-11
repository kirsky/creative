import i18n from './index';

const en = i18n.getFixedT('en');
const ru = i18n.getFixedT('ru');

describe('plural forms with real keys', () => {
  it.each([
    [0, 'креативов'],
    [1, 'креатив'],
    [2, 'креатива'],
    [4, 'креатива'],
    [5, 'креативов'],
    [11, 'креативов'],
    [12, 'креативов'],
    [21, 'креатив'],
    [22, 'креатива'],
    [25, 'креативов'],
    [101, 'креатив'],
    [111, 'креативов'],
  ])('ru quantity: %i -> %s', (count, word) => {
    expect(ru('common:creatives', { count })).toBe(`${count} ${word}`);
  });

  it.each([
    [1, 'creative'],
    [2, 'creatives'],
    [0, 'creatives'],
    [11, 'creatives'],
  ])('en quantity: %i -> %s', (count, word) => {
    expect(en('common:creatives', { count })).toBe(`${count} ${word}`);
  });

  it('error messages with a count follow the language plural rules', () => {
    expect(ru('errors:too_short', { count: 1 })).toBe('Минимум 1 символ');
    expect(ru('errors:too_short', { count: 5 })).toBe('Минимум 5 символов');
    expect(ru('errors:too_short', { count: 21 })).toBe('Минимум 21 символ');
    expect(en('errors:too_short', { count: 1 })).toBe('Must be at least 1 character');
    expect(en('errors:too_short', { count: 2 })).toBe('Must be at least 2 characters');
  });
});
