import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { BOARD_PATH, boardResponse, makeTask } from '../../test/board';
import { callsTo, chooseOption, openOptions, optionNames } from '../../test/createTaskForm';
import { errorResponse, makeMe, mockResponse } from '../../test/http';
import { makeOptions, renderApp } from '../../test/renderApp';

const OPTIONS_OK = { 'GET /api/options': mockResponse(200, makeOptions()) };

const anna = { id: 3, displayName: 'Anna K.' };
const igor = { id: 7, displayName: 'Igor S.' };

/** A small board in every status, as a buyer sees it. */
const TASKS = [
  makeTask({ id: 1, offer: 'Vulkan Bet', geo: ['PL'], quantity: 10, priority: 'urgent' }),
  makeTask({ id: 2, offer: 'Crypto Wave', geo: ['BR'], quantity: 2, isReturning: true }),
  makeTask({
    id: 3,
    offer: 'Лото Клуб',
    status: 'in_progress',
    assignee: anna,
    roundCount: 1,
    isOverdue: true,
    deadlineAt: '2020-01-01T00:00:00Z',
    priority: 'high',
  }),
  makeTask({
    id: 4,
    offer: 'Casino Royal',
    status: 'under_review',
    assignee: anna,
    roundCount: 2,
    creditsTotal: '220.00',
    allowedActions: ['approve', 'request_revisions', 'cancel'],
  }),
  makeTask({ id: 5, offer: 'Lucky Spin', status: 'completed', assignee: igor, roundCount: 2 }),
  makeTask({ id: 6, offer: 'Old Offer', status: 'cancelled', assignee: igor }),
  makeTask({ id: 7, offer: 'Dead Offer', status: 'cancelled' }),
];

/**
 * @param {string} [path]
 * @param {{me?: ReturnType<typeof makeMe>, tasks?: ReturnType<typeof makeTask>[], table?: Record<string, any>}} [options]
 */
function renderBoard(path = '/board', { me, tasks = TASKS, table = {} } = {}) {
  return renderApp(path, {
    me,
    table: { ...OPTIONS_OK, [`GET ${BOARD_PATH}`]: boardResponse(tasks), ...table },
  });
}

const designer = () => makeMe({ id: 3, role: 'designer', displayName: 'Anna K.' });

/** @param {ReturnType<typeof jest.fn>} fetchMock */
const boardUrls = (fetchMock) =>
  fetchMock.mock.calls.map(([url]) => url).filter((url) => url.startsWith('/api/tasks?'));

const column = (/** @type {string | RegExp} */ name) => screen.findByRole('region', { name });

beforeEach(() => {
  setCsrfToken(null);
});
afterEach(() => {
  jest.useRealTimers();
});

