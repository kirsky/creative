import { notifications } from '@mantine/notifications';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { BOARD_PATH, boardResponse, makeTask } from '../../test/board';
import { errorResponse, makeMe, mockResponse } from '../../test/http';
import { makeOptions, renderApp } from '../../test/renderApp';

const OPTIONS_OK = { 'GET /api/options': mockResponse(200, makeOptions()) };
const anna = { id: 3, displayName: 'Anna K.' };
const igor = { id: 7, displayName: 'Igor S.' };

const pool = makeTask({
  id: 1,
  offer: 'Vulkan Bet',
  quantity: 5,
  allowedActions: ['pick_up'],
  deadlineAt: '2099-03-01T12:00:00Z',
});
const returning = makeTask({
  id: 2,
  offer: 'Crypto Wave',
  isReturning: true,
  allowedActions: ['pick_up'],
  deadlineAt: '2099-02-01T12:00:00Z',
});
const working = makeTask({
  id: 3,
  offer: 'Лото Клуб',
  status: 'in_progress',
  assignee: anna,
  allowedActions: ['submit', 'release'],
  deadlineAt: '2099-05-01T12:00:00Z',
});
const revisions = makeTask({
  id: 4,
  offer: 'Casino Royal',
  status: 'revisions_required',
  assignee: anna,
  roundCount: 1,
  deadlineAt: '2099-09-01T12:00:00Z',
});
const late = makeTask({
  id: 5,
  offer: 'Late Offer',
  status: 'in_progress',
  assignee: anna,
  isOverdue: true,
  deadlineAt: '2020-01-01T00:00:00Z',
});
const waiting = makeTask({
  id: 6,
  offer: 'Waiting Offer',
  status: 'under_review',
  assignee: anna,
  deadlineAt: '2099-04-01T12:00:00Z',
});
const foreign = makeTask({
  id: 7,
  offer: 'Not Mine',
  status: 'in_progress',
  assignee: igor,
  deadlineAt: '2099-01-01T12:00:00Z',
});
const done = makeTask({ id: 8, offer: 'Done Offer', status: 'completed', assignee: anna });
const cancelled = makeTask({ id: 9, offer: 'Dropped Offer', status: 'cancelled', assignee: anna });

const TASKS = [pool, returning, working, revisions, late, waiting, foreign, done, cancelled];

const designer = () => makeMe({ id: 3, role: 'designer', displayName: 'Anna K.' });

/**
 * @param {{tasks?: ReturnType<typeof makeTask>[], table?: Record<string, any>, me?: ReturnType<typeof makeMe>, path?: string}} [options]
 */
function renderMyWork({ tasks = TASKS, table = {}, me = designer(), path = '/my-work' } = {}) {
  return renderApp(path, {
    me,
    table: { ...OPTIONS_OK, [`GET ${BOARD_PATH}`]: boardResponse(tasks), ...table },
  });
}

const counter = (/** @type {string} */ key) => screen.getByTestId(`counter-${key}`);
const root = () => screen.getByTestId('my-work');
/** A section once the board is loaded (the sections already exist, with skeletons, before). */
const section = async (/** @type {string} */ title) => {
  const name = new RegExp(`^${title}( \\d+)?$`);
  await screen.findByRole('region', { name });
  await waitFor(() => expect(screen.queryByTestId('card-skeleton')).not.toBeInTheDocument());
  return screen.getByRole('region', { name });
};
/** @param {HTMLElement} element */
const offers = (element) =>
  within(element)
    .getAllByRole('article')
    .map((card) => within(card).getAllByRole('link')[0]?.textContent);
/** @param {ReturnType<typeof jest.fn>} fetchMock */
const boardUrls = (fetchMock) =>
  fetchMock.mock.calls.map(([url]) => url).filter((url) => url.startsWith('/api/tasks?'));

beforeEach(() => {
  setCsrfToken(null);
});
afterEach(() => {
  // Toasts live in a store outside React: one test's toast must not reach the next test.
  act(() => notifications.clean());
});

