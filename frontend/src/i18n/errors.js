/** @typedef {import('i18next').TFunction} TFunction */
/** @typedef {import('../api/ApiRequestError').ApiRequestError} ApiRequestError */
/** @typedef {import('../api/ApiRequestError').FieldError} FieldError */

/** Every code the API (openapi.yaml, ErrorCode) and the API client can produce. */
export const KNOWN_API_ERROR_CODES = new Set([
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
  // Produced by the client:
  'payload_too_large',
  'network_error',
  'unknown',
]);

/** Every per-field code of the API (openapi.yaml, FieldError). */
export const FIELD_ERROR_CODES = new Set([
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
]);

/** The API names a request field it could not read as text "_invalid". */
export const UNREADABLE_FIELD = '_invalid';

const BYTES_PER_MB = 1_000_000;
const SECONDS_PER_MINUTE = 60;

/** @param {unknown} value @returns {value is number} */
function isNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

/**
 * "45 seconds" under a minute, otherwise whole minutes rounded up ("3 minutes" for 125 s).
 * @param {TFunction} t
 * @param {unknown} seconds
 * @returns {string | null} null when there is no usable number
 */
function describeWait(t, seconds) {
  if (!isNumber(seconds) || seconds <= 0) {
    return null;
  }
  if (seconds < SECONDS_PER_MINUTE) {
    return t('errors:wait_seconds', { count: Math.ceil(seconds) });
  }
  return t('errors:wait_minutes', { count: Math.ceil(seconds / SECONDS_PER_MINUTE) });
}

/**
 * The message for a failed API call, in the language of `t`. Never shows server text: the API
 * sends codes and this maps them.
 * @param {TFunction} t
 * @param {ApiRequestError} error
 * @returns {string}
 */
export function translateApiError(t, error) {
  const { code, params } = error;
  if (!KNOWN_API_ERROR_CODES.has(code)) {
    return t('errors:unknown');
  }
  if (code === 'rate_limited') {
    const wait = describeWait(t, params?.retryAfterSeconds);
    return wait === null ? t('errors:rate_limited_later') : t('errors:rate_limited', { wait });
  }
  if (code === 'file_too_large') {
    const maxBytes = params?.maxBytes;
    return isNumber(maxBytes) && maxBytes > 0
      ? t('errors:file_too_large', { maxMb: Math.max(1, Math.round(maxBytes / BYTES_PER_MB)) })
      : t('errors:file_too_large_unknown_limit');
  }
  return t(`errors:${code}`);
}

/**
 * The message for one field problem of a validation_failed answer. Pass `field` to have the
 * unreadable-field sentinel recognised.
 * @param {TFunction} t
 * @param {{field?: string, code: string, params?: Record<string, unknown>}} fieldError
 * @returns {string}
 */
export function translateFieldError(t, { field, code, params = {} }) {
  if (field === UNREADABLE_FIELD) {
    return t('errors:unreadable_field');
  }
  if (!FIELD_ERROR_CODES.has(code)) {
    return t('errors:unknown');
  }
  switch (code) {
    case 'too_short':
      return isNumber(params.min)
        ? t('errors:too_short', { count: params.min })
        : t('errors:invalid');
    case 'too_long':
      return isNumber(params.max)
        ? t('errors:too_long', { count: params.max })
        : t('errors:invalid');
    case 'too_many':
      return isNumber(params.max)
        ? t('errors:too_many', { count: params.max })
        : t('errors:invalid');
    case 'out_of_range':
      return isNumber(params.min) && isNumber(params.max)
        ? t('errors:out_of_range', { min: params.min, max: params.max })
        : t('errors:invalid');
    default:
      return t(`errors:${code}`);
  }
}

/**
 * Sorts the field problems of an error for a form: a message per field (the first one wins) and
 * the form-level messages for what belongs to no field the form has.
 * @param {TFunction} t
 * @param {ApiRequestError} error
 * @returns {{fields: Record<string, string>, form: string[]}}
 */
export function groupFieldErrors(t, error) {
  // No prototype, so a field called "constructor" or "__proto__" is only a name.
  /** @type {Record<string, string>} */
  const fields = Object.create(null);
  /** @type {string[]} */
  const form = [];
  for (const fieldError of error.fields) {
    const message = translateFieldError(t, fieldError);
    if (fieldError.field === UNREADABLE_FIELD) {
      if (!form.includes(message)) {
        form.push(message);
      }
    } else if (!Object.hasOwn(fields, fieldError.field)) {
      fields[fieldError.field] = message;
    }
  }
  return { fields, form };
}
