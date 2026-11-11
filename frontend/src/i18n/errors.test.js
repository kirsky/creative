import { ApiRequestError } from '../api/ApiRequestError';
import {
  FIELD_ERROR_CODES,
  groupFieldErrors,
  KNOWN_API_ERROR_CODES,
  translateApiError,
  translateFieldError,
} from './errors';
import i18n from './index';

const languages = /** @type {const} */ (['en', 'ru']);
/** @param {'en' | 'ru'} lng */
const tFor = (lng) => i18n.getFixedT(lng);

/** @param {string} text */
function expectReadable(text) {
  expect(typeof text).toBe('string');
  expect(text.trim()).not.toBe('');
  expect(text).not.toContain('{{');
  expect(text).not.toMatch(/^[a-z_]+:/);
  expect(text).not.toMatch(/undefined|NaN/);
}

describe('translateApiError', () => {
  it('covers every code of the API and the client', () => {
    expect([...KNOWN_API_ERROR_CODES].sort()).toEqual(
      [
        'unauthenticated',
        'invalid_credentials',
        'forbidden',
        'not_found',
        'validation_failed',
        'invalid_transition',
        'already_taken',
        'rate_limited',
        'file_too_large',
        'file_type_not_allowed',
        'file_empty',
        'task_not_editable',
        'internal_error',
        'payload_too_large',
        'network_error',
        'unknown',
      ].sort(),
    );
  });

  it.each(languages)('has a readable, distinct message for each code in %s', (lng) => {
    const t = tFor(lng);
    const seen = new Set();
    for (const code of KNOWN_API_ERROR_CODES) {
      const params =
        code === 'rate_limited'
          ? { retryAfterSeconds: 30 }
          : code === 'file_too_large'
            ? { maxBytes: 99000000 }
            : {};
      const message = translateApiError(t, new ApiRequestError(400, code, [], params));
      expectReadable(message);
      expect(seen.has(message)).toBe(false);
      seen.add(message);
    }
  });

  it('English and Russian differ for every code', () => {
    for (const code of KNOWN_API_ERROR_CODES) {
      const params = { retryAfterSeconds: 30, maxBytes: 99000000 };
      const en = translateApiError(tFor('en'), new ApiRequestError(400, code, [], params));
      const ru = translateApiError(tFor('ru'), new ApiRequestError(400, code, [], params));
      expect(ru).not.toBe(en);
    }
  });

  it('rate_limited mentions minutes, rounded up', () => {
    const err = new ApiRequestError(429, 'rate_limited', [], { retryAfterSeconds: 125 });
    expect(translateApiError(tFor('en'), err)).toContain('3 minutes');
    expect(translateApiError(tFor('ru'), err)).toContain('3 минуты');
  });

  it('rate_limited uses seconds under a minute and singular forms', () => {
    const at = (/** @type {number} */ s) =>
      new ApiRequestError(429, 'rate_limited', [], { retryAfterSeconds: s });
    expect(translateApiError(tFor('en'), at(45))).toContain('45 seconds');
    expect(translateApiError(tFor('ru'), at(45))).toContain('45 секунд');
    expect(translateApiError(tFor('ru'), at(1))).toContain('1 секунду');
    expect(translateApiError(tFor('en'), at(60))).toContain('1 minute');
    expect(translateApiError(tFor('en'), at(61))).toContain('2 minutes');
    expect(translateApiError(tFor('ru'), at(15 * 60))).toContain('15 минут');
  });

  it('rate_limited without a usable wait still reads well', () => {
    for (const params of [{}, { retryAfterSeconds: 'soon' }, { retryAfterSeconds: -3 }]) {
      const err = new ApiRequestError(429, 'rate_limited', [], params);
      expectReadable(translateApiError(tFor('en'), err));
      expectReadable(translateApiError(tFor('ru'), err));
    }
  });

  it('file_too_large shows the limit in MB', () => {
    const err = new ApiRequestError(413, 'file_too_large', [], { maxBytes: 99000000 });
    expect(translateApiError(tFor('en'), err)).toContain('99 MB');
    expect(translateApiError(tFor('ru'), err)).toContain('99 МБ');
  });

  it('file_too_large without a limit still reads well', () => {
    const err = new ApiRequestError(413, 'file_too_large');
    expectReadable(translateApiError(tFor('en'), err));
    expectReadable(translateApiError(tFor('ru'), err));
  });

  it('an unknown code falls back to errors:unknown', () => {
    for (const lng of languages) {
      const t = tFor(lng);
      const fallback = t('errors:unknown');
      expect(translateApiError(t, new ApiRequestError(500, 'something_new'))).toBe(fallback);
      expect(translateApiError(t, new ApiRequestError(500, 'constructor'))).toBe(fallback);
      expect(translateApiError(t, new ApiRequestError(0, 'unknown'))).toBe(fallback);
    }
  });

  it('client codes: network_error and payload_too_large', () => {
    expect(translateApiError(tFor('en'), new ApiRequestError(0, 'network_error'))).toMatch(
      /connect|reach/i,
    );
    expect(translateApiError(tFor('en'), new ApiRequestError(413, 'payload_too_large'))).toMatch(
      /large/i,
    );
  });
});

