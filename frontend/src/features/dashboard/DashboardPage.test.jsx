import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { EMPTY_BOARD_TABLE } from '../../test/board';
import { NOW_MS, dashboardPath, makeDashboard, makeEmptyDashboard } from '../../test/dashboard';
import { errorResponse, makeMe, mockResponse } from '../../test/http';
import { renderApp } from '../../test/renderApp';

jest.mock('recharts', () => require('../../test/rechartsMock').rechartsMock());

const manager = (/** @type {object} */ overrides = {}) =>
  makeMe({ id: 2, role: 'manager', displayName: 'Max Ivanov', ...overrides });

/**
 * @param {{dashboard?: ReturnType<typeof makeDashboard>, table?: Record<string, any>, me?: ReturnType<typeof makeMe>, path?: string}} [options]
 */
function renderDashboard({
  dashboard = makeDashboard(),
  table = {},
  me = manager(),
  path = '/dashboard',
} = {}) {
  return renderApp(path, {
    me,
    table: {
      ...EMPTY_BOARD_TABLE,
      [`GET ${dashboardPath('all')}`]: mockResponse(200, dashboard),
      ...table,
    },
  });
}

/** A section once the dashboard is loaded. */
const section = async (/** @type {string | RegExp} */ name) => {
  const region = await screen.findByRole('region', { name });
  return region;
};
const tile = async (/** @type {string} */ key) => {
  await screen.findByTestId(`tile-${key}`);
  return screen.getByTestId(`tile-${key}`);
};
/** @param {HTMLElement} element */
const cellTexts = (element) =>
  within(element)
    .getAllByRole('row')
    .map((row) =>
      within(row)
        .queryAllByRole('cell')
        .map((cell) => cell.textContent),
    )
    .filter((cells) => cells.length > 0);

// The page is a lazy chunk (with the chart library): load it once up front, so the first test
// does not spend its findBy timeout on the import (it ran out once, on a busy machine).
beforeAll(async () => {
  await import('./DashboardPage');
});

beforeEach(() => {
  setCsrfToken(null);
  jest.spyOn(Date, 'now').mockReturnValue(NOW_MS);
});
afterEach(() => {
  jest.restoreAllMocks();
});

