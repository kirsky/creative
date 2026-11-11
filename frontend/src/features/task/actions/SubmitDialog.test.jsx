import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../../api/client';
import i18n from '../../../i18n';
import {
  bodyOf,
  fileInput,
  fileUploads,
  paste,
  png,
  renderInProviders,
} from '../../../test/actions';
import { callsTo } from '../../../test/createTaskForm';
import { errorResponse, mockResponse } from '../../../test/http';
import { SubmitDialog } from './SubmitDialog';

const ROUNDS = '/api/tasks/42/rounds';
const CREATED = mockResponse(201, { number: 1 });
const PROVIDERS = mockResponse(200, { providers: [{ id: 1, name: 'Runway' }] });

/** @param {Parameters<typeof renderInProviders>[1]} [table] */
function renderDialog(table = {}) {
  const onClose = jest.fn();
  const utils = renderInProviders(<SubmitDialog taskId={42} onClose={onClose} />, {
    'GET /api/ai-providers': PROVIDERS,
    'POST /api/files': fileUploads(),
    ...table,
  });
  return { onClose, ...utils };
}

const submit = () => screen.getByRole('button', { name: 'Submit' });
const note = () => screen.getByLabelText('Note for the buyer');

/** @param {...File} files */
async function attach(...files) {
  await screen.findByRole('dialog');
  await userEvent.upload(fileInput(), files);
}

/** Waits until every file of the list has uploaded. */
async function uploaded(/** @type {number} */ count) {
  await waitFor(() => expect(screen.getAllByText('Uploaded')).toHaveLength(count));
}

beforeEach(() => {
  setCsrfToken('c'.repeat(64));
});

