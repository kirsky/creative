import { QueryClient } from '@tanstack/react-query';
import { act, cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../../api/client';
import { notifications } from '@mantine/notifications';
import { bodyOf, paste } from '../../../test/actions';
import { callsTo } from '../../../test/createTaskForm';
import { errorResponse, makeMe, mockResponse } from '../../../test/http';
import { renderApp } from '../../../test/renderApp';
import { ANNA, DMITRY, IGOR, makeEvent, makeRound, makeTaskDetail } from '../../../test/taskDetail';

afterEach(() => {
  notifications.clean();
  jest.restoreAllMocks();
});

beforeEach(() => {
  setCsrfToken(null);
});

const BUYER = () => makeMe({ id: DMITRY.id, role: 'buyer', displayName: DMITRY.displayName });
const DESIGNER = () => makeMe({ id: ANNA.id, role: 'designer', displayName: ANNA.displayName });
const OTHER_DESIGNER = () =>
  makeMe({ id: IGOR.id, role: 'designer', displayName: IGOR.displayName });
const MANAGER = () => makeMe({ id: 9, role: 'manager', displayName: 'Maria M.' });

/**
 * @param {Partial<import('../api').TaskDetail>} overrides
 * @param {{me?: ReturnType<typeof makeMe>, table?: Parameters<typeof renderApp>[1] extends infer O ? any : never}} [options]
 */
function renderTask(overrides, { me = BUYER(), table = {} } = {}) {
  const task = makeTaskDetail(overrides);
  return {
    task,
    ...renderApp('/tasks/42', {
      me,
      table: {
        'GET /api/tasks/42': mockResponse(200, task),
        'GET /api/ai-providers': mockResponse(200, { providers: [{ id: 1, name: 'Runway' }] }),
        ...table,
      },
    }),
  };
}

const bar = () => screen.findByRole('group', { name: 'Task actions' });
const names = async () =>
  within(await bar())
    .getAllByRole('button')
    .map((button) => button.textContent);
const noBar = async () => {
  await screen.findByRole('heading', { level: 1 });
  expect(screen.queryByRole('group', { name: 'Task actions' })).not.toBeInTheDocument();
};
const taskCalls = (/** @type {Parameters<typeof callsTo>[0]} */ fetchMock) =>
  callsTo(fetchMock, 'GET', '/api/tasks/42');

/** A round the signed-in designer submitted and the buyer has not decided. */
const OPEN_ROUND = makeRound({
  number: 2,
  submittedBy: ANNA,
  decision: null,
  aiUsage: [{ provider: 'Runway', credits: '60.00' }],
});
const DECIDED_ROUND = makeRound({
  number: 1,
  submittedBy: ANNA,
  decision: 'revisions_requested',
  decisionReason: 'Shorter hook',
  decidedAt: '2026-11-10T10:00:00.000Z',
});

describe('which buttons are shown', () => {
  it('shows a buyer of a task under review Approve, Request revisions and Cancel task', async () => {
    renderTask({
      status: 'under_review',
      assignee: ANNA,
      rounds: [OPEN_ROUND],
      allowedActions: ['approve', 'request_revisions', 'cancel', 'edit_brief'],
    });
    expect(await names()).toEqual(['Approve', 'Request revisions', 'Cancel task']);
    expect(screen.queryByRole('button', { name: /edit brief/i })).not.toBeInTheDocument();
  });

  it('shows a buyer of a new task Cancel task only', async () => {
    renderTask({ status: 'new', allowedActions: ['cancel', 'edit_brief'] });
    expect(await names()).toEqual(['Cancel task']);
  });

  it('shows the assigned designer of a task in progress Submit work and Release task', async () => {
    renderTask(
      {
        status: 'in_progress',
        assignee: ANNA,
        allowedActions: ['release', 'submit', 'report_ai_usage'],
      },
      { me: DESIGNER() },
    );
    // report_ai_usage is flagged but there is no round to edit.
    expect(await names()).toEqual(['Submit work', 'Release task']);
  });

  it('adds Edit AI usage when the designer has an open round of their own', async () => {
    renderTask(
      {
        status: 'in_progress',
        assignee: ANNA,
        rounds: [DECIDED_ROUND, OPEN_ROUND],
        allowedActions: ['release', 'submit', 'report_ai_usage'],
      },
      { me: DESIGNER() },
    );
    expect(await names()).toEqual(['Submit work', 'Edit AI usage', 'Release task']);
  });

  it('shows Edit AI usage alone for a designer whose round waits for review', async () => {
    renderTask(
      {
        status: 'under_review',
        assignee: ANNA,
        rounds: [OPEN_ROUND],
        allowedActions: ['report_ai_usage'],
      },
      { me: DESIGNER() },
    );
    expect(await names()).toEqual(['Edit AI usage']);
  });

  it.each([
    [
      'the latest round is decided',
      [OPEN_ROUND, makeRound({ number: 3, submittedBy: ANNA, decision: 'approved' })],
    ],
    ["the latest round is another designer's", [makeRound({ number: 2, submittedBy: IGOR })]],
    ['there is no round', []],
  ])('hides Edit AI usage when %s', async (_name, rounds) => {
    renderTask(
      {
        status: 'in_progress',
        assignee: ANNA,
        rounds,
        allowedActions: ['submit', 'report_ai_usage'],
      },
      { me: DESIGNER() },
    );
    expect(await names()).toEqual(['Submit work']);
  });

  it('hides Edit AI usage when the API does not offer report_ai_usage', async () => {
    renderTask(
      { status: 'in_progress', assignee: ANNA, rounds: [OPEN_ROUND], allowedActions: ['submit'] },
      { me: DESIGNER() },
    );
    expect(await names()).toEqual(['Submit work']);
  });

  it('shows the assigned designer of a task awaiting revisions Submit work and Release task', async () => {
    renderTask(
      {
        status: 'revisions_required',
        assignee: ANNA,
        rounds: [DECIDED_ROUND],
        allowedActions: ['release', 'submit', 'report_ai_usage', 'edit_brief'],
      },
      { me: DESIGNER() },
    );
    expect(await names()).toEqual(['Submit work', 'Release task']);
  });

  it('shows a designer viewing a new task Pick up', async () => {
    renderTask({ status: 'new', allowedActions: ['pick_up'] }, { me: DESIGNER() });
    expect(await names()).toEqual(['Pick up']);
  });

  it('shows another designer and a manager no actions at all', async () => {
    renderTask(
      { status: 'in_progress', assignee: ANNA, allowedActions: [] },
      { me: OTHER_DESIGNER() },
    );
    await noBar();
    cleanup();
    renderTask({ status: 'under_review', assignee: ANNA, allowedActions: [] }, { me: MANAGER() });
    await noBar();
    expect(
      screen.queryAllByRole('button', {
        name: /approve|submit|pick up|release|cancel|revisions|ai usage/i,
      }),
    ).toHaveLength(0);
  });

  it('shows the buttons in Russian', async () => {
    renderTask(
      { status: 'under_review', allowedActions: ['approve', 'request_revisions', 'cancel'] },
      { me: makeMe({ ...BUYER(), locale: 'ru' }) },
    );
    expect(
      within(await screen.findByRole('group', { name: 'Действия' }))
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).toEqual(['Принять', 'Запросить правки', 'Отменить задачу']);
  });
});

describe('pick up', () => {
  it('picks the task up and shows the new state', async () => {
    let picked = false;
    const { fetchMock } = renderTask(
      { status: 'new', allowedActions: ['pick_up'] },
      {
        me: DESIGNER(),
        table: {
          'GET /api/tasks/42': () =>
            mockResponse(
              200,
              picked
                ? makeTaskDetail({
                    status: 'in_progress',
                    assignee: ANNA,
                    allowedActions: ['submit', 'release'],
                  })
                : makeTaskDetail({ status: 'new', allowedActions: ['pick_up'] }),
            ),
          'POST /api/tasks/42/pickup': () => {
            picked = true;
            return mockResponse(200, makeTaskDetail());
          },
        },
      },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Pick up' }));
    expect(await screen.findByRole('button', { name: 'Submit work' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pick up' })).not.toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', '/api/tasks/42/pickup')).toHaveLength(1);
  });

  it('sends one request for a double click', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderTask(
      { status: 'new', allowedActions: ['pick_up'] },
      {
        me: DESIGNER(),
        table: {
          'POST /api/tasks/42/pickup': () =>
            new Promise((resolve) => {
              release = resolve;
            }),
        },
      },
    );
    await userEvent.dblClick(await screen.findByRole('button', { name: 'Pick up' }));
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks/42/pickup')).toHaveLength(1));
    await act(async () => release(mockResponse(200, makeTaskDetail())));
    expect(callsTo(fetchMock, 'POST', '/api/tasks/42/pickup')).toHaveLength(1);
  });

  it('shows a toast and refetches when another designer was faster', async () => {
    const { fetchMock } = renderTask(
      { status: 'new', allowedActions: ['pick_up'] },
      {
        me: DESIGNER(),
        table: { 'POST /api/tasks/42/pickup': errorResponse(409, 'already_taken') },
      },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Pick up' }));
    expect(await screen.findByText('Another designer has already taken this task.')).toBeVisible();
    await waitFor(() => expect(taskCalls(fetchMock)).toHaveLength(2));
  });
});

describe('approve', () => {
  const URL = 'POST /api/tasks/42/approve';
  const underReview = {
    status: /** @type {const} */ ('under_review'),
    assignee: ANNA,
    rounds: [OPEN_ROUND],
    allowedActions: ['approve', 'request_revisions', 'cancel'],
  };

  it('asks for confirmation with an optional note and sends it', async () => {
    const { fetchMock } = renderTask(underReview, {
      table: { [URL]: mockResponse(200, makeTaskDetail({ status: 'completed' })) },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog', { name: 'Approve this work?' });
    await userEvent.type(within(dialog).getByLabelText('Comment (optional)'), 'Great work');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(JSON.parse(callsTo(fetchMock, 'POST', '/api/tasks/42/approve')[0][1].body)).toEqual({
      note: 'Great work',
    });
    expect(await screen.findByText('Task approved.')).toBeVisible();
  });

  it('sends no note when the field is left empty', async () => {
    const { fetchMock } = renderTask(underReview, {
      table: { [URL]: mockResponse(200, makeTaskDetail()) },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
    await waitFor(() =>
      expect(callsTo(fetchMock, 'POST', '/api/tasks/42/approve')).toHaveLength(1),
    );
    expect(bodyOf(fetchMock, 'POST', '/api/tasks/42/approve')).toEqual({});
  });

  it('sends one request for a double click on the confirm button', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderTask(underReview, {
      table: {
        [URL]: () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.dblClick(within(dialog).getByRole('button', { name: 'Approve' }));
    await waitFor(() =>
      expect(callsTo(fetchMock, 'POST', '/api/tasks/42/approve')).toHaveLength(1),
    );
    expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeDisabled();
    await act(async () => release(mockResponse(200, makeTaskDetail())));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(callsTo(fetchMock, 'POST', '/api/tasks/42/approve')).toHaveLength(1);
  });

  it('refreshes the task, the boards and the dashboards, and the history when it is open', async () => {
    const invalidate = jest.spyOn(QueryClient.prototype, 'invalidateQueries');
    const { fetchMock } = renderTask(underReview, {
      table: {
        [URL]: mockResponse(200, makeTaskDetail()),
        'GET /api/tasks/42/history': mockResponse(200, { events: [makeEvent('task_created')] }),
      },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Activity history' }));
    await screen.findByText(/created the task/);
    expect(callsTo(fetchMock, 'GET', '/api/tasks/42/history')).toHaveLength(1);

    await userEvent.click(screen.getByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    for (const queryKey of [['task', 42], ['board'], ['dashboard']]) {
      expect(invalidate).toHaveBeenCalledWith({ queryKey });
    }
    await waitFor(() => expect(callsTo(fetchMock, 'GET', '/api/tasks/42/history')).toHaveLength(2));
    expect(taskCalls(fetchMock)).toHaveLength(2);
  });

  it.each([
    ['invalid_transition', 409, /not possible for the task in its current status/],
    ['forbidden', 403, /do not have permission/],
    ['not_found', 404, /Not found/],
  ])(
    'closes the dialog, shows a toast and refetches the task on %s',
    async (code, status, message) => {
      const { fetchMock } = renderTask(underReview, {
        table: { [URL]: errorResponse(status, code) },
      });
      await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
      const dialog = await screen.findByRole('dialog');
      await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));

      expect(await screen.findByText(message)).toBeVisible();
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
      await waitFor(() => expect(taskCalls(fetchMock)).toHaveLength(2));
    },
  );

  it('keeps the dialog open and the note after a server failure', async () => {
    const { fetchMock } = renderTask(underReview, {
      table: { [URL]: errorResponse(500, 'internal_error') },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText('Comment (optional)'), 'Great work');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Something went wrong on our side. Please try again later.',
    );
    expect(within(dialog).getByLabelText('Comment (optional)')).toHaveValue('Great work');
    expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeEnabled();
    // The task is asked for again after a failure too.
    await waitFor(() => expect(taskCalls(fetchMock)).toHaveLength(2));
  });
});

describe('request revisions', () => {
  it('opens the dialog, requires a reason and sends it', async () => {
    const { fetchMock } = renderTask(
      {
        status: 'under_review',
        assignee: ANNA,
        rounds: [OPEN_ROUND],
        allowedActions: ['approve', 'request_revisions'],
      },
      { table: { 'POST /api/tasks/42/request-revisions': mockResponse(200, makeTaskDetail()) } },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Request revisions' }));
    const dialog = await screen.findByRole('dialog', { name: 'Request revisions' });
    await userEvent.click(within(dialog).getByRole('button', { name: 'Request revisions' }));
    expect(await within(dialog).findByText('This field is required.')).toBeInTheDocument();
    await userEvent.type(within(dialog).getByLabelText(/^Reason for revisions/), 'Bigger logo');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Request revisions' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(bodyOf(fetchMock, 'POST', '/api/tasks/42/request-revisions')).toEqual({
      reason: 'Bigger logo',
    });
  });
});

describe('cancel', () => {
  const URL = 'POST /api/tasks/42/cancel';

  it('asks for confirmation, with an optional reason', async () => {
    const { fetchMock } = renderTask(
      { status: 'new', allowedActions: ['cancel'] },
      { table: { [URL]: mockResponse(200, makeTaskDetail({ status: 'cancelled' })) } },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel task' }));
    const dialog = await screen.findByRole('dialog', { name: 'Cancel this task?' });
    await userEvent.type(within(dialog).getByLabelText('Reason (optional)'), 'Offer paused');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel task' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(bodyOf(fetchMock, 'POST', '/api/tasks/42/cancel')).toEqual({ reason: 'Offer paused' });
    expect(await screen.findByText('Task cancelled.')).toBeVisible();
  });

  it('can be dismissed without a request', async () => {
    const { fetchMock } = renderTask({ status: 'new', allowedActions: ['cancel'] });
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel task' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Keep the task' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(callsTo(fetchMock, 'POST', '/api/tasks/42/cancel')).toHaveLength(0);
  });

  it('refuses a reason of more than 2000 characters', async () => {
    const { fetchMock } = renderTask({ status: 'new', allowedActions: ['cancel'] });
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel task' }));
    const dialog = await screen.findByRole('dialog');
    await paste(within(dialog).getByLabelText('Reason (optional)'), 'x'.repeat(2001));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel task' }));
    expect(await within(dialog).findByText('Must be at most 2000 characters')).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', '/api/tasks/42/cancel')).toHaveLength(0);
  });
});

describe('release', () => {
  const URL = 'POST /api/tasks/42/release';
  const inProgress = {
    status: /** @type {const} */ ('in_progress'),
    assignee: ANNA,
    allowedActions: ['release', 'submit'],
  };

  it('explains that the rounds and files stay, then releases', async () => {
    const { fetchMock } = renderTask(inProgress, {
      me: DESIGNER(),
      table: { [URL]: mockResponse(200, makeTaskDetail({ status: 'new' })) },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Release task' }));
    const dialog = await screen.findByRole('dialog', { name: 'Release this task?' });
    expect(dialog).toHaveTextContent('returns to the pool');
    expect(dialog).toHaveTextContent('Your rounds and files stay with the task.');
    expect(within(dialog).queryByRole('textbox')).not.toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Release task' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    const [, init] = callsTo(fetchMock, 'POST', '/api/tasks/42/release')[0];
    expect(init.body).toBeUndefined();
  });

  it('sends one request for a double click', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderTask(inProgress, {
      me: DESIGNER(),
      table: {
        [URL]: () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Release task' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.dblClick(within(dialog).getByRole('button', { name: 'Release task' }));
    await waitFor(() =>
      expect(callsTo(fetchMock, 'POST', '/api/tasks/42/release')).toHaveLength(1),
    );
    await act(async () => release(mockResponse(200, makeTaskDetail())));
    expect(callsTo(fetchMock, 'POST', '/api/tasks/42/release')).toHaveLength(1);
  });
});

describe('server problems with the text of a dialog', () => {
  const invalidText = (/** @type {string} */ field) =>
    errorResponse(422, 'validation_failed', {
      fields: [{ field, code: 'too_long', params: { max: 2000 } }],
    });
  const TOO_LONG = 'Must be at most 2000 characters';

  it.each([
    ['Approve', 'approve', 'note', 'Comment (optional)', 'Approve'],
    ['Cancel task', 'cancel', 'reason', 'Reason (optional)', 'Cancel task'],
  ])(
    'shows a 422 on the text of %s next to the textarea until it is edited',
    async (button, action, field, label, confirm) => {
      renderTask(
        { status: 'under_review', assignee: ANNA, rounds: [OPEN_ROUND], allowedActions: [action] },
        { table: { [`POST /api/tasks/42/${action}`]: invalidText(field) } },
      );
      await userEvent.click(await screen.findByRole('button', { name: button }));
      const dialog = await screen.findByRole('dialog');
      const text = within(dialog).getByLabelText(label);
      await userEvent.type(text, 'Some text');
      await userEvent.click(within(dialog).getByRole('button', { name: confirm }));

      expect(await within(dialog).findByText(TOO_LONG)).toBeInTheDocument();
      expect(text).toBeInvalid();
      expect(screen.getByRole('dialog')).toBeInTheDocument();

      await userEvent.type(text, '!');
      expect(within(dialog).queryByText(TOO_LONG)).not.toBeInTheDocument();
      expect(text).toBeValid();
    },
  );

  it('shows the error again when the same text is sent again', async () => {
    renderTask(
      { status: 'under_review', assignee: ANNA, rounds: [OPEN_ROUND], allowedActions: ['approve'] },
      { table: { 'POST /api/tasks/42/approve': invalidText('note') } },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText('Comment (optional)'), 'Some text');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
    expect(await within(dialog).findByText(TOO_LONG)).toBeInTheDocument();
    await userEvent.type(within(dialog).getByLabelText('Comment (optional)'), '!');
    expect(within(dialog).queryByText(TOO_LONG)).not.toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
    expect(await within(dialog).findByText(TOO_LONG)).toBeInTheDocument();
  });

  it('shows a 422 on the reason of Request revisions until it is edited', async () => {
    renderTask(
      {
        status: 'under_review',
        assignee: ANNA,
        rounds: [OPEN_ROUND],
        allowedActions: ['request_revisions'],
      },
      { table: { 'POST /api/tasks/42/request-revisions': invalidText('reason') } },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Request revisions' }));
    const dialog = await screen.findByRole('dialog');
    const reason = within(dialog).getByLabelText(/^Reason for revisions/);
    await userEvent.type(reason, 'Bigger logo');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Request revisions' }));
    expect(await within(dialog).findByText(TOO_LONG)).toBeInTheDocument();
    expect(reason).toBeInvalid();
    await userEvent.type(reason, '!');
    await waitFor(() => expect(within(dialog).queryByText(TOO_LONG)).not.toBeInTheDocument());
  });
});

describe('another task opened while a dialog is open', () => {
  it.each([
    [
      'Submit work',
      DESIGNER,
      { status: 'in_progress', assignee: ANNA, allowedActions: ['submit'] },
      'POST /api/tasks/5/rounds',
    ],
    [
      'Request revisions',
      BUYER,
      {
        status: 'under_review',
        assignee: ANNA,
        rounds: [OPEN_ROUND],
        allowedActions: ['request_revisions'],
      },
      'POST /api/tasks/5/request-revisions',
    ],
  ])(
    'drops the open %s dialog and sends nothing to the new task',
    async (button, me, task, post) => {
      const detail = (/** @type {number} */ id) =>
        mockResponse(200, makeTaskDetail({ .../** @type {any} */ (task), id }));
      const { router, fetchMock } = renderApp('/tasks/5', {
        me: me(),
        table: {
          'GET /api/tasks/5': detail(5),
          'GET /api/tasks/42': detail(42),
          'GET /api/ai-providers': mockResponse(200, { providers: [] }),
          [post]: mockResponse(200, {}),
          [post.replace('/5/', '/42/')]: mockResponse(200, {}),
        },
      });
      // Task 5 is loaded (and so cached) first.
      await screen.findByRole('button', { name: button });
      await act(async () => {
        await router.navigate('/tasks/42');
      });
      await userEvent.click(await screen.findByRole('button', { name: button }));
      const dialog = await screen.findByRole('dialog');
      const field = within(dialog).getByRole('textbox', {
        name: /^(Note for the buyer|Reason for revisions)/,
      });
      await userEvent.type(field, 'Text for task 42');

      // Back to task 5, whose data is cached: the page shows it at once.
      await act(async () => {
        await router.navigate('/tasks/5');
      });
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
      expect(screen.getByRole('button', { name: button })).toBeInTheDocument();
      expect(
        fetchMock.mock.calls.filter(
          ([url]) => url.startsWith('/api/tasks/5/') && url !== '/api/tasks/5/history',
        ),
      ).toHaveLength(0);

      // The new task's dialog starts empty.
      await userEvent.click(screen.getByRole('button', { name: button }));
      const fresh = await screen.findByRole('dialog');
      expect(
        within(fresh).getByRole('textbox', { name: /^(Note for the buyer|Reason for revisions)/ }),
      ).toHaveValue('');
    },
  );
});

describe('keyboard', () => {
  it('closes a dialog with Escape and gives the focus back to its button', async () => {
    renderTask({ status: 'new', allowedActions: ['cancel'] });
    const button = await screen.findByRole('button', { name: 'Cancel task' });
    await userEvent.click(button);
    await screen.findByRole('dialog');
    await userEvent.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    await waitFor(() => expect(button).toHaveFocus());
  });

  it('opens a dialog from the keyboard', async () => {
    renderTask({ status: 'new', allowedActions: ['cancel'] });
    const button = await screen.findByRole('button', { name: 'Cancel task' });
    button.focus();
    await userEvent.keyboard('{Enter}');
    expect(await screen.findByRole('dialog', { name: 'Cancel this task?' })).toBeInTheDocument();
  });
});

describe('submit work', () => {
  it('opens the submit dialog', async () => {
    renderTask(
      { status: 'in_progress', assignee: ANNA, allowedActions: ['submit'] },
      { me: DESIGNER() },
    );
    await userEvent.click(await screen.findByRole('button', { name: 'Submit work' }));
    expect(await screen.findByRole('dialog', { name: 'Submit work' })).toBeInTheDocument();
  });
});

describe('edit AI usage', () => {
  const URL = 'PUT /api/tasks/42/rounds/2/ai-usage';
  const waiting = {
    status: /** @type {const} */ ('under_review'),
    assignee: ANNA,
    rounds: [DECIDED_ROUND, OPEN_ROUND],
    allowedActions: ['report_ai_usage'],
  };

  it('shows the lines of the latest round and replaces the whole set', async () => {
    const { fetchMock } = renderTask(waiting, {
      me: DESIGNER(),
      table: { [URL]: mockResponse(200, makeTaskDetail()) },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Edit AI usage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit AI usage' });
    expect(within(dialog).getByRole('combobox', { name: 'Provider 1' })).toHaveValue('Runway');
    expect(within(dialog).getByLabelText('Credits 1')).toHaveValue('60.00');

    await userEvent.clear(within(dialog).getByLabelText('Credits 1'));
    await userEvent.type(within(dialog).getByLabelText('Credits 1'), '75.5');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add line' }));
    await userEvent.type(within(dialog).getByRole('combobox', { name: 'Provider 2' }), 'Sora');
    await userEvent.type(within(dialog).getByLabelText('Credits 2'), '3');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(bodyOf(fetchMock, 'PUT', '/api/tasks/42/rounds/2/ai-usage')).toEqual({
      lines: [
        { provider: 'Runway', credits: '75.5' },
        { provider: 'Sora', credits: '3' },
      ],
    });
    expect(await screen.findByText('AI usage saved.')).toBeVisible();
  });

  it('accepts an unchanged set and an empty one', async () => {
    const { fetchMock } = renderTask(waiting, {
      me: DESIGNER(),
      table: { [URL]: mockResponse(200, makeTaskDetail()) },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Edit AI usage' }));
    let dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(bodyOf(fetchMock, 'PUT', '/api/tasks/42/rounds/2/ai-usage')).toEqual({
      lines: [{ provider: 'Runway', credits: '60.00' }],
    });

    await userEvent.click(await screen.findByRole('button', { name: 'Edit AI usage' }));
    dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Remove line 1' }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(bodyOf(fetchMock, 'PUT', '/api/tasks/42/rounds/2/ai-usage', 1)).toEqual({ lines: [] });
  });

  it('maps a server error of a line onto the row', async () => {
    renderTask(waiting, {
      me: DESIGNER(),
      table: {
        [URL]: errorResponse(422, 'validation_failed', {
          fields: [{ field: 'lines.0.credits', code: 'invalid_amount', params: {} }],
        }),
      },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Edit AI usage' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(
      await within(dialog).findByText(
        'Enter a number of zero or more, with up to two decimal places.',
      ),
    ).toBeInTheDocument();
    expect(within(dialog).getByLabelText('Credits 1')).toBeInvalid();
  });

  it('closes with a toast and refetches when the round has been decided meanwhile', async () => {
    const { fetchMock } = renderTask(waiting, {
      me: DESIGNER(),
      table: { [URL]: errorResponse(409, 'invalid_transition') },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Edit AI usage' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(
      await screen.findByText(/not possible for the task in its current status/),
    ).toBeVisible();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    await waitFor(() => expect(taskCalls(fetchMock)).toHaveLength(2));
  });
});
