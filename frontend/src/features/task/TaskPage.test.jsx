import { act, screen, within } from '@testing-library/react';
import { setCsrfToken } from '../../api/client';
import i18n from '../../i18n';
import { formatDateTime } from '../../i18n/format';
import { makeMe, mockResponse } from '../../test/http';
import { renderApp } from '../../test/renderApp';
import { ANNA, IGOR, makeRound, makeTaskDetail } from '../../test/taskDetail';

beforeEach(() => {
  setCsrfToken(null);
});

/**
 * @param {Partial<import('./api').TaskDetail>} [overrides]
 * @param {{me?: ReturnType<typeof makeMe>}} [options]
 */
function renderTask(overrides = {}, { me } = {}) {
  const task = makeTaskDetail(overrides);
  const utils = renderApp(`/tasks/${task.id}`, {
    me,
    table: { [`GET /api/tasks/${task.id}`]: mockResponse(200, task) },
  });
  return { task, ...utils };
}

const region = (/** @type {string | RegExp} */ name) => screen.findByRole('region', { name });

/** The value next to a label of the side panel. */
async function fact(/** @type {string} */ label) {
  const panel = await region('Facts');
  const term = within(panel).getByText(label, { selector: 'dt' });
  return /** @type {HTMLElement} */ (term.nextElementSibling);
}

/** A task under review in its second round, as a designer left it. */
const REVIEW_TASK = {
  status: /** @type {const} */ ('under_review'),
  assignee: ANNA,
  roundCount: 2,
  creditsTotal: '220.00',
  deadlineAt: '2099-11-12T12:00:00Z',
  assignments: [
    {
      designer: IGOR,
      assignedAt: '2026-11-09T08:00:00.000Z',
      endedAt: '2026-11-09T12:00:00.000Z',
      endedHow: /** @type {const} */ ('released'),
    },
    { designer: ANNA, assignedAt: '2026-11-09T13:00:00.000Z', endedAt: null, endedHow: null },
  ],
  rounds: [
    makeRound({
      number: 1,
      note: 'First cut, hook is long',
      decision: /** @type {const} */ ('revisions_requested'),
      decisionReason: 'Shorter hook, bigger logo',
      decidedAt: '2026-11-10T10:00:00.000Z',
      files: [{ id: 21, name: 'v1_hook.mp4', size: 2_500_000, mime: 'video/mp4' }],
      aiUsage: [
        { provider: 'Midjourney', credits: '40.00' },
        { provider: 'Runway', credits: '120.00' },
      ],
    }),
    makeRound({
      number: 2,
      submittedAt: '2026-11-11T09:30:00.000Z',
      files: [
        { id: 31, name: 'v2_hook_a.mp4', size: 1_200_000, mime: 'video/mp4' },
        { id: 32, name: 'v2_hook_b.mp4', size: 850, mime: 'video/mp4' },
      ],
      aiUsage: [{ provider: 'Runway', credits: '60.00' }],
    }),
  ],
  metrics: {
    queueWaitSeconds: 11_400,
    cycleSeconds: 100_800,
    reviewSeconds: 5_400,
    revisionTurnaroundSeconds: 7_200,
  },
};

