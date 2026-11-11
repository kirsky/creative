import dayjs from 'dayjs';
import { getCsrfToken, setCsrfToken } from '../api/client';
import { errorResponse, installFetch, mockResponse, makeMe } from '../test/http';
import i18n, { changeLanguage, NAMESPACES } from './index';

beforeEach(() => {
  setCsrfToken(null);
});

afterEach(() => {
  jest.restoreAllMocks();
});

describe('i18n instance', () => {
  it('has the seven namespaces with bundled English and Russian resources', () => {
    expect(NAMESPACES).toEqual([
      'common',
      'auth',
      'board',
      'task',
      'dashboard',
      'errors',
      'mywork',
    ]);
    for (const lng of ['en', 'ru']) {
      for (const ns of NAMESPACES) {
        expect(i18n.hasResourceBundle(lng, ns)).toBe(true);
      }
    }
  });

  it('translates with the namespace prefix', () => {
    expect(i18n.t('common:appName', { lng: 'en' })).toBe('Creative Hub');
    expect(i18n.t('common:appName', { lng: 'ru' })).toBe('Креативный Хаб');
  });
});

describe('changeLanguage', () => {
  it('updates i18n, <html lang>, the dayjs locale, the title and localStorage', async () => {
    await changeLanguage('ru');
    expect(i18n.language).toBe('ru');
    expect(document.documentElement.lang).toBe('ru');
    expect(dayjs.locale()).toBe('ru');
    expect(document.title).toBe('Креативный Хаб');
    expect(localStorage.getItem('creative.lang')).toBe('ru');
    await changeLanguage('en');
    expect(document.documentElement.lang).toBe('en');
    expect(dayjs.locale()).toBe('en');
    expect(document.title).toBe('Creative Hub');
    expect(localStorage.getItem('creative.lang')).toBe('en');
  });

  it('sends no request when nobody is signed in', async () => {
    const fetchMock = installFetch({});
    await changeLanguage('ru');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('PATCHes /api/me/locale with the CSRF token when signed in', async () => {
    setCsrfToken('t'.repeat(64));
    const fetchMock = installFetch({
      'PATCH /api/me/locale': mockResponse(200, makeMe({ locale: 'ru' })),
    });
    await changeLanguage('ru');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const init = fetchMock.mock.calls[0][1];
    expect(JSON.parse(String(init.body))).toEqual({ locale: 'ru' });
    expect(init.headers).toMatchObject({ 'X-CSRF-Token': 't'.repeat(64) });
  });

  it('does not PATCH when asked to apply the server value', async () => {
    setCsrfToken('t'.repeat(64));
    const fetchMock = installFetch({});
    await changeLanguage('ru', { syncServer: false });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(i18n.language).toBe('ru');
    expect(localStorage.getItem('creative.lang')).toBe('ru');
  });

  it('a failed PATCH does not reject and the UI still switches', async () => {
    setCsrfToken('t'.repeat(64));
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});
    installFetch({ 'PATCH /api/me/locale': errorResponse(500, 'internal_error') });
    await expect(changeLanguage('ru')).resolves.toBeUndefined();
    expect(i18n.language).toBe('ru');
    expect(getCsrfToken()).not.toBeNull();
    expect(warn).toHaveBeenCalled();
  });

  it('a network failure of the PATCH does not reject either', async () => {
    setCsrfToken('t'.repeat(64));
    jest.spyOn(console, 'warn').mockImplementation(() => {});
    globalThis.fetch = /** @type {any} */ (jest.fn().mockRejectedValue(new TypeError('offline')));
    await expect(changeLanguage('ru')).resolves.toBeUndefined();
    expect(i18n.language).toBe('ru');
  });

  it('tolerates storage that throws', async () => {
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    await expect(changeLanguage('ru')).resolves.toBeUndefined();
    expect(i18n.language).toBe('ru');
  });

  it('sends the PATCHes of quick successive switches in order', async () => {
    setCsrfToken('t'.repeat(64));
    /** @type {string[]} */
    const sent = [];
    globalThis.fetch = /** @type {any} */ (
      jest.fn(async (/** @type {string} */ _path, /** @type {RequestInit} */ init) => {
        sent.push(JSON.parse(String(init.body)).locale);
        await new Promise((resolve) => setTimeout(resolve, sent.length === 1 ? 20 : 0));
        return mockResponse(200, makeMe());
      })
    );
    await Promise.all([changeLanguage('ru'), changeLanguage('en')]);
    expect(sent).toEqual(['ru', 'en']);
    expect(i18n.language).toBe('en');
  });
});
