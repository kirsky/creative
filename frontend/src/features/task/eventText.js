import { formatCountry, formatCredits, formatDateTime, formatNumber } from '../../i18n/format';

/** @typedef {import('../../i18n/detect').Language} Language */
/** @typedef {import('i18next').TFunction} TFunction */

/** The nine event types of the API (TaskEventType), in lifecycle order. */
export const EVENT_TYPES = [
  'task_created',
  'task_picked_up',
  'task_released',
  'round_submitted',
  'revisions_requested',
  'task_approved',
  'task_cancelled',
  'brief_edited',
  'ai_usage_changed',
];

/** The brief fields a `brief_edited` event can name, with a translated label each. */
const BRIEF_FIELDS = new Set([
  'offer',
  'geo',
  'creativeType',
  'quantity',
  'description',
  'priority',
  'deadline',
]);

/** A changed description in the activity list is cut to this many characters per side. */
const DESCRIPTION_LIMIT = 80;

/** What is shown for a value that is missing. */
const NONE = '—';

/** @param {unknown} value @returns {value is Record<string, unknown>} */
function isRecord(value) {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * The payload as an object; the API promises one, a broken one reads as empty.
 * @param {import('./api').TaskEvent} event
 * @returns {Record<string, unknown>}
 */
function payloadOf(event) {
  return isRecord(event.payload) ? event.payload : {};
}

/**
 * A payload value that is shown as text: a non-empty string or a number, otherwise undefined.
 * @param {unknown} value
 * @returns {string | undefined}
 */
function textOf(value) {
  if (typeof value === 'string' && value.trim() !== '') {
    return value;
  }
  return typeof value === 'number' && Number.isFinite(value) ? String(value) : undefined;
}

/**
 * Cuts text to `limit` characters (whole code points, so an emoji is never split) and adds "…".
 * @param {string} text
 * @param {number} limit
 */
function truncate(text, limit) {
  const characters = Array.from(text);
  return characters.length <= limit ? text : `${characters.slice(0, limit).join('')}…`;
}

/**
 * Both sides of a changed long text, each cut to at most `limit` characters (whole code points, so
 * an emoji is never split) around the first place they differ, with "…" where text is left out.
 * Both windows start at the same character and contain the first difference, so the two sides
 * never read the same. The window is centred on the difference, or ends with the longer text when
 * the difference is near its end. Texts that both fit are shown whole.
 * @param {string} oldText
 * @param {string} newText
 * @param {number} limit
 * @returns {[string, string]}
 */
export function diffWindows(oldText, newText, limit) {
  const before = Array.from(oldText);
  const after = Array.from(newText);
  if (before.length <= limit && after.length <= limit) {
    return [oldText, newText];
  }
  let first = 0;
  while (first < before.length && first < after.length && before[first] === after[first]) {
    first += 1;
  }
  const longest = Math.max(before.length, after.length);
  const start = Math.max(0, Math.min(first - Math.floor(limit / 2), longest - limit));
  const cut = (/** @type {string[]} */ characters) =>
    `${start > 0 ? '…' : ''}${characters.slice(start, start + limit).join('')}${
      start + limit < characters.length ? '…' : ''
    }`;
  return [cut(before), cut(after)];
}

/**
 * The language of a translator made by `getFixedT` (react-i18next's `t` is one).
 * @param {TFunction} t
 * @returns {Language}
 */
function languageOf(t) {
  const lng = /** @type {{lng?: unknown}} */ (t).lng;
  return lng === 'ru' ? 'ru' : 'en';
}

/**
 * A changed value of the brief as people read it: country names, a local date and time, translated
 * type and priority, a cut description.
 * @param {TFunction} t
 * @param {Language} lng
 * @param {string} field
 * @param {unknown} value
 * @returns {string}
 */
function formatFieldValue(t, lng, field, value) {
  if (value === null || value === undefined || value === '' || value === false) {
    return NONE;
  }
  switch (field) {
    case 'geo':
      return Array.isArray(value) && value.length > 0
        ? value
            .filter((code) => typeof code === 'string')
            .map((code) => formatCountry(code, lng, t))
            .join(', ')
        : NONE;
    case 'deadline':
      return typeof value === 'string' ? formatDateTime(value, lng) : NONE;
    case 'creativeType':
    case 'priority': {
      const code = textOf(value);
      return code === undefined
        ? NONE
        : t(`task:${field === 'priority' ? 'priorities' : 'creativeTypes'}.${code}`, {
            defaultValue: code,
          });
    }
    case 'quantity':
      return typeof value === 'number' ? formatNumber(value, lng) : (textOf(value) ?? NONE);
    default:
      return textOf(value) ?? NONE;
  }
}

/**
 * "Runway 120.00, Midjourney 40.00" for the lines of an ai_usage_changed payload.
 * @param {TFunction} t
 * @param {Language} lng
 * @param {unknown} lines
 * @returns {string}
 */
function formatAiLines(t, lng, lines) {
  if (!Array.isArray(lines)) {
    return '';
  }
  return lines
    .filter(isRecord)
    .map((line) =>
      t('task:events.aiLine', {
        provider: textOf(line.provider) ?? NONE,
        credits: formatCredits(typeof line.credits === 'string' ? line.credits : undefined, lng),
      }),
    )
    .join(', ');
}

/**
 * One event of the activity history as a translated sentence that starts with (or contains) the
 * actor's name. User text (names, reasons, providers) goes in as it is: this returns a string
 * for React to render as text, never markup. An unknown type reads as a generic line.
 * @param {TFunction} t
 * @param {import('./api').TaskEvent} event
 * @param {Language} [lng] the language of the dates and country names; defaults to that of `t`
 * @returns {string}
 */
export function renderEvent(t, event, lng = languageOf(t)) {
  const payload = payloadOf(event);
  const actor = event.actor?.displayName ?? NONE;
  const round = textOf(payload.round) ?? NONE;
  const reason = textOf(payload.reason);
  const note = textOf(payload.note);

  switch (event.type) {
    case 'task_created':
    case 'task_picked_up':
    case 'task_released':
    case 'brief_edited':
      return t(`task:events.${event.type}`, { actor });
    case 'round_submitted': {
      const count = typeof payload.fileCount === 'number' ? payload.fileCount : 0;
      return t('task:events.round_submitted', { actor, round, count });
    }
    case 'revisions_requested':
      return reason === undefined
        ? t('task:events.revisions_requested', { actor, round })
        : t('task:events.revisions_requested_reason', { actor, round, reason });
    case 'task_approved':
      return note === undefined
        ? t('task:events.task_approved', { actor, round })
        : t('task:events.task_approved_note', { actor, round, note });
    case 'task_cancelled':
      return reason === undefined
        ? t('task:events.task_cancelled', { actor })
        : t('task:events.task_cancelled_reason', { actor, reason });
    case 'ai_usage_changed': {
      const lines = formatAiLines(t, lng, payload.lines);
      return lines === ''
        ? t('task:events.ai_usage_cleared', { actor, round })
        : t('task:events.ai_usage_changed', { actor, round, lines });
    }
    default:
      return t('task:events.unknown', { actor });
  }
}

/**
 * The extra lines under an event: for brief_edited one "field: old → new" line per change, for
 * every other type none.
 * @param {TFunction} t
 * @param {import('./api').TaskEvent} event
 * @param {Language} [lng] defaults to the language of `t`
 * @returns {string[]}
 */
export function renderEventDetails(t, event, lng = languageOf(t)) {
  if (event.type !== 'brief_edited') {
    return [];
  }
  const { changes } = payloadOf(event);
  if (!Array.isArray(changes)) {
    return [];
  }
  return changes.filter(isRecord).flatMap((change) => {
    const { field } = change;
    if (typeof field !== 'string') {
      return [];
    }
    let before = formatFieldValue(t, lng, field, change.old);
    let after = formatFieldValue(t, lng, field, change.new);
    // A long text is shown around what changed; short fields are shown whole.
    if (field === 'description' && before !== NONE && after !== NONE) {
      [before, after] = diffWindows(before, after, DESCRIPTION_LIMIT);
    } else if (field === 'description') {
      [before, after] = [before, after].map((text) => truncate(text, DESCRIPTION_LIMIT));
    }
    return [
      t('task:events.change', {
        field: BRIEF_FIELDS.has(field) ? t(`task:fields.${field}`) : t('task:fields.unknown'),
        old: before,
        new: after,
      }),
    ];
  });
}
