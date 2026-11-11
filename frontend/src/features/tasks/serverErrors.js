import { UNREADABLE_FIELD } from '../../i18n/errors';
import { issueMessage } from './schema';

/**
 * Where a reference of the request came from, by its index in `references`.
 * @typedef {{kind: 'url', row: number} | {kind: 'file', key: number}} ReferenceSource
 */

const PLAIN_FIELDS = new Set([
  'offer',
  'creativeType',
  'quantity',
  'priority',
  'description',
  'deadline',
]);
/** The server says "too long" for a list with too many items. */
const LISTS = new Set(['geo', 'references']);

/**
 * The form field a server field name belongs to, or null when the form has no such field.
 * @param {string} field
 * @param {ReferenceSource[]} sources
 * @returns {string | null}
 */
function formField(field, sources) {
  if (PLAIN_FIELDS.has(field)) {
    return field;
  }
  if (field === 'geo' || /^geo\.\d+$/.test(field)) {
    return 'geo';
  }
  // Problems with attaching files are named `files` or `references`: they are shown together.
  if (field === 'files' || field === 'references') {
    return 'references';
  }
  const reference = /^references\.(\d+)(?:\.\w+)?$/.exec(field);
  if (reference !== null) {
    const source = sources[Number(reference[1])];
    if (source === undefined) {
      return null;
    }
    return source.kind === 'url' ? `urls.${source.row}.value` : 'references';
  }
  return null;
}

/**
 * Sorts the field problems of a 422 answer of POST /api/tasks into what belongs on a form field
 * (an issue message per field name, the first problem of a field wins) and what belongs to no
 * field of the form, which the form shows as an alert.
 * @param {import('../../api/ApiRequestError').ApiRequestError} error
 * @param {ReferenceSource[]} sources the references of the request, in the order they were sent
 * @returns {{fields: {name: string, message: string}[], form: import('../../api/ApiRequestError').FieldError[]}}
 */
export function mapCreateTaskError(error, sources) {
  /** @type {{name: string, message: string}[]} */
  const fields = [];
  /** @type {import('../../api/ApiRequestError').FieldError[]} */
  const form = [];
  for (const item of error.fields) {
    const name =
      item.field === '' || item.field === UNREADABLE_FIELD ? null : formField(item.field, sources);
    if (name === null) {
      form.push(item);
    } else if (!fields.some((existing) => existing.name === name)) {
      const code = item.code === 'too_long' && LISTS.has(item.field) ? 'too_many' : item.code;
      fields.push({ name, message: issueMessage(code, item.params) });
    }
  }
  return { fields, form };
}