describe('translateFieldError', () => {
  it('too_short{min:2}', () => {
    expect(translateFieldError(tFor('en'), { code: 'too_short', params: { min: 2 } })).toBe(
      'Must be at least 2 characters',
    );
    expect(translateFieldError(tFor('ru'), { code: 'too_short', params: { min: 2 } })).toBe(
      'Минимум 2 символа',
    );
  });

  it('too_long{max}, too_many{max} and out_of_range{min,max} interpolate their numbers', () => {
    for (const lng of languages) {
      const t = tFor(lng);
      expect(translateFieldError(t, { code: 'too_long', params: { max: 100 } })).toContain('100');
      expect(translateFieldError(t, { code: 'too_many', params: { max: 25 } })).toContain('25');
      const range = translateFieldError(t, { code: 'out_of_range', params: { min: 1, max: 100 } });
      expect(range).toContain('1');
      expect(range).toContain('100');
    }
    expect(translateFieldError(tFor('ru'), { code: 'too_long', params: { max: 100 } })).toBe(
      'Максимум 100 символов',
    );
    expect(translateFieldError(tFor('ru'), { code: 'too_many', params: { max: 20 } })).toContain(
      'элементов',
    );
  });

  it.each(languages)('every field code has a readable message in %s', (lng) => {
    const t = tFor(lng);
    const params = { min: 2, max: 10 };
    for (const code of FIELD_ERROR_CODES) {
      expectReadable(translateFieldError(t, { code, params }));
    }
  });

  it('English and Russian differ for every field code', () => {
    const params = { min: 2, max: 10 };
    for (const code of FIELD_ERROR_CODES) {
      expect(translateFieldError(tFor('ru'), { code, params })).not.toBe(
        translateFieldError(tFor('en'), { code, params }),
      );
    }
  });

  it('covers the field codes of the API', () => {
    expect([...FIELD_ERROR_CODES].sort()).toEqual(
      [
        'required',
        'too_short',
        'too_long',
        'out_of_range',
        'invalid_choice',
        'invalid_url',
        'must_be_future',
        'invalid_amount',
        'too_many',
        'invalid',
        'unknown_field',
      ].sort(),
    );
  });

  it('an unknown field code falls back to errors:unknown', () => {
    const t = tFor('en');
    expect(translateFieldError(t, { code: 'brand_new', params: {} })).toBe(t('errors:unknown'));
  });

  it('a limit code without its number does not print a hole', () => {
    for (const lng of languages) {
      const t = tFor(lng);
      for (const code of ['too_short', 'too_long', 'too_many', 'out_of_range']) {
        expectReadable(translateFieldError(t, { code, params: {} }));
      }
    }
  });

  it('the _invalid sentinel field is a generic message whatever its code', () => {
    const t = tFor('en');
    const generic = translateFieldError(t, {
      field: '_invalid',
      code: 'unknown_field',
      params: {},
    });
    expectReadable(generic);
    expect(generic).toBe(
      translateFieldError(t, { field: '_invalid', code: 'invalid', params: {} }),
    );
    expect(generic).not.toBe(translateFieldError(t, { code: 'unknown_field', params: {} }));
  });
});

describe('groupFieldErrors', () => {
  it('maps fields to their first message and gathers _invalid as form-level errors', () => {
    const t = tFor('en');
    const err = new ApiRequestError(
      422,
      'validation_failed',
      [
        { field: 'offer', code: 'too_short', params: { min: 2 } },
        { field: 'offer', code: 'invalid', params: {} },
        { field: 'quantity', code: 'out_of_range', params: { min: 1, max: 100 } },
        { field: '_invalid', code: 'unknown_field', params: {} },
      ],
      {},
    );
    const grouped = groupFieldErrors(t, err);
    expect(Object.keys(grouped.fields)).toEqual(['offer', 'quantity']);
    expect(grouped.fields.offer).toBe('Must be at least 2 characters');
    expect(grouped.fields.quantity).toContain('100');
    expect(grouped.form).toHaveLength(1);
  });

  it('is empty for an error without fields', () => {
    expect(groupFieldErrors(tFor('en'), new ApiRequestError(500, 'internal_error'))).toEqual({
      fields: {},
      form: [],
    });
  });
});
