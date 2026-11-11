import { ApiRequestError } from '../../../api/ApiRequestError';
import { makeTaskDetail } from '../../../test/taskDetail';
import { briefChanges, briefFormValues, editBriefSchema } from './editBrief';
import { mapEditBriefError } from './serverErrors';

afterEach(() => {
  jest.restoreAllMocks();
});

const TASK = makeTaskDetail({
  offer: 'Casino Royal',
  geo: ['FR', 'DE'],
  creativeType: 'video',
  quantity: 3,
  priority: 'normal',
  description: 'Short hook.',
  // 13:00 in Berlin (the test time zone), summer time.
  deadlineAt: '2026-06-20T11:00:00Z',
});

describe('briefFormValues', () => {
  it('fills the form from the task, the deadline in local time', () => {
    expect(briefFormValues(TASK)).toEqual({
      offer: 'Casino Royal',
      geo: ['FR', 'DE'],
      creativeType: 'video',
      quantity: 3,
      priority: 'normal',
      description: 'Short hook.',
      deadline: '2026-06-20 13:00:00',
    });
  });
});

describe('briefChanges', () => {
  it('is empty when nothing changed, whatever the order of the GEO or spaces around the offer', () => {
    const values = { ...briefFormValues(TASK), offer: '  Casino Royal ', geo: ['DE', 'FR'] };
    expect(briefChanges(TASK, values)).toEqual({});
  });

  it('names only the fields that changed, in the form the API wants', () => {
    const values = {
      ...briefFormValues(TASK),
      offer: ' Lucky Spin ',
      quantity: '5',
      priority: 'high',
      geo: ['PL', 'DE'],
      deadline: '2026-06-21 09:30:00',
    };
    expect(briefChanges(TASK, values)).toEqual({
      offer: 'Lucky Spin',
      quantity: 5,
      priority: 'high',
      geo: ['DE', 'PL'],
      deadline: '2026-06-21T07:30:00Z',
    });
  });

  it('sends the description exactly as typed (the server stores it untrimmed)', () => {
    const values = { ...briefFormValues(TASK), description: 'Short hook.\n' };
    expect(briefChanges(TASK, values)).toEqual({ description: 'Short hook.\n' });
  });

  it('sends the creative type when it changed', () => {
    expect(briefChanges(TASK, { ...briefFormValues(TASK), creativeType: 'carousel' })).toEqual({
      creativeType: 'carousel',
    });
  });
});

describe('editBriefSchema', () => {
  const now = () => jest.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-07-01T10:00:00Z'));

  it('accepts an unchanged deadline that has passed, as the API does', () => {
    now();
    const values = briefFormValues(TASK);
    expect(editBriefSchema(values.deadline).safeParse(values).success).toBe(true);
  });

  it('wants a changed deadline in the future', () => {
    now();
    const values = { ...briefFormValues(TASK), deadline: '2026-06-25 13:00:00' };
    const result = editBriefSchema(briefFormValues(TASK).deadline).safeParse(values);
    expect(result.success).toBe(false);
    expect(result.error?.issues.map((issue) => [issue.path.join('.'), issue.message])).toEqual([
      ['deadline', 'must_be_future'],
    ]);
  });

  it('checks the other fields with the rules of the create form', () => {
    now();
    const values = {
      ...briefFormValues(TASK),
      offer: ' x ',
      quantity: 101,
      geo: [],
      description: '   ',
      deadline: null,
    };
    const result = editBriefSchema(briefFormValues(TASK).deadline).safeParse(values);
    expect(
      Object.fromEntries(
        (result.error?.issues ?? []).map((issue) => [issue.path.join('.'), issue.message]),
      ),
    ).toEqual({
      offer: 'too_short:{"min":2}',
      quantity: 'out_of_range:{"min":1,"max":100}',
      geo: 'required',
      description: 'required',
      deadline: 'required',
    });
  });
});

describe('mapEditBriefError', () => {
  /** @param {{field: string, code: string, params?: Record<string, unknown>}[]} fields */
  const invalid = (fields) =>
    new ApiRequestError(
      422,
      'validation_failed',
      fields.map((item) => ({ params: {}, ...item })),
    );

  it('puts every brief field on its input and the rest in the alert', () => {
    const { fields, form } = mapEditBriefError(
      invalid([
        { field: 'offer', code: 'too_long', params: { max: 100 } },
        { field: 'geo.1', code: 'invalid_choice' },
        { field: 'geo', code: 'too_long', params: { max: 30 } },
        { field: 'deadline', code: 'must_be_future' },
        { field: 'references', code: 'unknown_field' },
        { field: '', code: 'required' },
      ]),
    );
    expect(fields).toEqual([
      { name: 'offer', message: 'too_long:{"max":100}' },
      { name: 'geo', message: 'invalid_choice' },
      { name: 'deadline', message: 'must_be_future' },
    ]);
    expect(form.map((item) => item.field)).toEqual(['references', '']);
  });

  it('calls a GEO list that is too long "too many"', () => {
    const { fields } = mapEditBriefError(
      invalid([{ field: 'geo', code: 'too_long', params: { max: 30 } }]),
    );
    expect(fields).toEqual([{ name: 'geo', message: 'too_many:{"max":30}' }]);
  });
});