describe('header', () => {
  it('titles the page with offer, GEO and type with quantity, and shows status and priority', async () => {
    renderTask({ ...REVIEW_TASK, priority: 'urgent', geo: ['DE', 'EU'] });
    expect(
      await screen.findByRole('heading', {
        level: 1,
        name: 'Casino Royal · Germany, Europe · Video ×3',
      }),
    ).toBeInTheDocument();
    const header = screen.getByTestId('task-header');
    expect(within(header).getByText('Under review')).toBeInTheDocument();
    expect(within(header).getByText('Urgent')).toBeInTheDocument();
  });

  it('links back to the board', async () => {
    renderTask();
    expect(await screen.findByRole('link', { name: 'Back to board' })).toHaveAttribute(
      'href',
      '/board',
    );
  });

  it('links a designer back to My work', async () => {
    renderTask({}, { me: makeMe({ role: 'designer' }) });
    expect(await screen.findByRole('link', { name: 'Back to My work' })).toHaveAttribute(
      'href',
      '/my-work',
    );
  });

  it('marks an overdue deadline with data-overdue, as the card does', async () => {
    renderTask({ isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' });
    const deadline = await screen.findByTestId('task-deadline');
    expect(deadline).toHaveAttribute('data-overdue', 'true');
    expect(deadline).toHaveTextContent(/overdue/i);
  });

  it('does not mark a deadline that lies ahead', async () => {
    renderTask({ isOverdue: false });
    const deadline = await screen.findByTestId('task-deadline');
    expect(deadline).not.toHaveAttribute('data-overdue');
    expect(deadline).toHaveTextContent(/due/i);
  });
});

describe('side panel', () => {
  it('shows Created by and Assigned to, and never the word Owner', async () => {
    renderTask(REVIEW_TASK);
    expect((await fact('Created by')).textContent).toBe('Dmitry P.');
    expect((await fact('Assigned to')).textContent).toBe('Anna K.');
    expect(screen.queryByText(/owner/i)).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/owner|working time|time spent/i);
  });

  it('says Unassigned when nobody holds the task', async () => {
    renderTask({ assignee: null });
    expect((await fact('Assigned to')).textContent).toBe('Unassigned');
  });

  it('shows the stage and the deadline', async () => {
    renderTask(REVIEW_TASK);
    expect((await fact('Stage')).textContent).toBe('Under review');
    expect((await fact('Deadline')).textContent).toBe(formatDateTime('2099-11-12T12:00:00Z', 'en'));
  });

  it('shows the four durations with formatDuration and the total credits', async () => {
    renderTask(REVIEW_TASK);
    expect((await fact('Queue wait')).textContent).toBe('3 h 10 m');
    expect((await fact('Cycle time')).textContent).toBe('1 d 4 h');
    expect((await fact('Review time')).textContent).toBe('1 h 30 m');
    expect((await fact('Revision turnaround')).textContent).toBe('2 h');
    expect((await fact('AI credits')).textContent).toBe('220.00');
  });

  it('shows a dash for a cycle that has not started and zero as under a minute', async () => {
    renderTask({
      metrics: {
        queueWaitSeconds: 30,
        cycleSeconds: null,
        reviewSeconds: 0,
        revisionTurnaroundSeconds: 0,
      },
    });
    expect((await fact('Cycle time')).textContent).toBe('—');
    expect((await fact('Queue wait')).textContent).toBe('< 1 m');
    expect((await fact('Review time')).textContent).toBe('< 1 m');
  });

  it('says that the times are elapsed time, not hours worked', async () => {
    renderTask(REVIEW_TASK);
    const panel = await region('Facts');
    expect(within(panel).getByText('Elapsed time, not hours worked.')).toBeInTheDocument();
  });

  it('lists the earlier assignees with how each assignment ended', async () => {
    renderTask(REVIEW_TASK);
    const panel = await region('Facts');
    const list = within(panel).getByRole('list', { name: 'Previous assignees' });
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(1);
    expect(items[0]).toHaveTextContent('Igor S. — released');
  });

  it('has no previous assignees list for a task nobody released', async () => {
    renderTask({
      assignee: ANNA,
      assignments: [
        { designer: ANNA, assignedAt: '2026-11-09T13:00:00.000Z', endedAt: null, endedHow: null },
      ],
    });
    await fact('Created by');
    expect(screen.queryByRole('list', { name: 'Previous assignees' })).not.toBeInTheDocument();
  });

  it('shows a previous assignee without a dangling dash when the ending is unknown', async () => {
    renderTask({
      status: 'in_progress',
      assignee: ANNA,
      assignments: [
        {
          designer: IGOR,
          assignedAt: '2026-11-09T08:00:00.000Z',
          endedAt: '2026-11-09T12:00:00.000Z',
          endedHow: null,
        },
        { designer: ANNA, assignedAt: '2026-11-09T13:00:00.000Z', endedAt: null, endedHow: null },
      ],
    });
    const panel = await region('Facts');
    const items = within(
      within(panel).getByRole('list', { name: 'Previous assignees' }),
    ).getAllByRole('listitem');
    expect(items).toHaveLength(1);
    expect(items[0].textContent).toMatch(/^Igor S\.\s*Nov 9, 2026/);
    expect(items[0].textContent).not.toContain('—');
  });

  it('never lists the current assignee of a task in progress as a previous one', async () => {
    renderTask({
      status: 'in_progress',
      assignee: ANNA,
      assignments: [
        {
          designer: ANNA,
          assignedAt: '2026-11-09T13:00:00.000Z',
          endedAt: '2026-11-09T14:00:00.000Z',
          endedHow: null,
        },
      ],
    });
    await fact('Created by');
    expect(screen.queryByRole('list', { name: 'Previous assignees' })).not.toBeInTheDocument();
  });

  it('still lists a designer who released the task and picked it up again', async () => {
    renderTask({
      status: 'in_progress',
      assignee: ANNA,
      assignments: [
        {
          designer: ANNA,
          assignedAt: '2026-11-09T08:00:00.000Z',
          endedAt: '2026-11-09T12:00:00.000Z',
          endedHow: 'released',
        },
        { designer: ANNA, assignedAt: '2026-11-09T13:00:00.000Z', endedAt: null, endedHow: null },
      ],
    });
    const panel = await region('Facts');
    expect(within(panel).getByText('Anna K. — released')).toBeInTheDocument();
  });

  it('shows who had a completed task and that the work ended with approval', async () => {
    renderTask({
      status: 'completed',
      assignee: ANNA,
      assignments: [
        {
          designer: ANNA,
          assignedAt: '2026-11-09T13:00:00.000Z',
          endedAt: '2026-11-11T10:00:00.000Z',
          endedHow: 'completed',
        },
      ],
    });
    const panel = await region('Facts');
    expect(within(panel).getByText('Anna K. — completed')).toBeInTheDocument();
  });
});

describe('Facts groups', () => {
  /** @param {string} name */
  async function group(name) {
    const panel = await region('Facts');
    return within(panel).getByRole('group', { name });
  }

  it('has People, Schedule and Time groups', async () => {
    renderTask(REVIEW_TASK);
    expect(await group('People')).toBeInTheDocument();
    expect(await group('Schedule')).toBeInTheDocument();
    expect(await group('Time')).toBeInTheDocument();
  });

  it('names the creator and the assignee with a decorative avatar each', async () => {
    renderTask(REVIEW_TASK);
    const people = await group('People');
    expect(
      within(people).getByText('Created by', { selector: 'dt' }).nextElementSibling,
    ).toHaveTextContent('Dmitry P.');
    expect(within(people).getByText('Anna K.')).toBeInTheDocument();
    const avatars = people.querySelectorAll('.task-side__avatar[aria-hidden="true"]');
    expect(avatars).toHaveLength(2);
  });

  it('shows Unassigned without an avatar for an empty assignee', async () => {
    renderTask({ assignee: null, status: 'new' });
    const people = await group('People');
    expect(within(people).getByText('Unassigned')).toBeInTheDocument();
    expect(people.querySelectorAll('.task-side__avatar')).toHaveLength(1);
  });

  it('keeps the deadline in the Schedule group and marks an overdue one', async () => {
    renderTask({ isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' });
    const schedule = await group('Schedule');
    const deadline = within(schedule).getByText('Deadline', { selector: 'dt' }).nextElementSibling;
    expect(deadline).toHaveAttribute('data-overdue', 'true');
  });

  it('does not mark a deadline that lies ahead', async () => {
    renderTask(REVIEW_TASK);
    const schedule = await group('Schedule');
    const deadline = within(schedule).getByText('Deadline', { selector: 'dt' }).nextElementSibling;
    expect(deadline).not.toHaveAttribute('data-overdue');
  });

  it('puts the four durations in tiles and the credits in a tile of their own', async () => {
    renderTask(REVIEW_TASK);
    const time = await group('Time');
    const tiles = time.querySelectorAll('.task-side__tile');
    expect(tiles).toHaveLength(4);
    expect(within(time).getByTestId('facts-credits')).toHaveTextContent('220.00');
  });

  it('shows a dash for a cycle not started and "< 1 m" for zeros, never blank or NaN', async () => {
    renderTask({
      metrics: {
        queueWaitSeconds: 0,
        cycleSeconds: null,
        reviewSeconds: 0,
        revisionTurnaroundSeconds: 0,
      },
    });
    const time = await group('Time');
    const values = [...time.querySelectorAll('.task-side__tile dd')].map((dd) => dd.textContent);
    expect(values).toEqual(['< 1 m', '—', '< 1 m', '< 1 m']);
    expect(time).not.toHaveTextContent('NaN');
  });

  it('shows no previous-assignees heading when nobody held the task before', async () => {
    renderTask({
      assignee: ANNA,
      assignments: [
        { designer: ANNA, assignedAt: '2026-11-09T13:00:00.000Z', endedAt: null, endedHow: null },
      ],
    });
    await region('Facts');
    expect(screen.queryByText('Previous assignees')).not.toBeInTheDocument();
  });

  it('words the group headings in Russian', async () => {
    renderTask(REVIEW_TASK);
    await region('Facts');
    await act(() => i18n.changeLanguage('ru'));
    const panel = await region('Факты');
    expect(within(panel).getByRole('group', { name: 'Люди' })).toBeInTheDocument();
    expect(within(panel).getByRole('group', { name: 'Сроки' })).toBeInTheDocument();
    expect(within(panel).getByRole('group', { name: 'Время' })).toBeInTheDocument();
  });
});

describe('brief', () => {
  it('shows the description as text with its line breaks', async () => {
    renderTask({ description: 'Line one\nLine two' });
    const brief = await region('Brief');
    const description = within(brief).getByText(/Line one/);
    expect(description.textContent).toBe('Line one\nLine two');
    expect(description).toHaveClass('task-brief__description');
  });

  it('renders markup, emoji and Cyrillic in the description literally (no script element)', async () => {
    const text = '<script>alert(1)</script> 🎰 Лото <img src=x onerror=alert(2)>';
    renderTask({ description: text });
    const brief = await region('Brief');
    expect(within(brief).getByText(text)).toBeInTheDocument();
    expect(document.querySelector('script')).toBeNull();
    expect(brief.querySelector('img')).toBeNull();
  });

  it('says so when there is no description', async () => {
    renderTask({ description: '' });
    const brief = await region('Brief');
    expect(within(brief).getByText('No description.')).toBeInTheDocument();
  });

  it('opens http and https references in a new tab without opener or referrer', async () => {
    renderTask({
      references: [
        { type: 'url', url: 'https://example.com/ref?a=1' },
        { type: 'url', url: 'http://example.org/page' },
      ],
    });
    const brief = await region('Brief');
    const links = within(brief).getAllByRole('link', { name: /example/ });
    expect(links).toHaveLength(2);
    for (const link of links) {
      expect(link).toHaveAttribute('target', '_blank');
      expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    }
    expect(links[0]).toHaveAttribute('href', 'https://example.com/ref?a=1');
    expect(links[1]).toHaveAttribute('href', 'http://example.org/page');
  });

  it.each([
    'javascript:alert(1)',
    ' JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'ftp://example.com/file',
    'example.com/no-scheme',
    '//evil.example/x',
  ])('shows the reference %j as plain text, not a link', async (url) => {
    renderTask({ references: [{ type: 'url', url }] });
    const brief = await region('Brief');
    expect(within(brief).queryByRole('link')).not.toBeInTheDocument();
    expect(brief.querySelector('a')).toBeNull();
    expect(brief.textContent).toContain(url);
    expect(document.querySelector('script')).toBeNull();
  });

  it('links a file reference to the authenticated download with its name and size', async () => {
    renderTask({
      references: [
        { type: 'file', fileId: 9, name: 'Бриф 🎰.pdf', mime: 'application/pdf', size: 12_500 },
      ],
    });
    const brief = await region('Brief');
    const link = within(brief).getByRole('link', { name: 'Бриф 🎰.pdf' });
    expect(link).toHaveAttribute('href', '/api/files/9');
    expect(link.closest('li')).toHaveTextContent('12.5 KB');
  });

  it('says so when there are no references', async () => {
    renderTask({ references: [] });
    const brief = await region('Brief');
    expect(within(brief).getByText('No references.')).toBeInTheDocument();
  });
});

describe('deliverables', () => {
  it('shows the files of the latest round with download links and sizes', async () => {
    renderTask(REVIEW_TASK);
    const deliverables = await region('Deliverables · round 2');
    const links = within(deliverables).getAllByRole('link');
    expect(links.map((link) => link.textContent)).toEqual(['v2_hook_a.mp4', 'v2_hook_b.mp4']);
    expect(links[0]).toHaveAttribute('href', '/api/files/31');
    expect(links[1]).toHaveAttribute('href', '/api/files/32');
    const items = within(deliverables).getAllByRole('listitem');
    expect(items[0]).toHaveTextContent('1.2 MB');
    expect(items[1]).toHaveTextContent('850 B');
    expect(within(deliverables).queryByText('v1_hook.mp4')).not.toBeInTheDocument();
  });

  it('shows an empty state before the first round', async () => {
    renderTask({ rounds: [] });
    const deliverables = await region('Deliverables');
    expect(within(deliverables).getByText('Nothing has been submitted yet.')).toBeInTheDocument();
    expect(within(deliverables).queryByRole('link')).not.toBeInTheDocument();
  });
});

describe('revision rounds', () => {
  it('lists the rounds oldest first with who submitted, when and the designer note', async () => {
    renderTask(REVIEW_TASK);
    const rounds = await region('Revision rounds');
    const articles = within(rounds).getAllByRole('article');
    expect(articles).toHaveLength(2);
    expect(within(articles[0]).getByRole('heading', { name: 'Round 1' })).toBeInTheDocument();
    expect(within(articles[1]).getByRole('heading', { name: 'Round 2' })).toBeInTheDocument();
    expect(articles[0]).toHaveTextContent('Anna K.');
    expect(articles[0]).toHaveTextContent(formatDateTime('2026-11-10T09:00:00.000Z', 'en'));
    expect(articles[0]).toHaveTextContent('First cut, hook is long');
    expect(within(articles[0]).getByRole('link', { name: 'v1_hook.mp4' })).toHaveAttribute(
      'href',
      '/api/files/21',
    );
  });

  it('shows the buyer decision with its reason, or Waiting for review', async () => {
    renderTask(REVIEW_TASK);
    const [first, second] = within(await region('Revision rounds')).getAllByRole('article');
    expect(first).toHaveTextContent('Revisions requested');
    expect(first).toHaveTextContent('Shorter hook, bigger logo');
    expect(first).toHaveTextContent(formatDateTime('2026-11-10T10:00:00.000Z', 'en'));
    expect(first).not.toHaveTextContent('Waiting for review');
    expect(second).toHaveTextContent('Waiting for review');
    expect(second).not.toHaveTextContent('Revisions requested');
  });

  it('shows an approval with its note', async () => {
    renderTask({
      status: 'completed',
      rounds: [
        makeRound({
          decision: 'approved',
          decisionReason: 'Great work',
          decidedAt: '2026-11-10T12:00:00.000Z',
        }),
      ],
    });
    const [article] = within(await region('Revision rounds')).getAllByRole('article');
    expect(article).toHaveTextContent('Approved');
    expect(article).toHaveTextContent('Great work');
  });

  it('shows the AI usage lines of each round and their subtotal', async () => {
    renderTask(REVIEW_TASK);
    const [first, second] = within(await region('Revision rounds')).getAllByRole('article');
    expect(first).toHaveTextContent('Midjourney — 40.00');
    expect(first).toHaveTextContent('Runway — 120.00');
    expect(first).toHaveTextContent('Round total: 160.00');
    expect(second).toHaveTextContent('Runway — 60.00');
    expect(second).toHaveTextContent('Round total: 60.00');
  });

  it('adds credits exactly, with no floating point drift', async () => {
    renderTask({
      rounds: [
        makeRound({
          aiUsage: [
            { provider: 'A', credits: '0.10' },
            { provider: 'B', credits: '0.20' },
            { provider: 'C', credits: '1000000.07' },
          ],
        }),
      ],
    });
    const [article] = within(await region('Revision rounds')).getAllByRole('article');
    expect(article).toHaveTextContent('Round total: 1,000,000.37');
  });

  it('shows no AI block for a round without AI usage', async () => {
    renderTask({ rounds: [makeRound()] });
    const [article] = within(await region('Revision rounds')).getAllByRole('article');
    expect(article).not.toHaveTextContent(/AI usage|Round total/);
  });

  it('shows an empty state before the first round', async () => {
    renderTask({ rounds: [] });
    const rounds = await region('Revision rounds');
    expect(within(rounds).getByText('No rounds yet.')).toBeInTheDocument();
  });

  it('renders feedback, notes, provider names and file names as text, never markup', async () => {
    const hostile = '<script>alert(1)</script> 🎰 Лото';
    renderTask({
      rounds: [
        makeRound({
          note: hostile,
          decision: 'revisions_requested',
          decisionReason: hostile,
          decidedAt: '2026-11-10T10:00:00.000Z',
          files: [{ id: 5, name: hostile, size: 10, mime: 'image/png' }],
          aiUsage: [{ provider: hostile, credits: '1.00' }],
        }),
      ],
    });
    const rounds = await region('Revision rounds');
    expect(
      within(rounds).getAllByText(new RegExp('<script>alert\\(1\\)</script> 🎰 Лото')).length,
    ).toBe(4);
    expect(document.querySelector('script')).toBeNull();
  });
});

describe('actions mount point', () => {
  it('shows no action button to anyone for now', async () => {
    renderTask(REVIEW_TASK, { me: makeMe({ role: 'manager' }) });
    await region('Revision rounds');
    expect(
      screen.queryByRole('button', {
        name: /approve|submit|pick up|request revisions|cancel|release/i,
      }),
    ).not.toBeInTheDocument();
  });
});

describe('language', () => {
  it('shows labels, durations and dates in Russian', async () => {
    renderTask(REVIEW_TASK, { me: makeMe({ locale: 'ru' }) });
    const panel = await screen.findByRole('region', { name: 'Факты' });
    const term = (/** @type {string} */ label) =>
      /** @type {HTMLElement} */ (
        within(panel).getByText(label, { selector: 'dt' }).nextElementSibling
      );
    expect(term('Создатель').textContent).toBe('Dmitry P.');
    expect(term('Исполнитель').textContent).toBe('Anna K.');
    expect(term('Время в очереди').textContent).toBe('3 ч 10 мин');
    expect(term('Время цикла').textContent).toBe('1 д 4 ч');
    expect(term('Время проверки').textContent).toBe('1 ч 30 мин');
    expect(term('Время на правки').textContent).toBe('2 ч');
    expect(term('Кредиты ИИ').textContent).toBe('220,00');
    expect(term('Дедлайн').textContent).toBe(formatDateTime('2099-11-12T12:00:00Z', 'ru'));
    expect(within(panel).getByText('Прошедшее время, а не затраченные часы.')).toBeInTheDocument();
    expect(await screen.findByRole('heading', { name: 'Раунд 1' })).toBeInTheDocument();
    expect(screen.getByText('Ожидает проверки')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'История активности' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/владел/i);
  });

  it('re-renders in Russian when the language is switched', async () => {
    renderTask(REVIEW_TASK);
    expect((await fact('Created by')).textContent).toBe('Dmitry P.');
    await act(() => i18n.changeLanguage('ru'));
    expect(await screen.findByRole('region', { name: 'Факты' })).toBeInTheDocument();
    expect(screen.queryByText('Created by')).not.toBeInTheDocument();
    expect(screen.getByText('Создатель')).toBeInTheDocument();
  });
});