describe('a full dashboard', () => {
  it('shows the title and the five stat tiles', async () => {
    renderDashboard();
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Team overview' }),
    ).toBeInTheDocument();
    const total = await tile('total');
    expect(total).toHaveTextContent('Total tasks');
    expect(total).toHaveTextContent('16');
    expect(total).toHaveTextContent('2 cancelled');
    const active = await tile('active');
    expect(active).toHaveTextContent('Active');
    expect(active).toHaveTextContent('7');
    expect(active).toHaveTextContent('in progress, review, revisions');
    const overdue = await tile('overdue');
    expect(overdue).toHaveTextContent('Overdue');
    expect(overdue).toHaveTextContent('2');
    const cycle = await tile('cycle');
    expect(cycle).toHaveTextContent('Average completion (cycle) time');
    expect(cycle).toHaveTextContent('3 d 5 h');
    expect(cycle).toHaveTextContent('4 completed tasks');
    const ai = await tile('ai');
    expect(ai).toHaveTextContent('AI credits used');
    expect(ai).toHaveTextContent('1,240.50');
    expect(ai).toHaveTextContent('3 providers');
  });

  it('shows the tasks by status as a legend with counts and a note about cancelled ones', async () => {
    renderDashboard();
    const region = await section('Tasks by status');
    const legend = within(region).getByTestId('status-legend');
    expect(legend).toHaveTextContent('New 3');
    expect(legend).toHaveTextContent('In progress 3');
    expect(legend).toHaveTextContent('Under review 2');
    expect(legend).toHaveTextContent('Revisions required 2');
    expect(legend).toHaveTextContent('Completed 4');
    expect(region).toHaveTextContent('+2 cancelled');
    expect(within(region).getByTestId('chart')).toBeInTheDocument();
    const table = within(region).getByRole('table', { name: 'Number of tasks in each status' });
    expect(cellTexts(table)).toEqual([
      ['New', '3'],
      ['In progress', '3'],
      ['Under review', '2'],
      ['Revisions required', '2'],
      ['Completed', '4'],
      ['Cancelled', '2'],
    ]);
  });

  it('shows where the time goes, as elapsed time', async () => {
    renderDashboard();
    const region = await section('Where time goes (averages)');
    expect(cellTexts(within(region).getByRole('table'))).toEqual([
      ['Queue wait', '5 h 40 m'],
      ['Cycle time (pickup → approval)', '3 d 5 h'],
      ['Review time per round', '7 h 15 m'],
      ['Revision turnaround', '1 d 2 h'],
    ]);
    expect(region).toHaveTextContent('Elapsed time, not hours worked.');
  });

  it('lists the designer workload, overdue counts in the danger colour only when above zero', async () => {
    renderDashboard();
    const region = await section('Designer workload');
    const table = within(region).getByRole('table');
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((header) => header.textContent),
    ).toEqual(['Designer', 'In progress', 'Revisions', 'In review', 'Overdue']);
    expect(cellTexts(table)).toEqual([
      ['Anna K.', '1', '1', '1', '1'],
      ['Igor S.', '1', '0', '1', '0'],
      ['Maria T.', '1', '1', '0', '1'],
    ]);
    const overdueOf = (/** @type {string} */ name) =>
      within(within(table).getByRole('row', { name: new RegExp(name) })).getAllByRole('cell')[4];
    expect(overdueOf('Anna K.')).toHaveAttribute('data-danger', 'true');
    expect(overdueOf('Igor S.')).not.toHaveAttribute('data-danger');
  });

  it('hints that the workload table scrolls sideways (shown by CSS on narrow screens only)', async () => {
    renderDashboard();
    const region = await section('Designer workload');
    const hint = within(region).getByText('Scroll sideways →');
    expect(hint).toHaveClass('dashboard-section__scroll-hint');
    // Decoration for sighted users: the table itself is complete for assistive technology.
    expect(hint).toHaveAttribute('aria-hidden', 'true');
  });

  it('lists the overdue tasks with a link, the assignee, the status and how late', async () => {
    renderDashboard();
    const region = await section('Overdue tasks');
    const items = within(region).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    const first = within(items[0]);
    expect(first.getByRole('link', { name: 'Мега Спин' })).toHaveAttribute('href', '/tasks/11');
    expect(items[0]).toHaveTextContent('Maria T.');
    expect(items[0]).toHaveTextContent('Revisions required');
    expect(items[0]).toHaveTextContent('2 d late');
    expect(items[1]).toHaveTextContent('Лото Клуб');
    expect(items[1]).toHaveTextContent('Unassigned');
    expect(items[1]).toHaveTextContent('New');
    expect(items[1]).toHaveTextContent('1 d 5 h late');
    expect(within(region).queryByText(/^Showing/)).not.toBeInTheDocument();
  });

  it('says how many overdue tasks it shows when there are more than the list holds', async () => {
    const base = makeDashboard().overdue.tasks[0];
    const tasks = Array.from({ length: 50 }, (_, index) => ({
      ...base,
      id: 100 + index,
      offer: `Offer ${index}`,
    }));
    renderDashboard({ dashboard: makeDashboard({ overdue: { count: 73, tasks } }) });
    const region = await section('Overdue tasks');
    expect(within(region).getAllByRole('listitem')).toHaveLength(50);
    expect(region).toHaveTextContent('Showing 50 of 73');
    expect(await tile('overdue')).toHaveTextContent('73');
  });

  it('shows the AI credits by provider as a chart with an accessible table, biggest first', async () => {
    renderDashboard();
    const region = await section('AI credits by provider');
    expect(within(region).getByTestId('chart')).toBeInTheDocument();
    const table = within(region).getByRole('table', { name: 'Credits used per AI provider' });
    expect(cellTexts(table)).toEqual([
      ['Runway', '520.00'],
      ['Midjourney', '330.50'],
      ['Kling', '210.00'],
    ]);
  });

  it('shows the AI credits by designer, split by provider, with a total per designer', async () => {
    renderDashboard();
    const region = await section('AI credits by designer');
    expect(within(region).getByTestId('chart')).toBeInTheDocument();
    const table = within(region).getByRole('table', {
      name: 'Credits used per designer and AI provider',
    });
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((header) => header.textContent),
    ).toEqual(['Designer', 'Kling', 'Midjourney', 'Runway', 'Total']);
    expect(cellTexts(table)).toEqual([
      ['Maria T.', '130.00', '330.50', '170.00', '630.50'],
      ['Anna K.', '260.00', '0.00', '350.00', '610.00'],
    ]);
  });

  it('writes the credits at the end of each bar and the names on the axis, as text', async () => {
    renderDashboard();
    const texts = async (/** @type {string} */ name) => {
      const region = await section(name);
      return [...within(region).getByTestId('chart').querySelectorAll('svg text')].map(
        (text) => text.textContent,
      );
    };
    expect(await texts('AI credits by provider')).toEqual(
      expect.arrayContaining(['Runway', '520.00', 'Midjourney', '330.50', 'Kling', '210.00']),
    );
    expect(await texts('AI credits by designer')).toEqual(
      expect.arrayContaining(['Maria T.', '630.50', 'Anna K.', '610.00']),
    );
  });

  it("writes each designer's total on that designer's bar, also without the top provider", async () => {
    // The chart leaves out a zero-width segment, so a designer who has not used the provider on
    // top of the stack has no segment there to carry the label.
    const designer = (
      /** @type {number} */ designerId,
      /** @type {string} */ name,
      /** @type {{provider: string, credits: string}[]} */ byProvider,
      /** @type {string} */ total,
    ) => ({ designerId, name, total, byProvider });
    renderDashboard({
      dashboard: makeDashboard({
        ai: {
          total: '930.50',
          byProvider: [
            { provider: 'Runway', credits: '270.00' },
            { provider: 'Midjourney', credits: '660.50' },
          ],
          byDesigner: [
            designer(3, 'Anna K.', [{ provider: 'Runway', credits: '100.00' }], '100.00'),
            designer(5, 'Igor S.', [{ provider: 'Midjourney', credits: '200.00' }], '200.00'),
            designer(
              7,
              'Maria T.',
              [
                { provider: 'Runway', credits: '170.00' },
                { provider: 'Midjourney', credits: '460.50' },
              ],
              '630.50',
            ),
          ],
        },
      }),
    });
    const chart = within(await section('AI credits by designer')).getByTestId('chart');
    const rowOf = (/** @type {string} */ text) => {
      const element = [...chart.querySelectorAll('svg text')].find(
        (candidate) => candidate.textContent === text,
      );
      return element === undefined ? undefined : Math.round(Number(element.getAttribute('y')));
    };
    const labels = [...chart.querySelectorAll('svg text')]
      .map((text) => text.textContent ?? '')
      .filter((text) => /^\d+\.\d\d$/.test(text));
    expect(labels.sort()).toEqual(['100.00', '200.00', '630.50']);
    for (const [name, total] of [
      ['Maria T.', '630.50'],
      ['Igor S.', '200.00'],
      ['Anna K.', '100.00'],
    ]) {
      expect(rowOf(total)).toBeDefined();
      expect(rowOf(total)).toBe(rowOf(name));
    }
  });

  it('colours a provider the same in both charts', async () => {
    renderDashboard();
    const byProvider = await section('AI credits by provider');
    const byDesigner = await section('AI credits by designer');
    for (const provider of ['Runway', 'Midjourney', 'Kling']) {
      const swatch = (/** @type {HTMLElement} */ region) =>
        region
          .querySelector(`[data-provider="${provider}"] [data-color]`)
          ?.getAttribute('data-color');
      expect(swatch(byProvider)).toMatch(/^var\(--mantine-color-/);
      expect(swatch(byDesigner)).toBe(swatch(byProvider));
    }
    const colors = ['Runway', 'Midjourney', 'Kling'].map((provider) =>
      byProvider
        .querySelector(`[data-provider="${provider}"] [data-color]`)
        ?.getAttribute('data-color'),
    );
    expect(new Set(colors).size).toBe(3);
  });

  it('keeps a provider colour when another period has fewer providers', async () => {
    const full = makeDashboard();
    const week = makeDashboard({
      ai: {
        total: '350.00',
        byProvider: [{ provider: 'Runway', credits: '350.00' }],
        byDesigner: [
          {
            designerId: 3,
            name: 'Anna K.',
            total: '350.00',
            byProvider: [{ provider: 'Runway', credits: '350.00' }],
          },
        ],
      },
    });
    renderDashboard({
      dashboard: full,
      table: { [`GET ${dashboardPath('7d')}`]: mockResponse(200, week) },
    });
    const colorsIn = (/** @type {HTMLElement} */ region) =>
      Object.fromEntries(
        [...region.querySelectorAll('[data-provider]')].map((item) => [
          item.getAttribute('data-provider'),
          item.querySelector('[data-color]')?.getAttribute('data-color'),
        ]),
      );
    const before = colorsIn(await section('AI credits by provider'));
    expect(Object.keys(before).sort()).toEqual(['Kling', 'Midjourney', 'Runway']);

    await userEvent.click(screen.getByRole('radio', { name: '7 days' }));
    await waitFor(() =>
      expect(colorsIn(screen.getByRole('region', { name: 'AI credits by provider' }))).toEqual({
        Runway: before.Runway,
      }),
    );
    expect(colorsIn(screen.getByRole('region', { name: 'AI credits by designer' }))).toEqual({
      Runway: before.Runway,
    });
    // The bars carry the same colour as the legend.
    const fills = [
      ...screen
        .getByRole('region', { name: 'AI credits by provider' })
        .querySelectorAll('path.recharts-rectangle'),
    ].map((path) => path.getAttribute('fill'));
    expect(fills).toEqual([before.Runway]);
  });

  it('has one h1 and the sections as h2', async () => {
    renderDashboard();
    await section('Overdue tasks');
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    // The page header is not a second banner landmark next to the app header.
    expect(screen.getAllByRole('banner')).toHaveLength(1);
    expect(
      screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent),
    ).toEqual([
      'Tasks by status',
      'Where time goes (averages)',
      'Designer workload',
      'Overdue tasks',
      'AI credits by provider',
      'AI credits by designer',
    ]);
  });
});

