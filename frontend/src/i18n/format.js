/** @typedef {import('./detect').Language} Language */
/** @typedef {import('i18next').TFunction} TFunction */

/** What is shown for a value that is missing. */
const NONE = '—';

const REGION_CODES = new Set(['WW', 'EU', 'LATAM', 'MENA', 'CIS']);

/**
 * @param {string | null | undefined} iso an API timestamp, UTC with a Z
 * @returns {Date | null}
 */
function parseTimestamp(iso) {
  if (typeof iso !== 'string') {
    return null;
  }
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Local calendar date in the browser's timezone, for example "Mar 30, 2026" / "30 мар. 2026 г.".
 * @param {string | null | undefined} iso
 * @param {Language} lng
 */
export function formatDate(iso, lng) {
  const date = parseTimestamp(iso);
  if (date === null) {
    return NONE;
  }
  return new Intl.DateTimeFormat(lng, { year: 'numeric', month: 'short', day: 'numeric' }).format(
    date,
  );
}

/**
 * Local date and 24-hour time with seconds in the browser's timezone, so a daylight-saving jump
 * is visible (01:59:59 is followed by 03:00:00 in Berlin on the last Sunday of March).
 * @param {string | null | undefined} iso
 * @param {Language} lng
 */
export function formatDateTime(iso, lng) {
  const date = parseTimestamp(iso);
  if (date === null) {
    return NONE;
  }
  return new Intl.DateTimeFormat(lng, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).format(date);
}

/**
 * A deadline for a crowded card: local date and 24-hour time without seconds ("Nov 14, 12:00"),
 * with the year only when it is not the current one.
 * @param {string | null | undefined} iso
 * @param {Language} lng
 * @param {number} [nowMs] defaults to the current time
 */
export function formatDeadline(iso, lng, nowMs = Date.now()) {
  const date = parseTimestamp(iso);
  if (date === null) {
    return NONE;
  }
  const thisYear = new Date(nowMs).getFullYear() === date.getFullYear();
  return new Intl.DateTimeFormat(lng, {
    ...(thisYear ? {} : { year: 'numeric' }),
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(date);
}

/**
 * @param {number} value
 * @param {Language} lng
 */
export function formatNumber(value, lng) {
  return new Intl.NumberFormat(lng).format(value);
}

/**
 * Credits come from the API as decimal strings ("1240.50"). They are only displayed, with two
 * decimals and the separators of the language; Intl reads the string exactly, no float involved.
 * @param {string | null | undefined} value
 * @param {Language} lng
 */
export function formatCredits(value, lng) {
  if (typeof value !== 'string' || !/^-?\d+(\.\d+)?$/.test(value)) {
    return NONE;
  }
  return new Intl.NumberFormat(lng, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    /** @type {any} */ (value),
  );
}

const SECONDS_PER_MINUTE = 60;
const SECONDS_PER_HOUR = 3600;
const SECONDS_PER_DAY = 86400;

/**
 * Elapsed time as the two largest non-zero units among days, hours and minutes ("3 d 5 h",
 * "3 ч 10 мин"). Under a minute it says so; null (nothing measured yet) is a dash.
 * @param {number | null | undefined} seconds
 * @param {TFunction} t
 */
export function formatDuration(seconds, t) {
  if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) {
    return NONE;
  }
  if (seconds < SECONDS_PER_MINUTE) {
    return t('common:duration.lessThanMinute');
  }
  const days = Math.floor(seconds / SECONDS_PER_DAY);
  const hours = Math.floor((seconds % SECONDS_PER_DAY) / SECONDS_PER_HOUR);
  const minutes = Math.floor((seconds % SECONDS_PER_HOUR) / SECONDS_PER_MINUTE);
  return [
    { value: days, key: 'common:duration.days' },
    { value: hours, key: 'common:duration.hours' },
    { value: minutes, key: 'common:duration.minutes' },
  ]
    .filter(({ value }) => value > 0)
    .slice(0, 2)
    .map(({ value, key }) => t(key, { value }))
    .join(' ');
}

/**
 * The name of a GEO code: a country in the language of the viewer (Intl.DisplayNames), or one of
 * the translated region names. Something Intl cannot name stays as the code.
 * @param {string} code ISO 3166-1 alpha-2, or WW EU LATAM MENA CIS
 * @param {Language} lng
 * @param {TFunction} t
 */
export function formatCountry(code, lng, t) {
  if (REGION_CODES.has(code)) {
    return t(`common:regions.${code}`);
  }
  try {
    return new Intl.DisplayNames([lng], { type: 'region' }).of(code) ?? code;
  } catch {
    return code;
  }
}

/**
 * A date picked in the browser's local time as the API wants it: UTC, second precision, Z.
 * @param {Date} date
 */
export function localDateTimeToUtcIso(date) {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/**
 * True only when the deadline lies strictly before now: a deadline equal to now is not overdue.
 * @param {string} deadlineIso
 * @param {number} [nowMs] defaults to the current time
 */
export function isPastDeadline(deadlineIso, nowMs = Date.now()) {
  const deadline = parseTimestamp(deadlineIso);
  return deadline !== null && deadline.getTime() < nowMs;
}

/**
 * A file size in decimal units, the same ones the 99 MB limit uses: "850 B", "12.5 KB", "1.5 MB".
 * @param {number} bytes
 * @param {Language} lng
 * @param {TFunction} t
 */
export function formatFileSize(bytes, lng, t) {
  const oneDecimal = new Intl.NumberFormat(lng, { maximumFractionDigits: 1 });
  if (bytes < 1000) {
    return t('common:fileSize.bytes', { value: formatNumber(bytes, lng) });
  }
  if (bytes < 1_000_000) {
    return t('common:fileSize.kb', { value: oneDecimal.format(bytes / 1000) });
  }
  return t('common:fileSize.mb', { value: oneDecimal.format(bytes / 1_000_000) });
}
