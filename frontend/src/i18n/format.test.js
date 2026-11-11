import i18n from './index';
import {
  formatCountry,
  formatCredits,
  formatDate,
  formatDateTime,
  formatDuration,
  formatFileSize,
  formatNumber,
  isPastDeadline,
  localDateTimeToUtcIso,
} from './format';

const tEn = i18n.getFixedT('en');
const tRu = i18n.getFixedT('ru');

/** @param {string} text */
const normaliseSpaces = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

describe('timezone of the test run', () => {
  it('is Europe/Berlin (set in the Jest global setup)', () => {
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('Europe/Berlin');
  });
});

describe('formatNumber', () => {
  it('uses a no-break space as the Russian thousands separator', () => {
    const text = formatNumber(1240, 'ru');
    expect(text).toMatch(/^1[\u00a0\u202f]240$/);
    expect(text).not.toMatch(/[ ,]/);
    expect(normaliseSpaces(text)).toBe('1 240');
  });

  it('uses a comma as the English thousands separator', () => {
    expect(formatNumber(1240, 'en')).toBe('1,240');
  });

  it('uses locale decimal separators', () => {
    expect(formatNumber(1240.5, 'ru')).toBe(`1${formatNumber(1000, 'ru')[1]}240,5`);
    expect(formatNumber(1240.5, 'en')).toBe('1,240.5');
  });
});

describe('formatCredits', () => {
  it('shows two decimals from the decimal string with locale separators', () => {
    expect(formatCredits('1240.5', 'en')).toBe('1,240.50');
    expect(normaliseSpaces(formatCredits('1240.5', 'ru'))).toBe('1 240,50');
    expect(formatCredits('0', 'en')).toBe('0.00');
    expect(formatCredits('12.00', 'ru')).toBe('12,00');
  });

  it('keeps every digit of a long decimal string (no float rounding)', () => {
    expect(formatCredits('9999999999.99', 'en')).toBe('9,999,999,999.99');
    expect(formatCredits('0.10', 'en')).toBe('0.10');
  });

  it('shows a dash for missing or malformed values, never NaN', () => {
    expect(formatCredits(null, 'en')).toBe('—');
    expect(formatCredits('abc', 'en')).toBe('—');
    expect(formatCredits('', 'ru')).toBe('—');
  });
});

describe('formatDuration', () => {
  it('shows the largest two non-zero units among days, hours, minutes', () => {
    expect(formatDuration(3 * 86400 + 5 * 3600, tEn)).toBe('3 d 5 h');
    expect(formatDuration(3 * 86400 + 5 * 3600, tRu)).toBe('3 д 5 ч');
    expect(formatDuration(11400, tEn)).toBe('3 h 10 m');
    expect(formatDuration(11400, tRu)).toBe('3 ч 10 мин');
  });

  it('shows a single unit when the others are zero', () => {
    expect(formatDuration(2 * 86400, tEn)).toBe('2 d');
    expect(formatDuration(7200, tRu)).toBe('2 ч');
    expect(formatDuration(61, tEn)).toBe('1 m');
  });

  it('skips zero units in between', () => {
    expect(formatDuration(86400 + 600, tEn)).toBe('1 d 10 m');
    expect(formatDuration(86400 + 3600 + 600, tEn)).toBe('1 d 1 h');
  });

  it('shows "less than a minute" under 60 seconds, including zero', () => {
    expect(formatDuration(59, tEn)).toBe('< 1 m');
    expect(formatDuration(0, tEn)).toBe('< 1 m');
    expect(formatDuration(30, tRu)).toBe('< 1 мин');
  });

  it('shows a dash for null and for non-finite input', () => {
    expect(formatDuration(null, tEn)).toBe('—');
    expect(formatDuration(null, tRu)).toBe('—');
    expect(formatDuration(Number.NaN, tEn)).toBe('—');
  });
});

describe('formatDateTime across the Berlin spring-forward', () => {
  it('shows 01:59:59 one second before and 03:00:00 at the change (English)', () => {
    expect(formatDateTime('2026-03-29T00:59:59Z', 'en')).toContain('01:59:59');
    expect(formatDateTime('2026-03-29T01:00:00Z', 'en')).toContain('03:00:00');
    expect(formatDateTime('2026-03-29T01:00:00Z', 'en')).not.toContain('02:00:00');
  });

  it('shows the same instants in Russian', () => {
    expect(formatDateTime('2026-03-29T00:59:59Z', 'ru')).toContain('01:59:59');
    expect(formatDateTime('2026-03-29T01:00:00Z', 'ru')).toContain('03:00:00');
  });

  it('uses the offset of each side of the change', () => {
    expect(formatDateTime('2026-03-28T12:00:00Z', 'en')).toContain('13:00:00');
    expect(formatDateTime('2026-03-30T12:00:00Z', 'en')).toContain('14:00:00');
    expect(formatDateTime('2026-10-25T00:59:59Z', 'en')).toContain('02:59:59');
    expect(formatDateTime('2026-10-25T01:00:00Z', 'en')).toContain('02:00:00');
  });

  it('reads API timestamps with milliseconds', () => {
    expect(formatDateTime('2026-03-29T01:00:00.000Z', 'en')).toContain('03:00:00');
  });

  it('writes the month in the language', () => {
    expect(formatDateTime('2026-03-29T12:00:00Z', 'en')).toContain('Mar');
    expect(formatDateTime('2026-03-29T12:00:00Z', 'ru')).toMatch(/мар/);
  });

  it('shows a dash for an unreadable value', () => {
    expect(formatDateTime('not a date', 'en')).toBe('—');
    expect(formatDateTime(null, 'en')).toBe('—');
  });
});