describe('SubmitDialog', () => {
  it('is a dialog titled Submit work with files, a note and the AI usage', async () => {
    renderDialog();
    const dialog = await screen.findByRole('dialog', { name: 'Submit work' });
    expect(within(dialog).getByRole('button', { name: 'Attach files' })).toBeInTheDocument();
    expect(within(dialog).getByLabelText('Note for the buyer')).toBeInTheDocument();
    expect(within(dialog).getByText('AI usage')).toBeInTheDocument();
    expect(within(dialog).getByText('No AI used')).toBeInTheDocument();
  });

  it('blocks the submission without files', async () => {
    const { fetchMock, onClose } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    await screen.findByRole('dialog');
    await userEvent.click(submit());
    expect(await screen.findByText('This field is required.')).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(0);
    expect(onClose).not.toHaveBeenCalled();
  });

  it('does not count a file that failed to upload', async () => {
    const { fetchMock } = renderDialog({
      'POST /api/files': errorResponse(422, 'file_type_not_allowed'),
      [`POST ${ROUNDS}`]: CREATED,
    });
    await attach(png('evil.png'));
    expect(await screen.findByText('This type of file is not allowed.')).toBeInTheDocument();
    await userEvent.click(submit());
    expect(await screen.findAllByText('This field is required.')).not.toHaveLength(0);
    expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(0);
  });

  it('uploads the files first and sends their ids, with no AI lines and no note', async () => {
    const { fetchMock, onClose } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    await attach(png('a.png'), png('b.png'));
    await uploaded(2);
    await userEvent.click(submit());
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));

    expect(bodyOf(fetchMock, 'POST', ROUNDS)).toEqual({ note: '', fileIds: [11, 12], aiUsage: [] });
    const lastUpload = fetchMock.mock.calls.map(([url]) => url).lastIndexOf('/api/files');
    const roundCall = fetchMock.mock.calls.findIndex(([url]) => url === ROUNDS);
    expect(lastUpload).toBeLessThan(roundCall);
    expect(await screen.findByText('Work submitted for review.')).toBeInTheDocument();
  });

  it('sends the note and the AI usage with provider and credits as strings', async () => {
    const { fetchMock, onClose } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.type(note(), '  Hook is shorter now 🎰  ');
    await userEvent.click(screen.getByRole('button', { name: 'Add line' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add line' }));
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), ' Runway ');
    await userEvent.type(screen.getByLabelText('Credits 1'), '120.50');
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 2' }), 'Midjourney');
    await userEvent.type(screen.getByLabelText('Credits 2'), '0');
    await userEvent.click(submit());
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));

    const body = bodyOf(fetchMock, 'POST', ROUNDS);
    expect(body).toEqual({
      note: 'Hook is shorter now 🎰',
      fileIds: [11],
      aiUsage: [
        { provider: 'Runway', credits: '120.50' },
        { provider: 'Midjourney', credits: '0' },
      ],
    });
    const raw = callsTo(fetchMock, 'POST', ROUNDS)[0][1].body;
    expect(raw).toContain('"credits":"120.50"');
  });

  it('shows a client error on the amount and sends nothing', async () => {
    const { fetchMock } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(screen.getByRole('button', { name: 'Add line' }));
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), 'Runway');
    await userEvent.type(screen.getByLabelText('Credits 1'), '1.234');
    await userEvent.click(submit());
    expect(
      await screen.findByText('Enter a number of zero or more, with up to two decimal places.'),
    ).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(0);
  });

  it('blocks the 26th file on this side and attaches only 25', async () => {
    const { fetchMock, onClose } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    await attach(...Array.from({ length: 26 }, (_, index) => png(`f${index}.png`)));
    await uploaded(25);
    expect(screen.getByText('No more than 25 items')).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', '/api/files')).toHaveLength(25);
    await userEvent.click(submit());
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(bodyOf(fetchMock, 'POST', ROUNDS).fileIds).toHaveLength(25);
    expect(
      screen.getByText(/1 file failed to upload and will not be attached/),
    ).toBeInTheDocument();
  });

  it('refuses a note of more than 2000 characters', async () => {
    const { fetchMock } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    await attach(png('a.png'));
    await uploaded(1);
    await paste(note(), 'x'.repeat(2001));
    await userEvent.click(submit());
    expect(await screen.findByText('Must be at most 2000 characters')).toBeInTheDocument();
    expect(note()).toBeInvalid();
    expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(0);
  });

  it('waits for a file that is still uploading', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderDialog({
      'POST /api/files': () =>
        new Promise((resolve) => {
          release = resolve;
        }),
      [`POST ${ROUNDS}`]: CREATED,
    });
    await attach(png('a.png'));
    await waitFor(() => expect(submit()).toBeDisabled());
    await userEvent.click(submit());
    expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(0);

    await act(async () =>
      release(mockResponse(201, { id: 5, name: 'a.png', mime: 'image/png', size: 3 })),
    );
    await waitFor(() => expect(submit()).toBeEnabled());
    await userEvent.click(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(1));
    expect(bodyOf(fetchMock, 'POST', ROUNDS).fileIds).toEqual([5]);
  });

  it('sends one request for a double click', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock, onClose } = renderDialog({
      [`POST ${ROUNDS}`]: () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.dblClick(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(1));
    expect(submit()).toBeDisabled();
    release(CREATED);
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(1);
  });

  it('refreshes the task, the boards, the dashboards and the providers after a success', async () => {
    const { queryClient, onClose } = renderDialog({ [`POST ${ROUNDS}`]: CREATED });
    const invalidate = jest.spyOn(queryClient, 'invalidateQueries');
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(submit());
    await waitFor(() => expect(onClose).toHaveBeenCalled());
    for (const queryKey of [['task', 42], ['board'], ['dashboard'], ['aiProviders']]) {
      expect(invalidate).toHaveBeenCalledWith({ queryKey });
    }
  });

  it('puts a server error of an amount on its line', async () => {
    const { onClose } = renderDialog({
      [`POST ${ROUNDS}`]: errorResponse(422, 'validation_failed', {
        fields: [
          { field: 'aiUsage.1.credits', code: 'invalid_amount', params: {} },
          { field: 'aiUsage.0.provider', code: 'too_long', params: { max: 50 } },
        ],
      }),
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(screen.getByRole('button', { name: 'Add line' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add line' }));
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), 'Runway');
    await userEvent.type(screen.getByLabelText('Credits 1'), '1');
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 2' }), 'Sora');
    await userEvent.type(screen.getByLabelText('Credits 2'), '2');
    await userEvent.click(submit());

    expect(
      await screen.findByText('Enter a number of zero or more, with up to two decimal places.'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Credits 2')).toBeInvalid();
    expect(screen.getByLabelText('Credits 1')).toBeValid();
    expect(screen.getByRole('combobox', { name: 'Provider 1' })).toBeInvalid();
    expect(screen.getByText('Must be at most 50 characters')).toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
  });

  it('puts server errors of the files and the note where they belong', async () => {
    renderDialog({
      [`POST ${ROUNDS}`]: errorResponse(422, 'validation_failed', {
        fields: [
          { field: 'files', code: 'invalid_choice', params: {} },
          { field: 'note', code: 'too_long', params: { max: 2000 } },
        ],
      }),
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(submit());
    expect(await screen.findByText('Choose one of the available options.')).toBeInTheDocument();
    expect(screen.getByText('Must be at most 2000 characters')).toBeInTheDocument();
    expect(note()).toBeInvalid();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows what belongs to no field as an alert of the form', async () => {
    renderDialog({
      [`POST ${ROUNDS}`]: errorResponse(422, 'validation_failed', {
        fields: [{ field: 'aiUsage.0.colour', code: 'unknown_field', params: {} }],
      }),
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(submit());
    expect(await screen.findByRole('alert')).toHaveTextContent('This field is not expected here.');
  });

  it('keeps the dialog open with everything entered after a server failure', async () => {
    const { fetchMock, onClose } = renderDialog({
      [`POST ${ROUNDS}`]: errorResponse(502, 'unknown'),
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.type(note(), 'My note');
    await userEvent.click(screen.getByRole('button', { name: 'Add line' }));
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), 'Runway');
    await userEvent.type(screen.getByLabelText('Credits 1'), '9');
    await userEvent.click(submit());

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Something went wrong. Please try again.',
    );
    expect(onClose).not.toHaveBeenCalled();
    expect(note()).toHaveValue('My note');
    expect(screen.getByRole('combobox', { name: 'Provider 1' })).toHaveValue('Runway');
    expect(screen.getByText('a.png')).toBeInTheDocument();
    expect(submit()).toBeEnabled();

    // Trying again sends the same data (the file is not uploaded a second time).
    await userEvent.click(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', ROUNDS)).toHaveLength(2));
    expect(callsTo(fetchMock, 'POST', '/api/files')).toHaveLength(1);
    expect(bodyOf(fetchMock, 'POST', ROUNDS, 1)).toEqual(bodyOf(fetchMock, 'POST', ROUNDS, 0));
  });

  it('keeps the dialog open when the network fails', async () => {
    const { onClose } = renderDialog({
      [`POST ${ROUNDS}`]: () => {
        throw new Error('offline');
      },
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(submit());
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not connect to the server');
    expect(onClose).not.toHaveBeenCalled();
  });

  it.each([
    ['invalid_transition', 409, /not possible for the task in its current status/],
    ['forbidden', 403, /do not have permission/],
    ['not_found', 404, /Not found/],
    ['already_taken', 409, /already taken/],
  ])('closes with a toast on %s', async (code, status, message) => {
    const { onClose } = renderDialog({ [`POST ${ROUNDS}`]: errorResponse(status, code) });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(submit());
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(await screen.findByText(message)).toBeVisible();
  });

  it('closes with Escape unless the submission is on its way', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { onClose } = renderDialog({
      [`POST ${ROUNDS}`]: () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    });
    await attach(png('a.png'));
    await uploaded(1);
    await userEvent.click(submit());
    await waitFor(() => expect(submit()).toBeDisabled());
    await userEvent.keyboard('{Escape}');
    expect(onClose).not.toHaveBeenCalled();
    release(CREATED);
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
  });

  it('closes with Escape while idle', async () => {
    const { onClose } = renderDialog();
    await screen.findByRole('dialog');
    await userEvent.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('keeps the focus inside the dialog', async () => {
    renderDialog();
    const dialog = await screen.findByRole('dialog');
    await waitFor(() =>
      expect(dialog).toContainElement(/** @type {HTMLElement} */ (document.activeElement)),
    );
    for (let press = 0; press < 8; press += 1) {
      await userEvent.tab();
      expect(dialog).toContainElement(/** @type {HTMLElement} */ (document.activeElement));
    }
  });

  it('is in Russian too', async () => {
    await i18n.changeLanguage('ru');
    renderDialog();
    const dialog = await screen.findByRole('dialog', { name: 'Сдать работу' });
    expect(within(dialog).getByText('ИИ не использовался')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Сдать' })).toBeInTheDocument();
  });
});
