import { notifications } from '@mantine/notifications';
import { QueryClient } from '@tanstack/react-query';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../../api/client';
import { bodyOf } from '../../../test/actions';
import { callsTo } from '../../../test/createTaskForm';
import { errorResponse, makeMe, mockResponse } from '../../../test/http';
import { makeOptions, renderApp } from '../../../test/renderApp';
import { DMITRY, makeEvent, makeTaskDetail } from '../../../test/taskDetail';

afterEach(() => {
  notifications.clean();
  jest.restoreAllMocks();
});

beforeEach(() => {
  setCsrfToken(null);
});

const BUYER = () => makeMe({ id: DMITRY.id, role: 'buyer', displayName: DMITRY.displayName });
const PATCH = 'PATCH /api/tasks/42';

/** A task the buyer may edit; its deadline lies in the future. */
const EDITABLE = {
  status: /** @type {const} */ ('new'),
  priority: 'normal',
  deadlineAt: '2099-01-01T12:00:00Z',
  allowedActions: ['edit_brief', 'cancel'],
};

/**
 * @param {Partial<import('../api').TaskDetail>} overrides
 * @param {{me?: ReturnType<typeof makeMe>, table?: Record<string, any>}} [options]
 */
function renderTask(overrides, { me = BUYER(), table = {} } = {}) {
  return renderApp('/tasks/42', {
    me,
    table: {
      'GET /api/tasks/42': mockResponse(200, makeTaskDetail({ ...EDITABLE, ...overrides })),
      'GET /api/options': mockResponse(200, makeOptions()),
      ...table,
    },
  });
}

async function openDialog(name = 'Edit brief') {
  await userEvent.click(await screen.findByRole('button', { name }));
  const dialog = await screen.findByRole('dialog', { name });
  await within(dialog).findByLabelText(/^Offer/);
  return dialog;
}

/** @param {HTMLElement} dialog */
const save = (dialog) => within(dialog).getByRole('button', { name: 'Save changes' });
const taskCalls = (/** @type {Parameters<typeof callsTo>[0]} */ fetchMock) =>
  callsTo(fetchMock, 'GET', '/api/tasks/42');

describe('the Edit brief button', () => {
  it('is shown when the API offers edit_brief, before Cancel task', async () => {
    renderTask({});
    const bar = await screen.findByRole('group', { name: 'Task actions' });
    expect(
      within(bar)
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).toEqual(['Edit brief', 'Cancel task']);
  });

  it('is not shown without edit_brief', async () => {
    renderTask({ allowedActions: ['cancel'] });
    await screen.findByRole('button', { name: 'Cancel task' });
    expect(screen.queryByRole('button', { name: 'Edit brief' })).not.toBeInTheDocument();
  });
});

