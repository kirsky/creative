import { QueryClient } from '@tanstack/react-query';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { callsTo, fillValidForm, mockNow } from '../../test/createTaskForm';
import { errorResponse, mockResponse } from '../../test/http';
import { makeOptions, renderApp } from '../../test/renderApp';

const CREATED = mockResponse(201, { id: 42, offer: 'Casino Royal' });

/** @param {Parameters<typeof import('../../test/http').installFetch>[0]} [table] */
function renderForm(table = {}) {
  return renderApp('/tasks/new', {
    table: { 'GET /api/options': mockResponse(200, makeOptions()), ...table },
  });
}

const submit = () => screen.getByRole('button', { name: 'Create task' });
const fileInput = () =>
  /** @type {HTMLInputElement} */ (document.querySelector('input[type="file"]'));
/** @param {ReturnType<typeof renderApp>['fetchMock']} fetchMock */
const taskBody = (fetchMock) => JSON.parse(callsTo(fetchMock, 'POST', '/api/tasks')[0][1].body);
/** @param {string} name */
const png = (name) => new File(['abc'], name, { type: 'image/png' });

async function ready() {
  await screen.findByRole('heading', { level: 1, name: 'New task' });
  await screen.findByLabelText(/^Offer/);
}

beforeEach(() => {
  setCsrfToken(null);
});
afterEach(() => {
  jest.restoreAllMocks();
});

describe('what is sent', () => {
  it('posts the brief as JSON with the deadline in UTC (summer time)', async () => {
    mockNow('2026-06-15T08:00:00Z'); // 10:00 in Berlin
    const { fetchMock } = renderForm({ 'POST /api/tasks': CREATED });
    await ready();
    await userEvent.type(screen.getByLabelText(/^Offer/), '  Casino Royal  ');
    await userEvent.click(screen.getByRole('combobox', { name: /^Creative type/ }));
    await userEvent.click(await screen.findByRole('option', { name: 'Video', hidden: true }));
    await userEvent.click(screen.getByRole('combobox', { name: /^GEO/ }));
    await userEvent.click(await screen.findByRole('option', { name: 'Germany', hidden: true }));
    await userEvent.click(await screen.findByRole('option', { name: 'Europe', hidden: true }));
    await userEvent.clear(screen.getByLabelText(/^Quantity/));
    await userEvent.type(screen.getByLabelText(/^Quantity/), '12');
    await userEvent.click(screen.getByRole('radio', { name: 'Urgent' }));
    await userEvent.type(screen.getByLabelText(/^Description/), ' 🎰 <b>x</b> Лото ');
    await userEvent.click(submit());

    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    const [, init] = callsTo(fetchMock, 'POST', '/api/tasks')[0];
    expect(init.headers).toMatchObject({ 'Content-Type': 'application/json' });
    expect(taskBody(fetchMock)).toEqual({
      offer: 'Casino Royal',
      geo: ['DE', 'EU'],
      creativeType: 'video',
      quantity: 12,
      description: ' 🎰 <b>x</b> Лото ',
      priority: 'urgent',
      // Tomorrow 12:00 Berlin (CEST, UTC+2).
      deadline: '2026-06-16T10:00:00Z',
      references: [],
    });
  });

  it('converts the default deadline correctly across the daylight-saving change', async () => {
    mockNow('2026-03-28T10:00:00Z'); // the next day is 2026-03-29, after clocks went forward
    const { fetchMock } = renderForm({ 'POST /api/tasks': CREATED });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    expect(taskBody(fetchMock).deadline).toBe('2026-03-29T10:00:00Z');
  });

  it('defaults priority to normal', async () => {
    const { fetchMock } = renderForm({ 'POST /api/tasks': CREATED });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    expect(taskBody(fetchMock)).toMatchObject({ priority: 'normal', quantity: 1 });
  });
});

