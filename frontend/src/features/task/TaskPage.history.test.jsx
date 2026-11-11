import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { formatDateTime } from '../../i18n/format';
import { errorResponse, makeMe, mockResponse } from '../../test/http';
import { renderApp } from '../../test/renderApp';
import { ANNA, DMITRY, EVENT_FIXTURES, makeEvent, makeTaskDetail } from '../../test/taskDetail';

beforeEach(() => {
  setCsrfToken(null);
});

const historyCalls = (/** @type {{mock: {calls: any[][]}}} */ fetchMock) =>
  fetchMock.mock.calls.filter(([url]) => url === '/api/tasks/42/history');

/** @param {ReturnType<typeof mockResponse>} [history] the answer of the history endpoint */
function renderTask(history = mockResponse(200, { events: EVENT_FIXTURES }), me = makeMe()) {
  return renderApp('/tasks/42', {
    me,
    table: {
      'GET /api/tasks/42': mockResponse(200, makeTaskDetail()),
      'GET /api/tasks/42/history': history,
    },
  });
}

const toggle = () => screen.findByRole('button', { name: 'Activity history' });

describe('activity history', () => {
  it('is collapsed and not requested until it is expanded', async () => {
    const { fetchMock } = renderTask();
    const button = await toggle();
    expect(button).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('list', { name: 'Activity history' })).not.toBeInTheDocument();
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/tasks/42', expect.anything()));
    expect(historyCalls(fetchMock)).toHaveLength(0);
  });

  it('requests the events once on first expand and shows them oldest first', async () => {
    const events = [
      makeEvent('task_created', {}, { id: 1, actor: DMITRY, at: '2026-11-09T08:00:00.000Z' }),
      makeEvent('task_picked_up', { assignmentId: 1 }, { id: 2, at: '2026-11-09T09:10:00.000Z' }),
      makeEvent(
        'round_submitted',
        { round: 1, fileCount: 2 },
        { id: 3, at: '2026-11-10T09:00:00.000Z' },
      ),
    ];
    const { fetchMock } = renderTask(mockResponse(200, { events }));
    const button = await toggle();
    await userEvent.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'true');

    const timeline = await screen.findByRole('list', { name: 'Activity history' });
    const items = within(timeline).getAllByRole('listitem');
    expect(
      items.map((item) => item.querySelector('[data-testid="event-text"]')?.textContent),
    ).toEqual([
      'Dmitry P. created the task',
      'Anna K. picked up the task',
      'Anna K. submitted round 1 (2 files)',
    ]);
    expect(items[0]).toHaveTextContent(formatDateTime('2026-11-09T08:00:00.000Z', 'en'));
    expect(items[2]).toHaveTextContent(formatDateTime('2026-11-10T09:00:00.000Z', 'en'));
    expect(historyCalls(fetchMock)).toHaveLength(1);

    // Collapsing and expanding again does not ask again.
    await userEvent.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'false');
    await userEvent.click(button);
    expect(button).toHaveAttribute('aria-expanded', 'true');
    expect(historyCalls(fetchMock)).toHaveLength(1);
  });

  it('shows every one of the nine event types as a translated sentence', async () => {
    renderTask();
    await userEvent.click(await toggle());
    const timeline = await screen.findByRole('list', { name: 'Activity history' });
    const items = [...timeline.querySelectorAll('[data-event-type]')];
    expect(items).toHaveLength(9);
    for (const item of items) {
      expect(item.textContent).not.toMatch(/\b(task|common):\w|events\.|\{\{/);
      expect(item).toHaveTextContent('Anna K.');
    }
  });

  it('shows old → new for every change of an edited brief', async () => {
    renderTask(
      mockResponse(200, {
        events: [
          makeEvent(
            'brief_edited',
            {
              changes: [
                { field: 'geo', old: ['DE'], new: ['AT', 'DE'] },
                { field: 'quantity', old: 3, new: 5 },
                { field: 'description', old: 'old text', new: 'x'.repeat(120) },
              ],
            },
            { actor: DMITRY },
          ),
        ],
      }),
    );
    await userEvent.click(await toggle());
    const timeline = await screen.findByRole('list', { name: 'Activity history' });
    expect(within(timeline).getByTestId('event-text')).toHaveTextContent(
      'Dmitry P. edited the brief',
    );
    expect(within(timeline).getByText('GEO: Germany → Austria, Germany')).toBeInTheDocument();
    expect(within(timeline).getByText('Quantity: 3 → 5')).toBeInTheDocument();
    expect(
      within(timeline).getByText(`Description: old text → ${'x'.repeat(80)}…`),
    ).toBeInTheDocument();
  });

  it('renders user text of events as text, never markup', async () => {
    const hostile = '<script>alert(1)</script> 🎰 Лото';
    renderTask(
      mockResponse(200, {
        events: [
          makeEvent('revisions_requested', { round: 1, reason: hostile }),
          makeEvent('task_cancelled', { reason: hostile }),
          makeEvent('task_created', {}, { actor: { id: 9, displayName: hostile } }),
          makeEvent('brief_edited', {
            changes: [{ field: 'description', old: hostile, new: '<img src=x onerror=alert(1)>' }],
          }),
          makeEvent('ai_usage_changed', {
            round: 1,
            lines: [{ provider: hostile, credits: '1.00' }],
          }),
        ],
      }),
    );
    await userEvent.click(await toggle());
    const timeline = await screen.findByRole('list', { name: 'Activity history' });
    expect(timeline.querySelector('script, img')).toBeNull();
    expect(document.querySelector('script')).toBeNull();
    expect(timeline).toHaveTextContent(`Anna K. requested revisions on round 1: ${hostile}`);
    expect(timeline).toHaveTextContent(`Anna K. cancelled the task: ${hostile}`);
    expect(timeline).toHaveTextContent(`${hostile} created the task`);
    expect(timeline).toHaveTextContent(`Description: ${hostile} → <img src=x onerror=alert(1)>`);
    expect(timeline).toHaveTextContent(`${hostile} 1.00`);
  });

  it('shows an unknown event type as a generic line, not its key', async () => {
    renderTask(mockResponse(200, { events: [makeEvent('task_teleported')] }));
    await userEvent.click(await toggle());
    const timeline = await screen.findByRole('list', { name: 'Activity history' });
    expect(timeline).toHaveTextContent('Anna K.');
    expect(timeline).not.toHaveTextContent('task_teleported');
  });

  it('says so when there is no activity', async () => {
    renderTask(mockResponse(200, { events: [] }));
    await userEvent.click(await toggle());
    expect(await screen.findByText('No activity yet.')).toBeInTheDocument();
  });

  it('shows a translated error with Retry when the history cannot be loaded', async () => {
    let calls = 0;
    const { fetchMock } = renderApp('/tasks/42', {
      table: {
        'GET /api/tasks/42': mockResponse(200, makeTaskDetail()),
        'GET /api/tasks/42/history': () => {
          calls += 1;
          return calls === 1
            ? errorResponse(403, 'forbidden')
            : mockResponse(200, { events: [makeEvent('task_created')] });
        },
      },
    });
    await userEvent.click(await toggle());
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Could not load the activity history');
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Anna K. created the task')).toBeInTheDocument();
    expect(historyCalls(fetchMock)).toHaveLength(2);
  });

  it('shows the timeline in Russian', async () => {
    renderTask(
      mockResponse(200, { events: [makeEvent('task_created', {}, { actor: ANNA })] }),
      makeMe({ locale: 'ru' }),
    );
    await userEvent.click(await screen.findByRole('button', { name: 'История активности' }));
    expect(await screen.findByText('Anna K.: задача создана')).toBeInTheDocument();
  });
});
