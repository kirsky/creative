import { detectLanguage, readStoredLanguage, storeLanguage } from './detect';

/** @param {string | undefined} language */
function setNavigatorLanguage(language) {
  jest.spyOn(window.navigator, 'language', 'get').mockReturnValue(/** @type {string} */ (language));
}

beforeEach(() => {
  localStorage.clear();
});

afterEach(() => {
  jest.restoreAllMocks();
});

describe('detectLanguage', () => {
  it('a valid stored value beats the browser language', () => {
    localStorage.setItem('creative.lang', 'en');
    setNavigatorLanguage('ru-RU');
    expect(detectLanguage()).toBe('en');
    localStorage.setItem('creative.lang', 'ru');
    setNavigatorLanguage('en-US');
    expect(detectLanguage()).toBe('ru');
  });

  it.each([
    ['ru-RU', 'ru'],
    ['ru', 'ru'],
    ['RU', 'ru'],
    ['en-US', 'en'],
    ['de', 'en'],
    ['de-DE', 'en'],
    ['', 'en'],
  ])('browser language %p -> %s', (navigatorLanguage, expected) => {
    setNavigatorLanguage(navigatorLanguage);
    expect(detectLanguage()).toBe(expected);
  });

  it('ignores a stored value that is not a supported language', () => {
    localStorage.setItem('creative.lang', 'fr');
    setNavigatorLanguage('ru-RU');
    expect(detectLanguage()).toBe('ru');
    localStorage.setItem('creative.lang', '');
    setNavigatorLanguage('de');
    expect(detectLanguage()).toBe('en');
  });

  it('falls back to English when the browser reports no language', () => {
    setNavigatorLanguage(undefined);
    expect(detectLanguage()).toBe('en');
  });

  it('tolerates storage that throws on read', () => {
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('storage blocked');
    });
    setNavigatorLanguage('ru-RU');
    expect(readStoredLanguage()).toBeNull();
    expect(detectLanguage()).toBe('ru');
  });
});

describe('storeLanguage', () => {
  it('writes creative.lang', () => {
    storeLanguage('ru');
    expect(localStorage.getItem('creative.lang')).toBe('ru');
  });

  it('tolerates storage that throws on write', () => {
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota');
    });
    expect(() => storeLanguage('ru')).not.toThrow();
  });
});