describe('stat tiles', () => {
  it('gives every tile a decorative icon and keeps its test id and dt/dd structure', async () => {
    renderDashboard();
    await tile('total');
    for (const key of ['total', 'active', 'overdue', 'cycle', 'ai']) {
      const element = screen.getByTestId(`tile-${key}`);
      expect(element.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
      expect(element.querySelector('dt')).not.toBeNull();
      expect(element.querySelector('dd')).not.toBeNull();
    }
  });
});

describe('nothing to show yet', () => {
  it('shows zeros, dashes and "No data", never NaN or undefined', async () => {
    renderDashboard({ dashboard: makeEmptyDashboard() });
    expect(await tile('total')).toHaveTextContent('0');
    expect(screen.getByTestId('tile-total')).toHaveTextContent('0 cancelled');
    expect(screen.getByTestId('tile-active')).toHaveTextContent('0');
    expect(screen.getByTestId('tile-overdue')).toHaveTextContent('0');
    expect(screen.getByTestId('tile-cycle')).toHaveTextContent('—');
    expect(screen.getByTestId('tile-cycle')).toHaveTextContent('0 completed tasks');
    expect(screen.getByTestId('tile-ai')).toHaveTextContent('0.00');
    expect(screen.getByTestId('tile-ai')).toHaveTextContent('0 providers');

    const status = await section('Tasks by status');
    expect(status).toHaveTextContent('No data');
    expect(within(status).queryByTestId('chart')).not.toBeInTheDocument();

    const time = await section('Where time goes (averages)');
    expect(cellTexts(within(time).getByRole('table'))).toEqual([
      ['Queue wait', '—'],
      ['Cycle time (pickup → approval)', '—'],
      ['Review time per round', '—'],
      ['Revision turnaround', '—'],
    ]);

    expect(await section('Designer workload')).toHaveTextContent('No data');
    expect(await section('Overdue tasks')).toHaveTextContent('No data');
    for (const name of ['AI credits by provider', 'AI credits by designer']) {
      const region = await section(name);
      expect(region).toHaveTextContent('No data');
      expect(within(region).queryByTestId('chart')).not.toBeInTheDocument();
    }

    expect(screen.getByTestId('tile-overdue')).not.toHaveAttribute('data-danger');
    expect(document.body).not.toHaveTextContent(/NaN|undefined|Infinity/);
  });

  it('shows "No data" in the AI charts when providers exist but nothing was spent', async () => {
    renderDashboard({
      dashboard: makeDashboard({
        ai: {
          total: '0.00',
          byProvider: [{ provider: 'Runway', credits: '0.00' }],
          byDesigner: [
            {
              designerId: 3,
              name: 'Anna K.',
              total: '0.00',
              byProvider: [{ provider: 'Runway', credits: '0.00' }],
            },
          ],
        },
      }),
    });
    for (const name of ['AI credits by provider', 'AI credits by designer']) {
      const region = await section(name);
      expect(region).toHaveTextContent('No data');
      expect(within(region).queryByTestId('chart')).not.toBeInTheDocument();
    }
  });
});

describe('overdue styling', () => {
  it('marks the overdue tile as danger only when something is overdue', async () => {
    renderDashboard();
    expect(await tile('overdue')).toHaveAttribute('data-danger', 'true');
  });

  it('keeps the overdue tile calm at zero', async () => {
    renderDashboard({ dashboard: makeDashboard({ overdue: { count: 0, tasks: [] } }) });
    expect(await tile('overdue')).not.toHaveAttribute('data-danger');
  });
});

describe('the period', () => {
  it('defaults to All time and asks for period=all', async () => {
    const { fetchMock } = renderDashboard();
    await tile('total');
    expect(screen.getByRole('radio', { name: 'All time' })).toBeChecked();
    const urls = fetchMock.mock.calls
      .map(([url]) => url)
      .filter((url) => url.includes('analytics'));
    expect(urls).toEqual([dashboardPath('all')]);
    expect(screen.getByRole('radiogroup', { name: 'Period' })).toBeInTheDocument();
    expect(
      within(screen.getByRole('radiogroup', { name: 'Period' }))
        .getAllByRole('radio')
        .map((radio) => radio.getAttribute('value')),
    ).toEqual(['7d', '30d', 'all']);
  });

  it('requests the chosen period and keeps the old numbers on screen while it loads', async () => {
    /** @type {(value: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const held = new Promise((resolve) => {
      release = resolve;
    });
    const week = makeDashboard({
      totals: { ...makeDashboard().totals, total: 5 },
    });
    const { fetchMock } = renderDashboard({
      table: { [`GET ${dashboardPath('7d')}`]: () => held },
    });
    await tile('total');
    await userEvent.click(screen.getByRole('radio', { name: '7 days' }));

    await waitFor(() =>
      expect(fetchMock.mock.calls.map(([url]) => url)).toContain(dashboardPath('7d')),
    );
    // The old numbers stay, flagged as refreshing, instead of a blank page.
    expect(screen.getByTestId('tile-total')).toHaveTextContent('16');
    expect(screen.getByTestId('dashboard')).toHaveAttribute('data-refreshing', 'true');
    expect(screen.queryByTestId('dashboard-skeleton')).not.toBeInTheDocument();

    await act(async () => {
      release(mockResponse(200, week));
      await held;
    });
    await waitFor(() => expect(screen.getByTestId('tile-total')).toHaveTextContent('5'));
    expect(screen.getByTestId('dashboard')).not.toHaveAttribute('data-refreshing');
    expect(screen.getByRole('radio', { name: '7 days' })).toBeChecked();
  });

  it('asks for 30d too, and for all again from the cache', async () => {
    const { fetchMock } = renderDashboard({
      table: { [`GET ${dashboardPath('30d')}`]: mockResponse(200, makeDashboard()) },
    });
    await tile('total');
    await userEvent.click(screen.getByRole('radio', { name: '30 days' }));
    await waitFor(() =>
      expect(fetchMock.mock.calls.map(([url]) => url)).toContain(dashboardPath('30d')),
    );
    await waitFor(() => expect(screen.getByRole('radio', { name: '30 days' })).toBeChecked());
  });
});

describe('opening a designer', () => {
  it('opens the board of the designer from the name link', async () => {
    const { router } = renderDashboard();
    const region = await section('Designer workload');
    const link = within(region).getByRole('link', { name: 'Maria T.' });
    expect(link).toHaveAttribute('href', '/board?designerId=7');
    await userEvent.click(link);
    await waitFor(() => expect(router.state.location.pathname).toBe('/board'));
    expect(router.state.location.search).toBe('?designerId=7');
  });

  it('opens the board of the designer from anywhere in the row', async () => {
    const { router } = renderDashboard();
    const region = await section('Designer workload');
    const row = within(region).getByRole('row', { name: /Igor S\./ });
    await userEvent.click(within(row).getAllByRole('cell')[2]);
    await waitFor(() => expect(router.state.location.pathname).toBe('/board'));
    expect(router.state.location.search).toBe('?designerId=5');
  });
});

describe('the overdue tasks', () => {
  it('link to the task from the offer', async () => {
    renderDashboard();
    const region = await section('Overdue tasks');
    expect(within(region).getByRole('link', { name: 'Лото Клуб' })).toHaveAttribute(
      'href',
      '/tasks/12',
    );
  });
});

describe('language', () => {
  it('is in Russian, with Russian numbers, durations, credits and plurals', async () => {
    renderDashboard({ me: manager({ locale: 'ru' }) });
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Обзор команды' }),
    ).toBeInTheDocument();
    const total = await tile('total');
    expect(total).toHaveTextContent('Всего задач');
    expect(total).toHaveTextContent('2 отменённые');
    expect(screen.getByTestId('tile-active')).toHaveTextContent('Активные');
    expect(screen.getByTestId('tile-overdue')).toHaveTextContent('Просрочено');
    const cycle = screen.getByTestId('tile-cycle');
    expect(cycle).toHaveTextContent('Среднее время завершения (цикл)');
    expect(cycle).toHaveTextContent('3 д 5 ч');
    expect(cycle).toHaveTextContent('4 завершённые задачи');
    const ai = screen.getByTestId('tile-ai');
    expect(ai).toHaveTextContent('Использовано кредитов ИИ');
    expect(ai.textContent).toMatch(/1\s240,50/);
    expect(ai).toHaveTextContent('3 провайдера');

    expect(await section('Задачи по статусам')).toBeInTheDocument();
    const time = await section('Куда уходит время (средние значения)');
    expect(time).toHaveTextContent('5 ч 40 мин');
    expect(await section('Нагрузка дизайнеров')).toBeInTheDocument();
    const overdue = await section('Просроченные задачи');
    expect(overdue).toHaveTextContent('Без исполнителя');
    expect(overdue).toHaveTextContent('Новая');
    expect(overdue).toHaveTextContent('2 д');
    const providers = await section('Кредиты ИИ по провайдерам');
    expect(within(providers).getByRole('table')).toHaveTextContent('520,00');
    expect(await section('Кредиты ИИ по дизайнерам')).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Всё время' })).toBeChecked();
    expect(screen.getByRole('radio', { name: '7 дней' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: '30 дней' })).toBeInTheDocument();
  });
});

describe('loading and failure', () => {
  it('shows skeletons while the first answer is on its way', async () => {
    /** @type {(value: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const held = new Promise((resolve) => {
      release = resolve;
    });
    renderDashboard({ table: { [`GET ${dashboardPath('all')}`]: () => held } });
    expect(await screen.findByTestId('dashboard-skeleton')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Team overview' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'All time' })).toBeInTheDocument();
    await act(async () => {
      release(mockResponse(200, makeDashboard()));
      await held;
    });
    await tile('total');
    expect(screen.queryByTestId('dashboard-skeleton')).not.toBeInTheDocument();
  });

  it('shows an error with Try again, and recovers', async () => {
    let calls = 0;
    renderDashboard({
      table: {
        [`GET ${dashboardPath('all')}`]: () => {
          calls += 1;
          return calls === 1 ? errorResponse(403, 'forbidden') : mockResponse(200, makeDashboard());
        },
      },
    });
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Could not load the dashboard');
    expect(screen.getByRole('heading', { level: 1, name: 'Team overview' })).toBeInTheDocument();
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    await tile('total');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});

describe('access', () => {
  it.each([
    ['buyer', '/board'],
    ['designer', '/my-work'],
  ])('sends a %s away without asking for the dashboard', async (role, landing) => {
    const { router, fetchMock } = renderDashboard({
      me: makeMe({ role: /** @type {'buyer' | 'designer'} */ (role) }),
    });
    await waitFor(() => expect(router.state.location.pathname).toBe(landing));
    expect(screen.queryByTestId('dashboard')).not.toBeInTheDocument();
    expect(fetchMock.mock.calls.filter(([url]) => url.includes('analytics'))).toHaveLength(0);
  });
});
