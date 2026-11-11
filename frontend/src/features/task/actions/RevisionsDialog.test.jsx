import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../../api/client';
import { bodyOf, paste, renderInProviders } from '../../../test/actions';
import { callsTo } from '../../../test/createTaskForm';
import { errorResponse, mockResponse } from '../../../test/http';
import { makeTaskDetail } from '../../../test/taskDetail';
import { RevisionsDialog } from './RevisionsDialog';

const URL = '/api/tasks/42/request-revisions';

/** @param {Parameters<typeof renderInProviders>[1]} [table] */
function renderDialog(table = {}) {
  const onClose = jest.fn();
  const utils = renderInProviders(<RevisionsDialog taskId={42} onClose={onClose} />, table);
  return { onClose, ...utils };
}

const reason = () => screen.findByLabelText(/^Reason for revisions/);
const send = () => userEvent.click(screen.getByRole('button', { name: 'Request revisions' }));

beforeEach(() => {
  setCsrfToken('c'.repeat(64));
});

describe('RevisionsDialog', () => {
  it('is a dialog with a required reason field', async () => {
    renderDialog();
    expect(await screen.findByRole('dialog', { name: 'Request revisions' })).toBeInTheDocument();
    expect(await reason()).toBeRequired();
  });

  it.each([
    ['nothing', ''],
    ['only spaces', '   \n  '],
  ])('does not send %s and says the reason is required', async (_name, text) => {
    const { fetchMock, onClose } = renderDialog({ [`POST ${URL}`]: mockResponse(200, {}) });
    if (text !== '') {
      await userEvent.type(await reason(), text);
    }
    await send();
    expect(await screen.findByText('This field is required.')).toBeInTheDocument();
    expect(await reason()).toBeInvalid();
    expect(callsTo(fetchMock, 'POST', URL)).toHaveLength(0);
    expect(onClose).not.toHaveBeenCalled();
  });

  it('refuses a reason of more than 2000 characters', async () => {
    const { fetchMock } = renderDialog();
    await paste(await reason(), 'x'.repeat(2001));
    await send();
    expect(await screen.findByText('Must be at most 2000 characters')).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', URL)).toHaveLength(0);
  });

  it('accepts exactly 2000 characters and sends the trimmed reason', async () => {
    const { fetchMock, onClose } = renderDialog({
      [`POST ${URL}`]: mockResponse(200, makeTaskDetail({ status: 'revisions_required' })),
    });
    await paste(await reason(), `  ${'я'.repeat(2000)}  `);
    await send();
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(bodyOf(fetchMock, 'POST', URL)).toEqual({ reason: 'я'.repeat(2000) });
  });

  it('sends the reason, closes and confirms with a toast', async () => {
    const { fetchMock, onClose } = renderDialog({
      [`POST ${URL}`]: mockResponse(200, makeTaskDetail({ status: 'revisions_required' })),
    });
    await userEvent.type(await reason(), 'Shorter hook 🎰 <b>x</b>');
    await send();
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(bodyOf(fetchMock, 'POST', URL)).toEqual({ reason: 'Shorter hook 🎰 <b>x</b>' });
    expect(await screen.findByText('Revisions requested.')).toBeInTheDocument();
  });

  it('shows the error of the server under the reason', async () => {
    const { onClose } = renderDialog({
      [`POST ${URL}`]: errorResponse(422, 'validation_failed', {
        fields: [{ field: 'reason', code: 'too_long', params: { max: 2000 } }],
      }),
    });
    await userEvent.type(await reason(), 'Shorter');
    await send();
    expect(await screen.findByText('Must be at most 2000 characters')).toBeInTheDocument();
    expect(await reason()).toBeInvalid();
    expect(onClose).not.toHaveBeenCalled();
  });

  it('keeps the dialog and the text on a server failure', async () => {
    const { onClose } = renderDialog({ [`POST ${URL}`]: errorResponse(500, 'internal_error') });
    await userEvent.type(await reason(), 'Shorter hook');
    await send();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Something went wrong on our side. Please try again later.',
    );
    expect(await reason()).toHaveValue('Shorter hook');
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Request revisions' })).toBeEnabled();
  });

  it('closes with a toast when the task has changed meanwhile', async () => {
    const { onClose } = renderDialog({ [`POST ${URL}`]: errorResponse(409, 'invalid_transition') });
    await userEvent.type(await reason(), 'Shorter hook');
    await send();
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(
      await screen.findByText(/not possible for the task in its current status/),
    ).toBeVisible();
  });

  it('sends one request for a double click', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock, onClose } = renderDialog({
      [`POST ${URL}`]: () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    });
    await userEvent.type(await reason(), 'Shorter hook');
    await userEvent.dblClick(screen.getByRole('button', { name: 'Request revisions' }));
    await waitFor(() => expect(callsTo(fetchMock, 'POST', URL)).toHaveLength(1));
    release(mockResponse(200, makeTaskDetail()));
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    expect(callsTo(fetchMock, 'POST', URL)).toHaveLength(1);
  });
});