describe('references', () => {
  it('uploads files first, one request per file, then sends their ids with the links', async () => {
    let id = 100;
    const { fetchMock } = renderForm({
      'POST /api/files': () => {
        id += 1;
        return mockResponse(201, { id, name: `f${id}`, mime: 'image/png', size: 3 });
      },
      'POST /api/tasks': CREATED,
    });
    await ready();
    await fillValidForm();
    await userEvent.click(screen.getByRole('button', { name: 'Add link' }));
    await userEvent.type(screen.getByLabelText('Link 1'), ' https://example.com/a ');
    await userEvent.click(screen.getByRole('button', { name: 'Add link' }));
    // A blank row is not sent.
    await userEvent.click(screen.getByRole('button', { name: 'Add link' }));
    await userEvent.type(screen.getByLabelText('Link 3'), 'http://example.org');
    await userEvent.upload(fileInput(), [png('a.png'), png('b.png')]);
    await waitFor(() => expect(submit()).toBeEnabled());
    expect(callsTo(fetchMock, 'POST', '/api/files')).toHaveLength(2);
    expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(0);

    await userEvent.click(submit());

    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    expect(taskBody(fetchMock).references).toEqual([
      { type: 'url', url: 'https://example.com/a' },
      { type: 'url', url: 'http://example.org' },
      { type: 'file', fileId: 101 },
      { type: 'file', fileId: 102 },
    ]);
    const urls = fetchMock.mock.calls.map(([url]) => url);
    expect(urls.lastIndexOf('/api/files')).toBeLessThan(urls.indexOf('/api/tasks'));
  });

  it('does not send a file that failed to upload, and submit stays possible', async () => {
    let call = 0;
    const { fetchMock } = renderForm({
      'POST /api/files': () => {
        call += 1;
        return call === 1
          ? errorResponse(422, 'file_type_not_allowed')
          : mockResponse(201, { id: 7, name: 'b.png', mime: 'image/png', size: 3 });
      },
      'POST /api/tasks': CREATED,
    });
    await ready();
    await fillValidForm();
    await userEvent.upload(fileInput(), [png('evil.png'), png('b.png')]);
    expect(await screen.findByText('This type of file is not allowed.')).toBeInTheDocument();
    expect(
      screen.getByText('1 file failed to upload and will not be attached.'),
    ).toBeInTheDocument();
    await waitFor(() => expect(submit()).toBeEnabled());

    await userEvent.click(submit());

    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    expect(taskBody(fetchMock).references).toEqual([{ type: 'file', fileId: 7 }]);
  });

  it('a removed file is not sent', async () => {
    const { fetchMock } = renderForm({
      'POST /api/files': mockResponse(201, { id: 7, name: 'a.png', mime: 'image/png', size: 3 }),
      'POST /api/tasks': CREATED,
    });
    await ready();
    await fillValidForm();
    await userEvent.upload(fileInput(), png('a.png'));
    await screen.findByText('Uploaded');
    await userEvent.click(screen.getByRole('button', { name: 'Remove a.png' }));
    await userEvent.click(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    expect(taskBody(fetchMock).references).toEqual([]);
  });

  it('rejects a file over 99 MB without a request', async () => {
    const { fetchMock } = renderForm();
    await ready();
    const big = png('big.png');
    Object.defineProperty(big, 'size', { value: 99_000_001 });
    await userEvent.upload(fileInput(), big);
    expect(await screen.findByText(/The maximum size is 99 MB/)).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', '/api/files')).toHaveLength(0);
  });

  it('disables Create with a loading state while an upload is pending', async () => {
    /** @type {() => void} */
    let release = () => {};
    const { fetchMock } = renderForm({
      'POST /api/files': () =>
        new Promise((resolve) => {
          release = () =>
            resolve(mockResponse(201, { id: 7, name: 'a.png', mime: 'image/png', size: 3 }));
        }),
      'POST /api/tasks': CREATED,
    });
    await ready();
    await fillValidForm();
    await userEvent.upload(fileInput(), png('a.png'));
    await waitFor(() => expect(submit()).toBeDisabled());
    expect(submit()).toHaveAttribute('data-loading', 'true');
    // Enter in a field must not get around it.
    await userEvent.type(screen.getByLabelText(/^Offer/), '{Enter}');
    expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(0);

    await act(async () => release());
    await waitFor(() => expect(submit()).toBeEnabled());
  });
});

describe('server answers', () => {
  it('shows validation_failed field errors under the matching inputs', async () => {
    renderForm({
      'POST /api/tasks': errorResponse(422, 'validation_failed', {
        fields: [
          { field: 'offer', code: 'too_long', params: { max: 100 } },
          { field: 'references.0.url', code: 'invalid_url', params: {} },
          { field: 'geo', code: 'invalid_choice', params: {} },
        ],
      }),
    });
    await ready();
    await fillValidForm();
    await userEvent.click(screen.getByRole('button', { name: 'Add link' }));
    await userEvent.type(screen.getByLabelText('Link 1'), 'https://example.com');
    await userEvent.click(submit());

    expect(await screen.findByText('Must be at most 100 characters')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Offer/)).toBeInvalid();
    expect(screen.getByLabelText('Link 1')).toBeInvalid();
    expect(screen.getByText('Choose one of the available options.')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: /^GEO/ })).toBeInvalid();
    expect(screen.getByLabelText(/^Offer/)).toHaveFocus();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    // Fixing the field clears its server error, the others stay.
    await userEvent.type(screen.getByLabelText(/^Offer/), 'x');
    await waitFor(() => expect(screen.getByLabelText(/^Offer/)).toBeValid());
    expect(screen.getByLabelText('Link 1')).toBeInvalid();
    // And the message follows the language.
    await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
    expect(
      await screen.findByText('Введите корректную ссылку, например https://example.com.'),
    ).toBeInTheDocument();
  });

  it('puts a files problem next to the references', async () => {
    renderForm({
      'POST /api/files': mockResponse(201, { id: 7, name: 'a.png', mime: 'image/png', size: 3 }),
      'POST /api/tasks': errorResponse(422, 'validation_failed', {
        fields: [{ field: 'files', code: 'invalid_choice', params: {} }],
      }),
    });
    await ready();
    await fillValidForm();
    await userEvent.upload(fileInput(), png('a.png'));
    await screen.findByText('Uploaded');
    await userEvent.click(submit());
    const section = (await screen.findByRole('heading', { level: 2, name: 'References' })).closest(
      'section',
    );
    expect(
      await within(/** @type {HTMLElement} */ (section)).findByText(
        'Choose one of the available options.',
      ),
    ).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows form-level and unmappable errors in an alert', async () => {
    renderForm({
      'POST /api/tasks': errorResponse(422, 'validation_failed', {
        fields: [
          { field: '', code: 'invalid', params: {} },
          { field: '_invalid', code: 'invalid', params: {} },
          { field: 'remember', code: 'unknown_field', params: {} },
        ],
      }),
    });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('This value is not valid.');
    expect(alert).toHaveTextContent('could not be read');
    expect(alert).toHaveTextContent('This field is not expected here.');
    expect(submit()).toBeEnabled();
  });

  it.each([
    ['forbidden', errorResponse(403, 'forbidden'), 'You do not have permission to do this.'],
    [
      'already_taken',
      errorResponse(409, 'already_taken'),
      'Another designer has already taken this task.',
    ],
    ['a server error', errorResponse(500, 'internal_error'), 'Something went wrong on our side.'],
    [
      'a validation_failed without fields',
      errorResponse(422, 'validation_failed'),
      'Some fields are not valid.',
    ],
  ])('shows %s as a translated alert and keeps the form', async (_name, response, message) => {
    renderForm({ 'POST /api/tasks': response });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    expect(await screen.findByRole('alert')).toHaveTextContent(message);
    expect(screen.getByLabelText(/^Offer/)).toHaveValue('Casino Royal');
    expect(submit()).toBeEnabled();
  });

  it('shows a translated alert for a network failure', async () => {
    renderForm({
      'POST /api/tasks': () => {
        throw new TypeError('Failed to fetch');
      },
    });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not connect to the server.');
  });

  it('clears the previous alert when submitting again', async () => {
    let call = 0;
    renderForm({
      'POST /api/tasks': () => {
        call += 1;
        return call === 1 ? errorResponse(500, 'internal_error') : CREATED;
      },
    });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    await screen.findByRole('alert');
    await userEvent.click(submit());
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  });
});