describe('formatDate', () => {
  it('shows the local calendar day, which can differ from the UTC day', () => {
    // 23:30 UTC on 29 March is 01:30 on 30 March in Berlin.
    expect(formatDate('2026-03-29T23:30:00Z', 'en')).toBe('Mar 30, 2026');
    expect(formatDate('2026-03-29T23:30:00Z', 'ru')).toMatch(/^30 мар\.? 2026/);
  });

  it('has no time part', () => {
    expect(formatDate('2026-03-29T12:00:00Z', 'en')).not.toMatch(/:/);
  });
});

describe('formatCountry', () => {
  it('uses Intl.DisplayNames for countries', () => {
    expect(formatCountry('DE', 'ru', tRu)).toBe('Германия');
    expect(formatCountry('DE', 'en', tEn)).toBe('Germany');
  });

  it('uses the translated region names for the region codes', () => {
    expect(formatCountry('WW', 'ru', tRu)).toBe('Весь мир');
    expect(formatCountry('WW', 'en', tEn)).toBe('Worldwide');
    for (const code of ['EU', 'LATAM', 'MENA', 'CIS']) {
      expect(formatCountry(code, 'en', tEn)).not.toContain('regions.');
      expect(formatCountry(code, 'ru', tRu)).not.toContain('regions.');
    }
    expect(formatCountry('CIS', 'ru', tRu)).toBe('СНГ');
  });

  it('falls back to the code for something it cannot name', () => {
    expect(formatCountry('??', 'en', tEn)).toBe('??');
  });
});

describe('localDateTimeToUtcIso', () => {
  it('ends with Z, has second precision and round-trips', () => {
    const local = new Date(2026, 5, 15, 14, 30, 45, 678);
    const iso = localDateTimeToUtcIso(local);
    expect(iso).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    expect(new Date(iso).getTime()).toBe(Math.floor(local.getTime() / 1000) * 1000);
  });

  it('converts Berlin wall-clock time to UTC on both sides of the DST change', () => {
    expect(localDateTimeToUtcIso(new Date(2026, 2, 29, 1, 59, 59))).toBe('2026-03-29T00:59:59Z');
    expect(localDateTimeToUtcIso(new Date(2026, 2, 29, 3, 0, 0))).toBe('2026-03-29T01:00:00Z');
    expect(localDateTimeToUtcIso(new Date(2026, 0, 15, 12, 0, 0))).toBe('2026-01-15T11:00:00Z');
  });
});

describe('isPastDeadline', () => {
  const now = Date.parse('2026-06-01T12:00:00Z');

  it('a deadline exactly equal to now is not past', () => {
    expect(isPastDeadline('2026-06-01T12:00:00Z', now)).toBe(false);
    expect(isPastDeadline('2026-06-01T12:00:00.000Z', now)).toBe(false);
  });

  it('is past one millisecond later', () => {
    expect(isPastDeadline('2026-06-01T11:59:59.999Z', now)).toBe(true);
    expect(isPastDeadline('2026-06-01T12:00:00.001Z', now)).toBe(false);
  });

  it('is false for an unreadable deadline', () => {
    expect(isPastDeadline('garbage', now)).toBe(false);
  });
});

describe('formatFileSize', () => {
  it('uses decimal units with one decimal at most', () => {
    expect(formatFileSize(850, 'en', tEn)).toBe('850 B');
    expect(formatFileSize(12_500, 'en', tEn)).toBe('12.5 KB');
    expect(formatFileSize(1_500_000, 'en', tEn)).toBe('1.5 MB');
    expect(formatFileSize(99_000_000, 'en', tEn)).toBe('99 MB');
  });

  it('follows the language', () => {
    expect(normaliseSpaces(formatFileSize(1_500_000, 'ru', tRu))).toBe('1,5 МБ');
    expect(normaliseSpaces(formatFileSize(850, 'ru', tRu))).toBe('850 Б');
  });
});
