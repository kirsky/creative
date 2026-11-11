import { UNREADABLE_FIELD } from '../../../i18n/errors';
import { issueMessage } from '../../tasks/schema';

/** @typedef {import('../../../api/ApiRequestError').ApiRequestError} ApiRequestError */
/** @typedef {import('../../../api/ApiRequestError').FieldError} FieldError */

/**
 * The field problems of a 422 answer, sorted like mapCreateTaskError does for the brief: an issue
 * message per form field (the first problem of a field wins), and what belongs to no field of the
 * form, which the form shows as an alert.
 * @param {ApiRequestError} error
 * @param {(field: string) => string | null} resolve the form field of a server field, or null
 * @param {(item: FieldError) => string} [codeOf] the code to show for a problem (default: its own)
 * @returns {{fields: {name: string, message: string}[], form: FieldError[]}}
 */
function sortFieldErrors(error, resolve, codeOf = (item) => item.code) {
  /** @type {{name: string, message: string}[]} */
  const fields = [];
  /** @type {FieldError[]} */
  const form = [];
  for (const item of error.fields) {
    const name = item.field === '' || item.field === UNREADABLE_FIELD ? null : resolve(item.field);
    if (name === null) {
      form.push(item);
    } else if (!fields.some((existing) => existing.name === name)) {
      fields.push({ name, message: issueMessage(codeOf(item), item.params) });
    }
  }
  return { fields, form };
}

/**
 * The AI usage lines of the server (`prefix` is `aiUsage` when submitting, `lines` when replacing)
 * onto the rows of the editor, whose field array is called `aiUsage`.
 * @param {string} field
 * @param {string} prefix
 * @returns {string | null}
 */
function aiUsageField(field, prefix) {
  if (field === prefix || new RegExp(`^${prefix}\\.\\d+$`).test(field)) {
    return 'aiUsage.root';
  }
  const line = new RegExp(`^${prefix}\\.(\\d+)\\.(provider|credits)$`).exec(field);
  return line === null ? null : `aiUsage.${line[1]}.${line[2]}`;
}

/**
 * A 422 of POST /api/tasks/{id}/rounds: `note`, `fileIds` / `files` (shown under the files as
 * `files`) and the lines of `aiUsage`.
 * @param {ApiRequestError} error
 */
export function mapSubmitError(error) {
  return sortFieldErrors(error, (field) => {
    if (field === 'note') {
      return 'note';
    }
    if (field === 'files' || field === 'fileIds' || /^fileIds\.\d+$/.test(field)) {
      return 'files';
    }
    return aiUsageField(field, 'aiUsage');
  });
}

/**
 * A 422 of PUT /api/tasks/{id}/rounds/{n}/ai-usage.
 * @param {ApiRequestError} error
 */
export function mapAiUsageError(error) {
  return sortFieldErrors(error, (field) => aiUsageField(field, 'lines'));
}

/**
 * A 422 of a request with one text field (`note` of approve, `reason` of the others).
 * @param {ApiRequestError} error
 * @param {string} name
 */
export function mapTextError(error, name) {
  return sortFieldErrors(error, (field) => (field === name ? name : null));
}

/** The fields of PATCH /api/tasks/{id} that the edit-brief form has an input for. */
const BRIEF_FIELDS = new Set([
  'offer',
  'geo',
  'creativeType',
  'quantity',
  'description',
  'priority',
  'deadline',
]);

/**
 * A 422 of PATCH /api/tasks/{id}: each brief field on its input (`geo.<i>` on the GEO), anything
 * else in the alert. The server says "too long" for a GEO list with too many items.
 * @param {ApiRequestError} error
 */
export function mapEditBriefError(error) {
  return sortFieldErrors(
    error,
    (field) => {
      if (BRIEF_FIELDS.has(field)) {
        return field;
      }
      return /^geo\.\d+$/.test(field) ? 'geo' : null;
    },
    (item) => (item.field === 'geo' && item.code === 'too_long' ? 'too_many' : item.code),
  );
}
