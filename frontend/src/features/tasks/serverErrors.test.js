import { ApiRequestError } from '../../api/ApiRequestError';
import { mapCreateTaskError } from './serverErrors';

/** @param {Array<[string, string, Record<string, unknown>?]>} fields */
function validationError(fields) {
  return new ApiRequestError(
    422,
    'validation_failed',
    fields.map(([field, code, params = {}]) => ({ field, code, params })),
  );
}

// Payload with a link, a blank-skipped link row (form row 1 is not sent) and two files.
/** @type {import('./serverErrors').ReferenceSource[]} */
const payloadMap = [
  { kind: 'url', row: 0 },
  { kind: 'url', row: 2 },
  { kind: 'file', key: 5 },
  { kind: 'file', key: 6 },
];

describe('mapCreateTaskError', () => {
  it('maps plain fields onto the form fields with their params', () => {
    const { fields, form } = mapCreateTaskError(
      validationError([
        ['offer', 'too_short', { min: 2 }],
        ['quantity', 'out_of_range', { min: 1, max: 100 }],
        ['deadline', 'must_be_future'],
      ]),
      payloadMap,
    );
    expect(form).toEqual([]);
    expect(fields).toEqual([
      { name: 'offer', message: 'too_short:{"min":2}' },
      { name: 'quantity', message: 'out_of_range:{"min":1,"max":100}' },
      { name: 'deadline', message: 'must_be_future' },
    ]);
  });

  it('maps references.<i>.url onto the row the link came from, not the payload index', () => {
    const { fields } = mapCreateTaskError(
      validationError([['references.1.url', 'invalid_url']]),
      payloadMap,
    );
    expect(fields).toEqual([{ name: 'urls.2.value', message: 'invalid_url' }]);
  });

  it('maps a problem of a file reference, `files` and `references` onto the references block', () => {
    const { fields } = mapCreateTaskError(
      validationError([
        ['references.2.fileId', 'invalid'],
        ['files', 'invalid_choice'],
      ]),
      payloadMap,
    );
    // Both land on the same block; the first problem is shown.
    expect(fields).toEqual([{ name: 'references', message: 'invalid' }]);
  });

  it('says "too many" instead of "too long" for lists', () => {
    const { fields } = mapCreateTaskError(
      validationError([
        ['geo', 'too_long', { max: 30 }],
        ['references', 'too_long', { max: 50 }],
      ]),
      payloadMap,
    );
    expect(fields).toEqual([
      { name: 'geo', message: 'too_many:{"max":30}' },
      { name: 'references', message: 'too_many:{"max":50}' },
    ]);
  });

  it('maps geo.<i> onto geo', () => {
    const { fields } = mapCreateTaskError(
      validationError([['geo.3', 'invalid_choice']]),
      payloadMap,
    );
    expect(fields).toEqual([{ name: 'geo', message: 'invalid_choice' }]);
  });

  it('sends form-level, unreadable and unknown fields to the form alert', () => {
    const { fields, form } = mapCreateTaskError(
      validationError([
        ['', 'invalid'],
        ['_invalid', 'invalid'],
        ['remember', 'unknown_field'],
        ['references.9.url', 'invalid_url'],
      ]),
      payloadMap,
    );
    expect(fields).toEqual([]);
    expect(form).toEqual([
      { field: '', code: 'invalid', params: {} },
      { field: '_invalid', code: 'invalid', params: {} },
      { field: 'remember', code: 'unknown_field', params: {} },
      { field: 'references.9.url', code: 'invalid_url', params: {} },
    ]);
  });

  it('keeps only the first problem of a field', () => {
    const { fields } = mapCreateTaskError(
      validationError([
        ['offer', 'required'],
        ['offer', 'too_short', { min: 2 }],
      ]),
      payloadMap,
    );
    expect(fields).toEqual([{ name: 'offer', message: 'required' }]);
  });
});
