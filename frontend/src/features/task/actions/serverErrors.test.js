import { ApiRequestError } from '../../../api/ApiRequestError';
import { mapAiUsageError, mapSubmitError, mapTextError } from './serverErrors';

/** @param {{field: string, code: string, params?: Record<string, unknown>}[]} fields */
const invalid = (fields) =>
  new ApiRequestError(
    422,
    'validation_failed',
    fields.map((item) => ({ params: {}, ...item })),
  );

describe('mapSubmitError', () => {
  it('puts the lines, the note and the files where they belong', () => {
    const { fields, form } = mapSubmitError(
      invalid([
        { field: 'aiUsage.0.credits', code: 'invalid_amount' },
        { field: 'aiUsage.2.provider', code: 'too_long', params: { max: 50 } },
        { field: 'note', code: 'too_long', params: { max: 2000 } },
        { field: 'fileIds', code: 'required' },
        { field: 'fileIds.3', code: 'invalid' },
        { field: 'files', code: 'invalid_choice' },
        { field: 'aiUsage', code: 'too_many', params: { max: 20 } },
        { field: 'aiUsage.1', code: 'invalid' },
      ]),
    );
    expect(fields).toEqual([
      { name: 'aiUsage.0.credits', message: 'invalid_amount' },
      { name: 'aiUsage.2.provider', message: 'too_long:{"max":50}' },
      { name: 'note', message: 'too_long:{"max":2000}' },
      { name: 'files', message: 'required' },
      { name: 'aiUsage.root', message: 'too_many:{"max":20}' },
    ]);
    expect(form).toEqual([]);
  });

  it('leaves what the form has no field for to the alert', () => {
    const { fields, form } = mapSubmitError(
      invalid([
        { field: 'aiUsage.0.colour', code: 'unknown_field' },
        { field: 'surprise', code: 'unknown_field' },
        { field: '_invalid', code: 'invalid' },
        { field: '', code: 'invalid' },
      ]),
    );
    expect(fields).toEqual([]);
    expect(form.map((item) => item.field)).toEqual([
      'aiUsage.0.colour',
      'surprise',
      '_invalid',
      '',
    ]);
  });
});

describe('mapAiUsageError', () => {
  it('maps lines.N onto the rows of the editor', () => {
    const { fields, form } = mapAiUsageError(
      invalid([
        { field: 'lines.1.provider', code: 'required' },
        { field: 'lines', code: 'too_many', params: { max: 20 } },
        { field: 'lines.0.nope', code: 'unknown_field' },
      ]),
    );
    expect(fields).toEqual([
      { name: 'aiUsage.1.provider', message: 'required' },
      { name: 'aiUsage.root', message: 'too_many:{"max":20}' },
    ]);
    expect(form).toHaveLength(1);
  });
});

describe('mapTextError', () => {
  it('keeps the first problem of the one field', () => {
    const { fields, form } = mapTextError(
      invalid([
        { field: 'reason', code: 'required' },
        { field: 'reason', code: 'too_long', params: { max: 2000 } },
        { field: 'note', code: 'invalid' },
      ]),
      'reason',
    );
    expect(fields).toEqual([{ name: 'reason', message: 'required' }]);
    expect(form).toHaveLength(1);
  });
});