describe('success', () => {
  it('navigates to the new task and invalidates the board queries', async () => {
    const invalidate = jest.spyOn(QueryClient.prototype, 'invalidateQueries');
    const { router } = renderForm({ 'POST /api/tasks': CREATED });
    await ready();
    await fillValidForm();
    await userEvent.click(submit());
    await waitFor(() => expect(router.state.location.pathname).toBe('/tasks/42'));
    expect(await screen.findByRole('heading', { name: 'Task 42' })).toBeInTheDocument();
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['board'] });
  });

  it('sends one request for a double click and for Enter pressed while pending', async () => {
    /** @type {() => void} */
    let release = () => {};
    const { fetchMock } = renderForm({
      'POST /api/tasks': () =>
        new Promise((resolve) => {
          release = () => resolve(CREATED);
        }),
    });
    await ready();
    await fillValidForm();
    await userEvent.dblClick(submit());
    await userEvent.type(screen.getByLabelText(/^Offer/), '{Enter}{Enter}');
    expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1);
    expect(submit()).toBeDisabled();
    await act(async () => release());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
  });

  it('Cancel goes back to the board', async () => {
    const { router } = renderForm();
    await ready();
    await userEvent.click(screen.getByRole('link', { name: 'Cancel' }));
    expect(router.state.location.pathname).toBe('/board');
  });
});