describe('the dialog', () => {
  it('is filled from the task and offers no references', async () => {
    renderTask({ offer: 'Casino Royal', quantity: 3, description: 'Short hook.' });
    const dialog = await openDialog();
    expect(within(dialog).getByLabelText(/^Offer/)).toHaveValue('Casino Royal');
    expect(within(dialog).getByLabelText(/^Quantity/)).toHaveValue('3');
    expect(within(dialog).getByLabelText(/^Description/)).toHaveValue('Short hook.');
    expect(within(dialog).getByRole('radio', { name: 'Normal' })).toBeChecked();
    expect(within(dialog).queryByRole('button', { name: 'Add link' })).not.toBeInTheDocument();
    expect(within(dialog).getByText(/References cannot be changed/)).toBeInTheDocument();
  });

  it('sends only the fields that changed and refreshes the task, boards and dashboards', async () => {
    const invalidate = jest.spyOn(QueryClient.prototype, 'invalidateQueries');
    const { fetchMock } = renderTask(
      {},
      { table: { [PATCH]: mockResponse(200, makeTaskDetail()) } },
    );
    const dialog = await openDialog();
    const offer = within(dialog).getByLabelText(/^Offer/);
    await userEvent.clear(offer);
    await userEvent.type(offer, '  Lucky Spin ');
    await userEvent.click(within(dialog).getByRole('radio', { name: 'High' }));
    await userEvent.click(save(dialog));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(callsTo(fetchMock, 'PATCH', '/api/tasks/42')).toHaveLength(1);
    expect(bodyOf(fetchMock, 'PATCH', '/api/tasks/42')).toEqual({
      offer: 'Lucky Spin',
      priority: 'high',
    });
    expect(await screen.findByText('Brief updated.')).toBeVisible();
    for (const queryKey of [['task', 42], ['board'], ['dashboard']]) {
      expect(invalidate).toHaveBeenCalledWith({ queryKey });
    }
  });

  it('closes without a request when nothing changed', async () => {
    const { fetchMock } = renderTask({});
    const dialog = await openDialog();
    await userEvent.click(save(dialog));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(callsTo(fetchMock, 'PATCH', '/api/tasks/42')).toHaveLength(0);
  });

  it('lets a brief whose deadline has passed be edited without moving the deadline', async () => {
    const { fetchMock } = renderTask(
      { deadlineAt: '2020-01-01T12:00:00Z', isOverdue: true },
      { table: { [PATCH]: mockResponse(200, makeTaskDetail()) } },
    );
    const dialog = await openDialog();
    await userEvent.type(within(dialog).getByLabelText(/^Description/), ' More.');
    await userEvent.click(save(dialog));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(bodyOf(fetchMock, 'PATCH', '/api/tasks/42')).toEqual({
      description: 'Short hook.\nLogo in the corner. More.',
    });
  });

  it('shows the rules of the create form and sends nothing while they fail', async () => {
    const { fetchMock } = renderTask({});
    const dialog = await openDialog();
    await userEvent.clear(within(dialog).getByLabelText(/^Offer/));
    await userEvent.clear(within(dialog).getByLabelText(/^Description/));
    await userEvent.click(save(dialog));
    expect(await within(dialog).findAllByText('This field is required.')).toHaveLength(2);
    expect(callsTo(fetchMock, 'PATCH', '/api/tasks/42')).toHaveLength(0);
  });

  it('puts the field problems of a 422 on the inputs', async () => {
    renderTask(
      {},
      {
        table: {
          [PATCH]: errorResponse(422, 'validation_failed', {
            fields: [{ field: 'offer', code: 'too_long', params: { max: 100 } }],
          }),
        },
      },
    );
    const dialog = await openDialog();
    await userEvent.type(within(dialog).getByLabelText(/^Offer/), '!');
    await userEvent.click(save(dialog));
    expect(await within(dialog).findByText('Must be at most 100 characters')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it.each([
    ['task_not_editable', 409, 'This task can no longer be edited.'],
    ['invalid_transition', 409, /not possible for the task in its current status/],
    ['forbidden', 403, /do not have permission/],
    ['not_found', 404, /Not found/],
  ])('on %s shows a toast, closes and refetches the task', async (code, status, message) => {
    const { fetchMock } = renderTask({}, { table: { [PATCH]: errorResponse(status, code) } });
    const dialog = await openDialog();
    await userEvent.type(within(dialog).getByLabelText(/^Offer/), '!');
    await userEvent.click(save(dialog));
    expect(await screen.findByText(message)).toBeVisible();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    await waitFor(() => expect(taskCalls(fetchMock)).toHaveLength(2));
  });

  it('keeps the dialog and what was typed after a server failure', async () => {
    renderTask({}, { table: { [PATCH]: errorResponse(500, 'internal_error') } });
    const dialog = await openDialog();
    await userEvent.type(within(dialog).getByLabelText(/^Offer/), '!');
    await userEvent.click(save(dialog));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Something went wrong on our side. Please try again later.',
    );
    expect(within(dialog).getByLabelText(/^Offer/)).toHaveValue('Casino Royal!');
    expect(save(dialog)).toBeEnabled();
  });

  it('sends one request for a double click', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderTask(
      {},
      {
        table: {
          [PATCH]: () =>
            new Promise((resolve) => {
              release = resolve;
            }),
        },
      },
    );
    const dialog = await openDialog();
    await userEvent.type(within(dialog).getByLabelText(/^Offer/), '!');
    await userEvent.dblClick(save(dialog));
    await waitFor(() => expect(callsTo(fetchMock, 'PATCH', '/api/tasks/42')).toHaveLength(1));
    await act(async () => release(mockResponse(200, makeTaskDetail())));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(callsTo(fetchMock, 'PATCH', '/api/tasks/42')).toHaveLength(1);
  });

  it('shows the new brief_edited line in an open history after saving', async () => {
    let edited = false;
    const events = () => [
      makeEvent('task_created', {}, { actor: DMITRY }),
      ...(edited
        ? [
            makeEvent(
              'brief_edited',
              { changes: [{ field: 'offer', old: 'Casino Royal', new: 'Lucky Spin' }] },
              { actor: DMITRY },
            ),
          ]
        : []),
    ];
    const { fetchMock } = renderTask(
      {},
      {
        table: {
          'GET /api/tasks/42/history': () => mockResponse(200, { events: events() }),
          [PATCH]: () => {
            edited = true;
            return mockResponse(200, makeTaskDetail());
          },
        },
      },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Activity history' }));
    await screen.findByText(/created the task/);
    const dialog = await openDialog();
    const offer = within(dialog).getByLabelText(/^Offer/);
    await userEvent.clear(offer);
    await userEvent.type(offer, 'Lucky Spin');
    await userEvent.click(save(dialog));

    expect(await screen.findByText('Dmitry P. edited the brief')).toBeInTheDocument();
    expect(screen.getByText('Offer: Casino Royal → Lucky Spin')).toBeInTheDocument();
    expect(callsTo(fetchMock, 'GET', '/api/tasks/42/history')).toHaveLength(2);
  });

  it('speaks Russian', async () => {
    renderTask({}, { me: makeMe({ ...BUYER(), locale: 'ru' }) });
    await userEvent.click(await screen.findByRole('button', { name: 'Изменить бриф' }));
    const dialog = await screen.findByRole('dialog', { name: 'Редактирование брифа' });
    expect(await within(dialog).findByLabelText(/^Оффер/)).toHaveValue('Casino Royal');
    expect(within(dialog).getByRole('button', { name: 'Сохранить изменения' })).toBeEnabled();
    expect(within(dialog).getByText(/Референсы после создания задачи/)).toBeInTheDocument();
  });
});