describe('layout', () => {
  it('renders exactly five columns, in order, and no cancelled column', async () => {
    renderBoard();
    await column('New 2');
    const headings = screen
      .getAllByRole('region')
      .map((region) => within(region).getByRole('heading', { level: 2 }).textContent);
    expect(headings).toEqual([
      'New 2',
      'In progress 1',
      'Under review 1',
      'Revisions required 0',
      'Completed 1',
    ]);
    expect(screen.queryByRole('heading', { name: /cancelled/i })).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Board' })).toBeInTheDocument();
  });

  it('puts every card into the column of its status', async () => {
    renderBoard();
    const newColumn = await column('New 2');
    expect(within(newColumn).getByText('Vulkan Bet')).toBeInTheDocument();
    expect(within(newColumn).getByText('Crypto Wave')).toBeInTheDocument();
    expect(within(await column('In progress 1')).getByText('Лото Клуб')).toBeInTheDocument();
    expect(within(await column('Under review 1')).getByText('Casino Royal')).toBeInTheDocument();
    expect(within(await column('Completed 1')).getByText('Lucky Spin')).toBeInTheDocument();
    expect(screen.queryByText('Old Offer')).not.toBeInTheDocument();
  });

  it('shows a translated empty text in a column without tasks', async () => {
    renderBoard();
    const empty = await column('Revisions required 0');
    expect(within(empty).getByText('No tasks')).toBeInTheDocument();
    expect(within(await column('New 2')).queryByText('No tasks')).not.toBeInTheDocument();
  });

  it('shows skeleton cards while the board loads', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderBoard('/board', {
      table: {
        [`GET ${BOARD_PATH}`]: () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    expect((await screen.findAllByTestId('card-skeleton')).length).toBeGreaterThan(0);
    await waitFor(() => expect(boardUrls(fetchMock)).toHaveLength(1));
    expect(screen.getByRole('heading', { level: 1, name: 'Board' })).toBeInTheDocument();
    await act(async () => release(boardResponse(TASKS)));
    await column('New 2');
    expect(screen.queryByTestId('card-skeleton')).not.toBeInTheDocument();
  });

  it('shows the board in Russian', async () => {
    renderBoard('/board', { me: makeMe({ locale: 'ru' }) });
    await column('Новые 2');
    for (const name of ['В работе 1', 'На проверке 1', 'Нужны правки 0', 'Готово 1']) {
      expect(screen.getByRole('region', { name })).toBeInTheDocument();
    }
    expect(screen.getByRole('heading', { level: 1, name: 'Доска' })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Показать отменённые (2)' })).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Поиск по офферу' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Приоритет' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Тип креатива' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Дизайнер' })).toBeInTheDocument();
    expect(screen.getByText('Нужна ваша проверка')).toBeInTheDocument();
    expect(screen.getByText('Ждёт правок (освобождена)')).toBeInTheDocument();
    expect(
      within(screen.getByRole('region', { name: 'Нужны правки 0' })).getByText('Нет задач'),
    ).toBeVisible();
  });
});

describe('cards on the board', () => {
  it('shows the card details the wireframe has', async () => {
    renderBoard();
    const review = await column('Under review 1');
    expect(within(review).getByText('Casino Royal')).toBeInTheDocument();
    expect(review).toHaveTextContent('Anna K.');
    expect(review).toHaveTextContent('round 2 · 220.00 cr');
    expect(review).toHaveTextContent('Needs your review');
    expect(within(await column('New 2')).getByText('Revision pending (released)')).toBeVisible();
  });

  it('marks an overdue card', async () => {
    renderBoard();
    const progress = await column('In progress 1');
    expect(within(progress).getByTestId('task-card-deadline')).toHaveAttribute(
      'data-overdue',
      'true',
    );
  });

  it('links every card to its task page', async () => {
    renderBoard();
    await column('New 2');
    expect(screen.getByRole('link', { name: 'Vulkan Bet' })).toHaveAttribute('href', '/tasks/1');
    expect(screen.getByRole('link', { name: 'Лото Клуб' })).toHaveAttribute('href', '/tasks/3');
  });

  it('opens the task page from the keyboard', async () => {
    const { router } = renderBoard();
    await column('New 2');
    screen.getByRole('link', { name: 'Vulkan Bet' }).focus();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => expect(router.state.location.pathname).toBe('/tasks/1'));
  });
});

describe('cancelled tasks', () => {
  it('always asks for them, but lists them only after the toggle, with their count in its label', async () => {
    const { fetchMock } = renderBoard();
    await column('New 2');
    expect(boardUrls(fetchMock)).toEqual(['/api/tasks?includeCancelled=1']);
    const toggle = screen.getByRole('checkbox', { name: 'Show cancelled (2)' });
    expect(toggle).not.toBeChecked();
    expect(screen.queryByRole('region', { name: /^Cancelled/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Old Offer')).not.toBeInTheDocument();

    await userEvent.click(toggle);

    const cancelled = screen.getByRole('region', { name: 'Cancelled 2' });
    expect(within(cancelled).getByText('Old Offer')).toBeInTheDocument();
    expect(within(cancelled).getByText('Dead Offer')).toBeInTheDocument();
    expect(within(cancelled).getAllByTestId('task-card-status')).toHaveLength(2);
    // Showing them needs no new request, and they are still no column.
    expect(boardUrls(fetchMock)).toEqual(['/api/tasks?includeCancelled=1']);
    expect(screen.getAllByRole('region')).toHaveLength(6);

    await userEvent.click(toggle);
    expect(screen.queryByRole('region', { name: /^Cancelled/ })).not.toBeInTheDocument();
  });

  it('shows a zero count and an empty text when nothing was cancelled', async () => {
    renderBoard('/board', { tasks: TASKS.slice(0, 5) });
    await column('New 2');
    await userEvent.click(screen.getByRole('checkbox', { name: 'Show cancelled (0)' }));
    expect(
      within(screen.getByRole('region', { name: 'Cancelled 0' })).getByText('No cancelled tasks'),
    ).toBeVisible();
  });

  it('never lets a cancelled card be dragged or picked up', async () => {
    renderBoard('/board', {
      me: designer(),
      tasks: [makeTask({ id: 9, offer: 'Odd', status: 'cancelled', allowedActions: ['pick_up'] })],
    });
    await column('New 0');
    await userEvent.click(screen.getByRole('checkbox', { name: 'Show cancelled (1)' }));
    expect(screen.queryByRole('button', { name: /drag/i })).not.toBeInTheDocument();
  });
});

describe('filters', () => {
  const tableFor = (/** @type {string} */ query, tasks = TASKS) => ({
    [`GET /api/tasks?${query}includeCancelled=1`]: boardResponse(tasks),
  });

  it('sends the priority, mirrors it in the URL and shows the narrowed board', async () => {
    const { fetchMock, router } = renderBoard('/board', {
      table: tableFor('priority=high&', [TASKS[2]]),
    });
    await column('New 2');
    await chooseOption(/^Priority/, 'High');
    await waitFor(() =>
      expect(boardUrls(fetchMock)).toContain('/api/tasks?priority=high&includeCancelled=1'),
    );
    await column('New 0');
    expect(router.state.location.search).toBe('?priority=high');
    expect(screen.getByRole('combobox', { name: 'Priority' })).toHaveValue('High');
  });

  it('offers the priorities and creative types of the options, translated', async () => {
    renderBoard();
    await column('New 2');
    expect(optionNames(await openOptions(/^Priority/))).toEqual([
      'Low',
      'Normal',
      'High',
      'Urgent',
    ]);
    await userEvent.keyboard('{Escape}');
    expect(optionNames(await openOptions(/^Creative type/))).toEqual([
      'Static image',
      'Video',
      'Carousel',
      'Animated GIF',
      'HTML5 banner',
      'Other',
    ]);
  });

  it('sends the creative type', async () => {
    const { fetchMock, router } = renderBoard('/board', {
      table: tableFor('creativeType=video&', []),
    });
    await column('New 2');
    await chooseOption(/^Creative type/, 'Video');
    await waitFor(() =>
      expect(boardUrls(fetchMock)).toContain('/api/tasks?creativeType=video&includeCancelled=1'),
    );
    expect(router.state.location.search).toBe('?creativeType=video');
  });

  it('lists the assignees of the loaded tasks as designers and sends the chosen id', async () => {
    const { fetchMock, router } = renderBoard('/board', {
      table: tableFor('designerId=3&', [TASKS[2], TASKS[3]]),
    });
    await column('New 2');
    expect(optionNames(await openOptions(/^Designer/))).toEqual(['Anna K.', 'Igor S.']);
    await userEvent.click(screen.getByRole('option', { name: 'Anna K.', hidden: true }));
    await waitFor(() =>
      expect(boardUrls(fetchMock)).toContain('/api/tasks?designerId=3&includeCancelled=1'),
    );
    expect(router.state.location.search).toBe('?designerId=3');
    // The other designer stays selectable although the narrowed board no longer shows them.
    await column('New 0');
    expect(optionNames(await openOptions(/^Designer/))).toEqual(['Anna K.', 'Igor S.']);
  });

  it('debounces the search by 300 ms before asking and before writing the URL', async () => {
    const { fetchMock, router } = renderBoard('/board', {
      table: tableFor('q=vulkan&', [TASKS[0]]),
    });
    await column('New 2');
    jest.useFakeTimers();
    const user = userEvent.setup({ advanceTimers: jest.advanceTimersByTime });
    await user.type(screen.getByRole('textbox', { name: 'Search offer' }), 'vulkan');
    act(() => {
      jest.advanceTimersByTime(299);
    });
    expect(boardUrls(fetchMock)).toEqual(['/api/tasks?includeCancelled=1']);
    expect(router.state.location.search).toBe('');
    act(() => {
      jest.advanceTimersByTime(1);
    });
    jest.useRealTimers();
    await waitFor(() =>
      expect(boardUrls(fetchMock)).toEqual([
        '/api/tasks?includeCancelled=1',
        '/api/tasks?q=vulkan&includeCancelled=1',
      ]),
    );
    expect(router.state.location.search).toBe('?q=vulkan');
    await column('New 1');
  });

  it('drops a filter again when its chosen option is chosen once more', async () => {
    const { router } = renderBoard('/board?priority=high', {
      table: tableFor('priority=high&', [TASKS[2]]),
    });
    await column('New 0');
    await chooseOption(/^Priority/, 'High');
    await column('New 2');
    expect(router.state.location.search).toBe('');
  });

  it('clears every filter, the search box included, with one button', async () => {
    const { router, fetchMock } = renderBoard('/board?priority=high&designerId=3&q=Casino', {
      table: tableFor('priority=high&designerId=3&q=Casino&', []),
    });
    await column('New 0');
    expect(screen.getByRole('textbox', { name: 'Search offer' })).toHaveValue('Casino');
    await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    await column('New 2');
    expect(router.state.location.search).toBe('');
    expect(screen.getByRole('textbox', { name: 'Search offer' })).toHaveValue('');
    expect(boardUrls(fetchMock).at(-1)).toBe('/api/tasks?includeCancelled=1');
  });

  it('offers no clear button while nothing is filtered', async () => {
    renderBoard();
    await column('New 2');
    expect(screen.queryByRole('button', { name: 'Clear filters' })).not.toBeInTheDocument();
  });

  it('keeps the other search parameters when a filter changes', async () => {
    const { router } = renderBoard('/board?foo=bar', { table: tableFor('priority=high&', []) });
    await column('New 2');
    await chooseOption(/^Priority/, 'High');
    await waitFor(() => expect(router.state.location.search).toBe('?foo=bar&priority=high'));
  });
});

describe('filters from the URL', () => {
  it('pre-populates the controls and the first request from designerId and priority', async () => {
    const { fetchMock } = renderBoard('/board?designerId=7&priority=high', {
      table: {
        'GET /api/tasks?priority=high&designerId=7&includeCancelled=1': boardResponse([TASKS[4]]),
      },
    });
    await column('Completed 1');
    expect(boardUrls(fetchMock)).toEqual([
      '/api/tasks?priority=high&designerId=7&includeCancelled=1',
    ]);
    expect(screen.getByRole('combobox', { name: 'Priority' })).toHaveValue('High');
    expect(screen.getByRole('combobox', { name: 'Designer' })).toHaveValue('Igor S.');
  });

  it('reads creativeType and q as well', async () => {
    const { fetchMock } = renderBoard('/board?creativeType=video&q=Casino', {
      table: { 'GET /api/tasks?creativeType=video&q=Casino&includeCancelled=1': boardResponse([]) },
    });
    await column('New 0');
    expect(boardUrls(fetchMock)).toEqual([
      '/api/tasks?creativeType=video&q=Casino&includeCancelled=1',
    ]);
    expect(screen.getByRole('combobox', { name: 'Creative type' })).toHaveValue('Video');
    expect(screen.getByRole('textbox', { name: 'Search offer' })).toHaveValue('Casino');
  });

  it('ignores invalid and unknown values', async () => {
    const { fetchMock } = renderBoard(
      '/board?priority=asap&creativeType=hologram&designerId=0&q=%20%20',
    );
    await column('New 2');
    expect(boardUrls(fetchMock)).toEqual(['/api/tasks?includeCancelled=1']);
    expect(screen.getByRole('combobox', { name: 'Priority' })).toHaveValue('');
    expect(screen.getByRole('textbox', { name: 'Search offer' })).toHaveValue('');
  });

  it('follows a new designerId when the manager dashboard links to the open board', async () => {
    const { router, fetchMock } = renderBoard('/board', {
      me: makeMe({ role: 'manager' }),
      table: { 'GET /api/tasks?designerId=7&includeCancelled=1': boardResponse([TASKS[4]]) },
    });
    await column('New 2');
    await act(() => router.navigate('/board?designerId=7'));
    await column('Completed 1');
    expect(boardUrls(fetchMock)).toContain('/api/tasks?designerId=7&includeCancelled=1');
  });
});

describe('states', () => {
  it('shows a translated notice and a way out when the filters match nothing', async () => {
    const { router, fetchMock } = renderBoard('/board?priority=urgent', {
      table: {
        'GET /api/tasks?priority=urgent&includeCancelled=1': boardResponse([]),
      },
    });
    expect(await screen.findByText('No tasks match these filters')).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    await column('New 2');
    expect(screen.queryByText('No tasks match these filters')).not.toBeInTheDocument();
    expect(router.state.location.search).toBe('');
    expect(boardUrls(fetchMock).at(-1)).toBe('/api/tasks?includeCancelled=1');
  });

  it('does not call an empty board a filter problem', async () => {
    renderBoard('/board', { tasks: [] });
    await column('New 0');
    expect(screen.queryByText('No tasks match these filters')).not.toBeInTheDocument();
  });

  it('shows an error with a Retry button when the board cannot be loaded', async () => {
    let fail = true;
    const { fetchMock } = renderBoard('/board', {
      table: {
        [`GET ${BOARD_PATH}`]: () =>
          fail ? errorResponse(422, 'validation_failed') : boardResponse(TASKS),
      },
    });
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Could not load the board');
    expect(screen.queryAllByRole('region')).toHaveLength(0);

    fail = false;
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    await column('New 2');
    expect(boardUrls(fetchMock)).toHaveLength(2);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows the error in Russian', async () => {
    renderBoard('/board', {
      me: makeMe({ locale: 'ru' }),
      table: { [`GET ${BOARD_PATH}`]: errorResponse(403, 'forbidden') },
    });
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Не удалось загрузить доску');
    expect(within(alert).getByRole('button', { name: 'Повторить' })).toBeInTheDocument();
  });
});

describe('roles', () => {
  it('offers New task to a buyer', async () => {
    renderBoard();
    await column('New 2');
    expect(
      within(screen.getByRole('main')).getByRole('link', { name: 'New task' }),
    ).toHaveAttribute('href', '/tasks/new');
  });

  it.each(['designer', 'manager'])('does not offer New task to a %s', async (role) => {
    renderBoard('/board', {
      me: makeMe({ role: /** @type {'designer' | 'manager'} */ (role) }),
    });
    await column('New 2');
    expect(within(screen.getByRole('main')).queryByRole('link', { name: 'New task' })).toBeNull();
  });

  it("makes a designer's pool cards draggable and nothing else", async () => {
    renderBoard('/board', {
      me: designer(),
      tasks: [
        makeTask({ id: 1, offer: 'Pool Offer', allowedActions: ['pick_up'] }),
        makeTask({
          id: 3,
          offer: 'Mine',
          status: 'in_progress',
          assignee: anna,
          allowedActions: ['submit', 'release'],
        }),
      ],
    });
    await column('New 1');
    const handles = screen.getAllByRole('button', { name: /^Drag/ });
    expect(handles).toHaveLength(1);
    expect(handles[0]).toHaveAccessibleName('Drag Pool Offer to In progress');
    // The grip is an icon: hidden from assistive technology, so the button's name is its label.
    expect(handles[0].querySelector('svg[aria-hidden="true"]')).not.toBeNull();
    expect(handles[0]).toHaveTextContent('');
    expect(within(await column('In progress 1')).queryByRole('button')).toBeNull();
  });

  it("makes none of a buyer's cards draggable", async () => {
    renderBoard();
    await column('New 2');
    expect(screen.queryByRole('button', { name: /drag/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /pick up/i })).not.toBeInTheDocument();
  });
});

describe('picking up', () => {
  const pool = makeTask({ id: 1, offer: 'Vulkan Bet', allowedActions: ['pick_up'] });
  const picked = makeTask({
    id: 1,
    offer: 'Vulkan Bet',
    status: 'in_progress',
    assignee: anna,
    allowedActions: ['submit', 'release'],
  });

  it('shows Pick up on a pool card only, calls the endpoint and moves the card from the server state', async () => {
    let isPicked = false;
    const { fetchMock } = renderBoard('/board', {
      me: designer(),
      tasks: [],
      table: {
        [`GET ${BOARD_PATH}`]: () => boardResponse(isPicked ? [picked] : [pool]),
        'POST /api/tasks/1/pickup': () => {
          isPicked = true;
          return mockResponse(200, { id: 1, status: 'in_progress' });
        },
      },
    });
    const pickUp = await screen.findByRole('button', { name: 'Pick up Vulkan Bet' });
    await userEvent.click(pickUp);

    expect(await within(await column('In progress 1')).findByText('Vulkan Bet')).toBeVisible();
    await column('New 0');
    expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(1);
    expect(boardUrls(fetchMock)).toEqual([
      '/api/tasks?includeCancelled=1',
      '/api/tasks?includeCancelled=1',
    ]);
    expect(screen.queryByRole('button', { name: /pick up/i })).not.toBeInTheDocument();
  });

  it('shows a spinner on the card while the request is open and ignores a second click', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const { fetchMock } = renderBoard('/board', {
      me: designer(),
      tasks: [pool, makeTask({ id: 2, offer: 'Other', allowedActions: ['pick_up'] })],
      table: {
        'POST /api/tasks/1/pickup': () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    const button = await screen.findByRole('button', { name: 'Pick up Vulkan Bet' });
    await userEvent.click(button);
    await waitFor(() => expect(button).toHaveAttribute('data-loading', 'true'));
    expect(screen.getByRole('button', { name: 'Pick up Other' })).toBeDisabled();
    await userEvent.click(button);
    expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(1);
    await act(async () => release(mockResponse(200, { id: 1 })));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Pick up Other' })).toBeEnabled(),
    );
  });

  it('shows a translated toast and refetches when somebody else was faster', async () => {
    let taken = false;
    const { fetchMock } = renderBoard('/board', {
      me: designer(),
      tasks: [],
      table: {
        [`GET ${BOARD_PATH}`]: () =>
          boardResponse(taken ? [makeTask({ id: 9, offer: 'Left' })] : [pool]),
        'POST /api/tasks/1/pickup': () => {
          taken = true;
          return errorResponse(409, 'already_taken');
        },
      },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Pick up Vulkan Bet' }));

    expect(await screen.findByText('Another designer has already taken this task.')).toBeVisible();
    expect(await screen.findByText('Left')).toBeVisible();
    expect(screen.queryByText('Vulkan Bet')).not.toBeInTheDocument();
    expect(boardUrls(fetchMock)).toHaveLength(2);
  });

  it('says it in Russian', async () => {
    renderBoard('/board', {
      me: makeMe({ ...designer(), locale: 'ru' }),
      tasks: [pool],
      table: { 'POST /api/tasks/1/pickup': errorResponse(409, 'already_taken') },
    });
    await userEvent.click(await screen.findByRole('button', { name: /Взять в работу/ }));
    expect(await screen.findByText(/Эту задачу уже взял/)).toBeVisible();
  });

  it.each([
    ['invalid_transition', 409],
    ['forbidden', 403],
    ['not_found', 404],
  ])('shows a toast for %s', async (code, status) => {
    renderBoard('/board', {
      me: designer(),
      tasks: [pool],
      table: { 'POST /api/tasks/1/pickup': errorResponse(status, code) },
    });
    await userEvent.click(await screen.findByRole('button', { name: 'Pick up Vulkan Bet' }));
    const toast = await screen.findByText(
      {
        invalid_transition: /not possible for the task in its current status/,
        forbidden: /do not have permission/,
        not_found: /Not found/,
      }[/** @type {'forbidden'} */ (code)],
    );
    expect(toast).toBeVisible();
  });
});
