import dayjs from 'dayjs';
import { z } from 'zod';
import { translateFieldError } from '../../i18n/errors';

/** The limits of POST /api/tasks (openapi.yaml, NewTask); the schema mirrors the server rules. */
export const LIMITS = {
  offer: { min: 2, max: 100 },
  quantity: { min: 1, max: 100 },
  geo: { max: 30 },
  description: { max: 10000 },
  url: { max: 2000 },
  references: { max: 50 },
  deadlineYears: 10,
};

/**
 * A problem of the form as a string, which is all React Hook Form carries from a rule to the
 * place the message is shown: the API field-error code, followed by its params as JSON when it
 * has any ("required", "too_short:{"min":2}"). Server errors are put through the same format, so
 * a message from either side is translated by translateIssue when it is rendered, and a language
 * switch re-translates a message that is already on screen.
 * @param {string} code
 * @param {Record<string, unknown>} [params]
 */
export function issueMessage(code, params) {
  return params === undefined || Object.keys(params).length === 0
    ? code
    : `${code}:${JSON.stringify(params)}`;
}

/**
 * @param {string} message
 * @returns {{code: string, params: Record<string, unknown>}}
 */
export function parseIssueMessage(message) {
  const separator = message.indexOf(':{');
  if (separator > 0) {
    try {
      const params = JSON.parse(message.slice(separator + 1));
      if (typeof params === 'object' && params !== null && !Array.isArray(params)) {
        return { code: message.slice(0, separator), params };
      }
    } catch {
      // Not params after all: the whole text is the code.
    }
  }
  return { code: message, params: {} };
}

/**
 * @param {import('i18next').TFunction} t
 * @param {string} message an issue message (issueMessage)
 * @returns {string}
 */
export function translateIssue(t, message) {
  const { code, params } = parseIssueMessage(message);
  return translateFieldError(t, { code, params });
}

/** Characters as a person counts them (an emoji is one), like the server does. */
const length = (/** @type {string} */ text) => Array.from(text).length;

const LOCAL_DATE_TIME = 'YYYY-MM-DD HH:mm:ss';

/**
 * The deadline the form starts with: tomorrow at 12:00 in the browser's time zone, as the
 * picker writes values ("2026-06-16 12:00:00", local time).
 * @param {number} nowMs
 */
export function defaultDeadline(nowMs) {
  return dayjs(nowMs).add(1, 'day').hour(12).minute(0).second(0).format(LOCAL_DATE_TIME);
}

/**
 * @param {unknown} value the picker's local "YYYY-MM-DD HH:mm:ss"
 * @returns {Date | null} null when it is not a date
 */
export function parseLocalDateTime(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  const parsed = dayjs(value);
  return parsed.isValid() ? parsed.toDate() : null;
}

/** @param {string} text */
export function isHttpUrl(text) {
  try {
    const url = new URL(text);
    return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '';
  } catch {
    return false;
  }
}

/**
 * The quantity as a number: the number input hands over a number, or text while it is being typed.
 * @param {unknown} value
 * @returns {number | null} null when empty
 */
function quantityNumber(value) {
  if (typeof value === 'number') {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    return Number(value);
  }
  return null;
}

/** @param {unknown} value @returns {number} */
export function quantityValue(value) {
  return quantityNumber(value) ?? Number.NaN;
}

/** @param {string} message @returns {{code: 'custom', message: string}} */
const issue = (message) => ({ code: 'custom', message });

const required = z.string({ error: 'required' }).min(1, 'required');

export const taskSchema = z.object({
  offer: z.string({ error: 'required' }).superRefine((value, ctx) => {
    const size = length(value.trim());
    if (size === 0) {
      ctx.addIssue(issue('required'));
    } else if (size < LIMITS.offer.min) {
      ctx.addIssue(issue(issueMessage('too_short', { min: LIMITS.offer.min })));
    } else if (size > LIMITS.offer.max) {
      ctx.addIssue(issue(issueMessage('too_long', { max: LIMITS.offer.max })));
    }
  }),
  creativeType: required,
  quantity: z.unknown().superRefine((value, ctx) => {
    const quantity = quantityNumber(value);
    if (quantity === null) {
      ctx.addIssue(issue('required'));
    } else if (!Number.isFinite(quantity) || !Number.isInteger(quantity)) {
      ctx.addIssue(issue('invalid'));
    } else if (quantity < LIMITS.quantity.min || quantity > LIMITS.quantity.max) {
      ctx.addIssue(issue(issueMessage('out_of_range', LIMITS.quantity)));
    }
  }),
  priority: required,
  geo: z
    .array(z.string(), { error: 'required' })
    .min(1, 'required')
    .max(LIMITS.geo.max, issueMessage('too_many', LIMITS.geo)),
  // Stored exactly as sent, so it is measured and sent untrimmed; only "nothing but spaces" is empty.
  description: z.string({ error: 'required' }).superRefine((value, ctx) => {
    if (value.trim() === '') {
      ctx.addIssue(issue('required'));
    } else if (length(value) > LIMITS.description.max) {
      ctx.addIssue(issue(issueMessage('too_long', LIMITS.description)));
    }
  }),
  deadline: z
    .string()
    .nullable()
    .superRefine((value, ctx) => {
      if (value === null || value.trim() === '') {
        ctx.addIssue(issue('required'));
        return;
      }
      const date = parseLocalDateTime(value);
      const now = Date.now();
      if (date === null) {
        ctx.addIssue(issue('invalid'));
      } else if (date.getTime() <= now) {
        ctx.addIssue(issue('must_be_future'));
      } else if (date.getTime() > dayjs(now).add(LIMITS.deadlineYears, 'year').valueOf()) {
        ctx.addIssue(issue('invalid'));
      }
    }),
  // Blank rows are allowed: they are left out when the brief is sent.
  urls: z
    .array(
      z.object({
        value: z.string().superRefine((value, ctx) => {
          const text = value.trim();
          if (text === '') {
            return;
          }
          if (length(text) > LIMITS.url.max) {
            ctx.addIssue(issue(issueMessage('too_long', LIMITS.url)));
          } else if (!isHttpUrl(text)) {
            ctx.addIssue(issue('invalid_url'));
          }
        }),
      }),
    )
    .max(LIMITS.references.max, issueMessage('too_many', LIMITS.references)),
});

/**
 * What the form holds. quantity is a number, or the text of a number being typed.
 * @typedef {object} TaskFormValues
 * @property {string} offer
 * @property {string} creativeType
 * @property {number | string} quantity
 * @property {string} priority
 * @property {string[]} geo
 * @property {string} description
 * @property {string | null} deadline local "YYYY-MM-DD HH:mm:ss"
 * @property {{value: string}[]} urls
 */
