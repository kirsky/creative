import i18n from '../../i18n';
import {
  defaultDeadline,
  issueMessage,
  parseIssueMessage,
  taskSchema,
  translateIssue,
} from './schema';

const tEn = i18n.getFixedT('en');
const tRu = i18n.getFixedT('ru');

/** @param {Record<string, unknown>} [overrides] */
function valid(overrides = {}) {
  return {
    offer: 'Casino Royal',
    creativeType: 'video',
    quantity: 1,
    priority: 'normal',
    geo: ['DE'],
    description: 'Make it pop',
    deadline: defaultDeadline(Date.now()),
    urls: [],
    ...overrides,
  };
}

/** @param {Record<string, unknown>} values @returns {Record<string, string>} path -> message */
function problems(values) {
  const result = taskSchema.safeParse(values);
  return result.success
    ? {}
    : Object.fromEntries(result.error.issues.map((issue) => [issue.path.join('.'), issue.message]));
}

describe('issue messages', () => {
  it('a plain code has no params', () => {
    expect(issueMessage('required')).toBe('required');
    expect(parseIssueMessage('required')).toEqual({ code: 'required', params: {} });
  });

  it('carries params and round-trips', () => {
    const message = issueMessage('too_short', { min: 2 });
    expect(parseIssueMessage(message)).toEqual({ code: 'too_short', params: { min: 2 } });
  });

  it('translates through the same path as server errors, in both languages', () => {
    const message = issueMessage('out_of_range', { min: 1, max: 100 });
    expect(translateIssue(tEn, message)).toBe('Must be between 1 and 100.');
    expect(translateIssue(tRu, message)).toBe('Допустимо значение от 1 до 100.');
  });

  it('does not choke on text that is not an issue message', () => {
    expect(parseIssueMessage('{not json')).toEqual({ code: '{not json', params: {} });
    expect(translateIssue(tEn, 'whatever')).toBe(tEn('errors:unknown'));
  });
});