describe('counters', () => {
  it('count in_progress, revisions_required and new tasks', async () => {
    renderMyWork();
    await section('My tasks');
    expect(counter('in_progress')).toHaveTextContent('In progress');
    expect(counter('in_progress')).toHaveTextContent('2');
    expect(counter('revisions_required')).toHaveTextContent('Revisions required');
    expect(counter('revisions_required')).toHaveTextContent('1');
    expect(counter('available')).toHaveTextContent('Available');
    expect(counter('available')).toHaveTextContent('2');
  });

  it('ignore other designers, completed and cancelled tasks', async () => {
    renderMyWork({ tasks: [foreign, done, cancelled, pool] });
    await section('Available tasks');
    expect(counter('in_progress')).toHaveTextContent('0');
    expect(counter('revisions_required')).toHaveTextContent('0');
    expect(counter('available')).toHaveTextContent('1');
  });

  it('show a placeholder, not 0, while the board loads', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    renderMyWork({
      table: {
        [`GET ${BOARD_PATH}`]: () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    expect(await screen.findByRole('heading', { level: 1, name: 'My work' })).toBeInTheDocument();
    for (const key of ['in_progress', 'revisions_required', 'available']) {
      expect(counter(key)).toHaveTextContent('—');
      expect(counter(key)).not.toHaveTextContent('0');
    }
    expect(screen.getAllByTestId('card-skeleton').length).toBeGreaterThan(0);
    await act(async () => release(boardResponse(TASKS)));
    await section('My tasks');
    expect(counter('available')).toHaveTextContent('2');
    expect(screen.queryByTestId('card-skeleton')).not.toBeInTheDocument();
  });
});

describe('assigned tasks', () => {
  it('lists only the signed-in designer’s active tasks, revisions first, then overdue, then by deadline', async () => {
    renderMyWork();
    const assigned = await section('My tasks');
    expect(offers(assigned)).toEqual(['Casino Royal', 'Late Offer', 'Лото Клуб', 'Waiting Offer']);
    expect(screen.queryByText('Not Mine')).not.toBeInTheDocument();
    expect(screen.queryByText('Done Offer')).not.toBeInTheDocument();
    expect(screen.queryByText('Dropped Offer')).not.toBeInTheDocument();
  });

  it('ranks an overdue task under review after a non-overdue one in progress, and keeps it styled late', async () => {
    const lateReview = makeTask({
      id: 10,
      offer: 'Late Review',
      status: 'under_review',
      assignee: anna,
      isOverdue: true,
      deadlineAt: '2020-02-01T00:00:00Z',
    });
    renderMyWork({ tasks: [lateReview, working, late, revisions] });
    const assigned = await section('My tasks');
    expect(offers(assigned)).toEqual(['Casino Royal', 'Late Offer', 'Лото Клуб', 'Late Review']);
    expect(within(assigned).getByText('Late Review').closest('article')).toHaveAttribute(
      'data-overdue',
      'true',
    );
  });

  it('shows the status of each assigned card', async () => {
    renderMyWork();
    const assigned = await section('My tasks');
    const statuses = within(assigned)
      .getAllByTestId('task-card-status')
      .map((badge) => badge.textContent);
    expect(statuses).toEqual(['Revisions required', 'In progress', 'In progress', 'Under review']);
  });

  it('marks a task that needs revisions with an accent and a label, and only that one', async () => {
    renderMyWork();
    const assigned = await section('My tasks');
    const items = within(assigned).getAllByRole('listitem');
    const marked = items.filter((item) => item.dataset.attention === 'true');
    expect(marked).toHaveLength(1);
    expect(marked[0]).toHaveTextContent('Casino Royal');
    expect(within(marked[0]).getByText('Needs your attention')).toBeInTheDocument();
    expect(within(assigned).getAllByText('Needs your attention')).toHaveLength(1);
  });

  it('keeps the overdue styling of the card', async () => {
    renderMyWork();
    const assigned = await section('My tasks');
    const lateCard = within(assigned).getByText('Late Offer').closest('article');
    expect(lateCard).toHaveAttribute('data-overdue', 'true');
  });

  it('links each card to its task page', async () => {
    renderMyWork();
    await section('My tasks');
    expect(screen.getByRole('link', { name: 'Лото Клуб' })).toHaveAttribute('href', '/tasks/3');
    expect(screen.getByRole('link', { name: 'Vulkan Bet' })).toHaveAttribute('href', '/tasks/1');
  });

  it('offers no Pick up button on assigned cards', async () => {
    renderMyWork();
    const assigned = await section('My tasks');
    expect(within(assigned).queryByRole('button')).not.toBeInTheDocument();
  });
});

describe('available tasks', () => {
  it('lists the pool with a Pick up button, returning tasks included', async () => {
    renderMyWork();
    const available = await section('Available tasks');
    // The API sends the pool earliest deadline first; the page keeps that order.
    expect(offers(available)).toEqual(['Vulkan Bet', 'Crypto Wave']);
    expect(within(available).getByText('Vulkan Bet')).toBeInTheDocument();
    expect(within(available).getByText('Crypto Wave')).toBeInTheDocument();
    expect(within(available).getByText('Revision pending (released)')).toBeInTheDocument();
    expect(within(available).getAllByRole('button', { name: /^Pick up / })).toHaveLength(2);
    expect(within(available).getByRole('heading', { level: 2 })).toHaveTextContent(
      'Available tasks 2',
    );
  });
});

describe('focus', () => {
  it('puts the assigned tasks first when there are some', async () => {
    renderMyWork();
    const assigned = await section('My tasks');
    const available = await section('Available tasks');
    expect(root()).not.toHaveAttribute('data-focus');
    expect(
      assigned.compareDocumentPosition(available) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(within(available).getAllByRole('article').length).toBe(2);
  });

  it('counts a task that waits for review as assigned work', async () => {
    renderMyWork({ tasks: [waiting, pool] });
    const assigned = await section('My tasks');
    expect(root()).not.toHaveAttribute('data-focus');
    expect(within(assigned).getByText('Waiting Offer')).toBeInTheDocument();
  });

  it('focuses the available tasks when nothing is assigned', async () => {
    renderMyWork({ tasks: [pool, returning, foreign, done, cancelled] });
    const available = await section('Available tasks');
    const assigned = await section('My tasks');
    expect(root()).toHaveAttribute('data-focus', 'available');
    expect(
      available.compareDocumentPosition(assigned) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(within(assigned).getByText(/^You have no tasks in progress\./)).toBeInTheDocument();
    expect(within(available).getAllByRole('article')).toHaveLength(2);
  });

  it('shows one friendly empty state when there is nothing at all', async () => {
    renderMyWork({ tasks: [foreign, done] });
    expect(await screen.findByText('Nothing to do right now')).toBeInTheDocument();
    expect(screen.queryByRole('region')).not.toBeInTheDocument();
    expect(screen.getAllByText('Nothing to do right now')).toHaveLength(1);
    expect(counter('in_progress')).toHaveTextContent('0');
    expect(screen.getByRole('link', { name: 'Open full board' })).toBeInTheDocument();
  });

  it('says so in the available section when the pool is empty', async () => {
    renderMyWork({ tasks: [working] });
    const available = await section('Available tasks');
    expect(within(available).getByText('No tasks to pick up right now.')).toBeInTheDocument();
  });
});

describe('picking up', () => {
  it('moves the task from available to assigned after the refetch', async () => {
    let boardCalls = 0;
    const { fetchMock } = renderMyWork({
      table: {
        [`GET ${BOARD_PATH}`]: () => {
          boardCalls += 1;
          return boardResponse(
            boardCalls === 1
              ? [pool, returning]
              : [
                  { ...pool, status: 'in_progress', assignee: anna, allowedActions: ['submit'] },
                  returning,
                ],
          );
        },
        'POST /api/tasks/1/pickup': mockResponse(200, { id: 1, status: 'in_progress' }),
      },
    });
    const available = await section('Available tasks');
    expect(root()).toHaveAttribute('data-focus', 'available');
    await userEvent.click(within(available).getByRole('button', { name: 'Pick up Vulkan Bet' }));

    const assigned = await section('My tasks');
    await waitFor(() => expect(within(assigned).getByText('Vulkan Bet')).toBeInTheDocument());
    expect(within(await section('Available tasks')).queryByText('Vulkan Bet')).toBeNull();
    expect(root()).not.toHaveAttribute('data-focus');
    expect(counter('in_progress')).toHaveTextContent('1');
    expect(counter('available')).toHaveTextContent('1');
    expect(boardUrls(fetchMock)).toHaveLength(2);
  });

  it('disables the other Pick up buttons while one is in flight', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    renderMyWork({
      tasks: [pool, returning],
      table: {
        'POST /api/tasks/1/pickup': () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    const available = await section('Available tasks');
    await userEvent.click(within(available).getByRole('button', { name: 'Pick up Vulkan Bet' }));
    await waitFor(() =>
      expect(within(available).getByRole('button', { name: 'Pick up Crypto Wave' })).toBeDisabled(),
    );
    await act(async () => release(mockResponse(200, { id: 1, status: 'in_progress' })));
  });

  it('shows the translated toast for already_taken and refetches', async () => {
    let boardCalls = 0;
    const { fetchMock } = renderMyWork({
      table: {
        [`GET ${BOARD_PATH}`]: () => {
          boardCalls += 1;
          return boardResponse(boardCalls === 1 ? [pool, returning] : [returning]);
        },
        'POST /api/tasks/1/pickup': errorResponse(409, 'already_taken'),
      },
    });
    const available = await section('Available tasks');
    await userEvent.click(within(available).getByRole('button', { name: 'Pick up Vulkan Bet' }));
    expect(
      await screen.findByText('Another designer has already taken this task.'),
    ).toBeInTheDocument();
    await waitFor(() => expect(boardUrls(fetchMock)).toHaveLength(2));
    await waitFor(() => expect(screen.queryByText('Vulkan Bet')).not.toBeInTheDocument());
    expect(counter('available')).toHaveTextContent('1');
  });
});

describe('page states', () => {
  it('shows an error with a Retry button', async () => {
    let fail = true;
    const { fetchMock } = renderMyWork({
      table: {
        [`GET ${BOARD_PATH}`]: () =>
          fail ? errorResponse(422, 'validation_failed') : boardResponse(TASKS),
      },
    });
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Could not load your work');
    expect(screen.queryByTestId('counter-available')).not.toBeInTheDocument();
    fail = false;
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    await section('My tasks');
    expect(boardUrls(fetchMock)).toHaveLength(2);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('links to the full board', async () => {
    renderMyWork();
    await section('My tasks');
    expect(screen.getByRole('link', { name: 'Open full board' })).toHaveAttribute('href', '/board');
  });

  it('shares the board query: opening My work from the board shows data at once', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    let calls = 0;
    const { fetchMock } = renderMyWork({
      path: '/board',
      table: {
        [`GET ${BOARD_PATH}`]: () => {
          calls += 1;
          return calls === 1
            ? boardResponse(TASKS)
            : new Promise((resolve) => {
                release = resolve;
              });
        },
      },
    });
    await screen.findByRole('region', { name: /^New/ });
    await userEvent.click(
      within(screen.getByRole('navigation')).getByRole('link', { name: 'My work' }),
    );
    await section('My tasks');
    // The second request is still open: what is on screen came from the board's cache entry.
    expect(counter('available')).toHaveTextContent('2');
    expect(screen.queryByTestId('card-skeleton')).not.toBeInTheDocument();
    expect(boardUrls(fetchMock)).toEqual([
      '/api/tasks?includeCancelled=1',
      '/api/tasks?includeCancelled=1',
    ]);
    await act(async () => release(boardResponse(TASKS)));
  });
});

describe('access and language', () => {
  it.each([
    ['buyer', '/board'],
    ['manager', '/dashboard'],
  ])('redirects a %s away from /my-work', async (role, landing) => {
    const { router } = renderMyWork({
      me: makeMe({ role: /** @type {'buyer' | 'manager'} */ (role) }),
    });
    await waitFor(() => expect(router.state.location.pathname).toBe(landing));
    expect(screen.queryByTestId('my-work')).not.toBeInTheDocument();
  });

  it('is in Russian, with the Russian plural of the quantity', async () => {
    renderMyWork({
      me: makeMe({ id: 3, role: 'designer', locale: 'ru' }),
      tasks: [{ ...pool, quantity: 2 }, { ...returning, quantity: 5 }, revisions],
    });
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Моя работа' }),
    ).toBeInTheDocument();
    await section('Мои задачи');
    expect(counter('in_progress')).toHaveTextContent('В работе');
    expect(counter('revisions_required')).toHaveTextContent('Нужны правки');
    expect(counter('available')).toHaveTextContent('Доступно');
    expect(screen.getByText('Требуют вашего внимания')).toBeInTheDocument();
    const available = await section('Доступные задачи');
    expect(within(available).getByText('Vulkan Bet').closest('article')).toHaveTextContent(
      '2 креатива',
    );
    expect(within(available).getByText('Crypto Wave').closest('article')).toHaveTextContent(
      '5 креативов',
    );
    expect(screen.getByRole('link', { name: 'Открыть всю доску' })).toBeInTheDocument();
  });

  it('says there is nothing to do in Russian', async () => {
    renderMyWork({ me: makeMe({ id: 3, role: 'designer', locale: 'ru' }), tasks: [] });
    expect(await screen.findByText('Сейчас задач нет')).toBeInTheDocument();
  });
});