describe('taskSchema', () => {
  it('accepts a valid brief', () => {
    expect(problems(valid())).toEqual({});
  });

  it('offer: "a" is too short with min 2 (translated in en and ru), 100 is the limit', () => {
    const message = problems(valid({ offer: 'a' })).offer;
    expect(parseIssueMessage(message)).toEqual({ code: 'too_short', params: { min: 2 } });
    expect(translateIssue(tEn, message)).toBe('Must be at least 2 characters');
    expect(translateIssue(tRu, message)).toBe('Минимум 2 символа');
    expect(problems(valid({ offer: 'ab' }))).toEqual({});
    expect(problems(valid({ offer: 'x'.repeat(100) }))).toEqual({});
    expect(parseIssueMessage(problems(valid({ offer: 'x'.repeat(101) })).offer)).toEqual({
      code: 'too_long',
      params: { max: 100 },
    });
  });

  it('offer: is trimmed before measuring and an empty one is required', () => {
    expect(parseIssueMessage(problems(valid({ offer: '   ' })).offer).code).toBe('required');
    expect(parseIssueMessage(problems(valid({ offer: ' a ' })).offer).code).toBe('too_short');
  });

  it('offer: counts characters, not UTF-16 units (emoji)', () => {
    expect(problems(valid({ offer: '🎰'.repeat(100) }))).toEqual({});
    expect(problems(valid({ offer: '🎰'.repeat(101) })).offer).toBeDefined();
  });

  it.each([0, 101, 2.5, -1])('quantity %s is rejected', (quantity) => {
    expect(problems(valid({ quantity })).quantity).toBeDefined();
  });

  it.each([1, 100, '7'])('quantity %s is accepted', (quantity) => {
    expect(problems(valid({ quantity }))).toEqual({});
  });

  it('quantity: out of range says 1 to 100, a decimal is invalid, empty is required', () => {
    expect(parseIssueMessage(problems(valid({ quantity: 0 })).quantity)).toEqual({
      code: 'out_of_range',
      params: { min: 1, max: 100 },
    });
    expect(parseIssueMessage(problems(valid({ quantity: 101 })).quantity).code).toBe(
      'out_of_range',
    );
    expect(parseIssueMessage(problems(valid({ quantity: 2.5 })).quantity).code).toBe('invalid');
    expect(parseIssueMessage(problems(valid({ quantity: '' })).quantity).code).toBe('required');
    expect(parseIssueMessage(problems(valid({ quantity: 'abc' })).quantity).code).toBe('invalid');
  });

  it('description: required (also when only spaces), at most 10000, not trimmed', () => {
    expect(parseIssueMessage(problems(valid({ description: '' })).description).code).toBe(
      'required',
    );
    expect(parseIssueMessage(problems(valid({ description: '  \n ' })).description).code).toBe(
      'required',
    );
    expect(problems(valid({ description: 'x'.repeat(10000) }))).toEqual({});
    expect(
      parseIssueMessage(problems(valid({ description: 'x'.repeat(10001) })).description),
    ).toEqual({ code: 'too_long', params: { max: 10000 } });
  });

  it('geo: at least one, at most 30', () => {
    expect(parseIssueMessage(problems(valid({ geo: [] })).geo).code).toBe('required');
    const codes = Array.from({ length: 30 }, (_, i) => `C${i}`);
    expect(problems(valid({ geo: codes }))).toEqual({});
    expect(parseIssueMessage(problems(valid({ geo: [...codes, 'X'] })).geo)).toEqual({
      code: 'too_many',
      params: { max: 30 },
    });
  });

  it('creativeType and priority are required', () => {
    const found = problems(valid({ creativeType: '', priority: '' }));
    expect(parseIssueMessage(found.creativeType).code).toBe('required');
    expect(parseIssueMessage(found.priority).code).toBe('required');
  });

  it('deadline: required, must be in the future, at most 10 years ahead', () => {
    expect(parseIssueMessage(problems(valid({ deadline: null })).deadline).code).toBe('required');
    expect(parseIssueMessage(problems(valid({ deadline: '' })).deadline).code).toBe('required');
    expect(
      parseIssueMessage(problems(valid({ deadline: '2020-01-01 12:00:00' })).deadline).code,
    ).toBe('must_be_future');
    expect(translateIssue(tEn, problems(valid({ deadline: '2020-01-01 12:00:00' })).deadline)).toBe(
      'Choose a date and time in the future.',
    );
    expect(translateIssue(tRu, problems(valid({ deadline: '2020-01-01 12:00:00' })).deadline)).toBe(
      'Выберите дату и время в будущем.',
    );
    expect(
      parseIssueMessage(problems(valid({ deadline: '2999-01-01 12:00:00' })).deadline).code,
    ).toBe('invalid');
    expect(parseIssueMessage(problems(valid({ deadline: 'garbage' })).deadline).code).toBe(
      'invalid',
    );
  });

  it('a deadline exactly now is not in the future, one second later is', () => {
    // 08:00:00Z is 10:00:00 in Berlin (summer time).
    const spy = jest.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-06-15T08:00:00Z'));
    expect(
      parseIssueMessage(problems(valid({ deadline: '2026-06-15 10:00:00' })).deadline).code,
    ).toBe('must_be_future');
    expect(problems(valid({ deadline: '2026-06-15 10:00:01' }))).toEqual({});
    spy.mockRestore();
  });

  describe('link rows', () => {
    it.each([
      'https://example.com',
      'http://example.com/path?q=1#x',
      ' https://example.com ',
      '',
      '   ',
    ])('accepts %j', (value) => {
      expect(problems(valid({ urls: [{ value }] }))).toEqual({});
    });

    it.each([
      'javascript:alert(1)',
      'ftp://x',
      'example.com',
      'data:text/html,<b>x</b>',
      'http://',
      'https://exa mple.com',
    ])('rejects %j with invalid_url', (value) => {
      const found = problems(valid({ urls: [{ value: 'https://ok.example' }, { value }] }));
      expect(parseIssueMessage(found['urls.1.value']).code).toBe('invalid_url');
      expect(found['urls.0.value']).toBeUndefined();
    });

    it('rejects a link over 2000 characters', () => {
      const long = `https://example.com/${'a'.repeat(2000)}`;
      const found = problems(valid({ urls: [{ value: long }] }));
      expect(parseIssueMessage(found['urls.0.value'])).toEqual({
        code: 'too_long',
        params: { max: 2000 },
      });
    });

    it('rejects more than 50 links', () => {
      const urls = Array.from({ length: 51 }, () => ({ value: 'https://example.com' }));
      expect(parseIssueMessage(problems(valid({ urls })).urls)).toEqual({
        code: 'too_many',
        params: { max: 50 },
      });
    });
  });
});

describe('defaultDeadline', () => {
  it('is tomorrow at 12:00 local time, across the daylight-saving change too', () => {
    // Berlin (TZ of the test run): 2026-03-28 10:00 UTC is 11:00 CET; the next day is CEST.
    expect(defaultDeadline(Date.parse('2026-03-28T10:00:00Z'))).toBe('2026-03-29 12:00:00');
    expect(defaultDeadline(Date.parse('2026-06-15T23:30:00Z'))).toBe('2026-06-17 12:00:00');
  });
});
