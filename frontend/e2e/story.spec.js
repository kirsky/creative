import { checkPage } from './support/app';
import { ACCOUNTS } from './support/credentials';
import { expect, test } from './support/fixtures';
import { LANGUAGES, tr } from './support/i18n';
import { pngFile } from './support/png';

/**
 * The five-minute story of the spec (sections 1-2), through the real interface, three people in
 * three browsers: Dmitry (buyer) briefs, Anna (designer) delivers, Olga (manager) watches.
 */

/** A few strings written out, so a wrong lookup in `tr` cannot hide a wrong language. */
const LITERALS = {
  en: { board: 'Board', completed: 'Completed', history: 'Activity history', queue: 'Queue wait' },
  ru: {
    board: 'Доска',
    completed: 'Готово',
    history: 'История активности',
    queue: 'Время в очереди',
  },
};

const ANNA = ACCOUNTS.anna.displayName;
const DMITRY = ACCOUNTS.dmitry.displayName;
/** The AI credits the story adds: Runway 120 + Midjourney 40 in round 1, nothing in round 2. */
const STORY_CREDITS_CENTS = 16000n;

/**
 * Exact decimal arithmetic for credit strings ("1234.5", "160.00"), in cents.
 * @param {string} value
 */
function toCents(value) {
  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(value);
  if (match === null) {
    throw new Error(`Not a credit amount: "${value}"`);
  }
  const cents = BigInt(match[2]) * 100n + BigInt((match[3] ?? '0').padEnd(2, '0'));
  return match[1] === '-' ? -cents : cents;
}

/**
 * A number as the app formats it (Intl.NumberFormat), back to a plain decimal string.
 * @param {string} text
 * @param {'en' | 'ru'} lng
 */
function unformat(text, lng) {
  const digits = text.replace(/[\s\u00a0\u202f]/g, '');
  return lng === 'ru' ? digits.replace(',', '.') : digits.replace(/,/g, '');
}

/** @param {number} value @param {'en' | 'ru'} lng */
function credits(value, lng) {
  return new Intl.NumberFormat(lng, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    value,
  );
}

/**
 * Opens the dashboard and reads Anna's AI credits from what the page received and from the
 * by-designer table (the chart's accessible twin); the two must agree.
 * @param {import('@playwright/test').Page} page
 * @param {'en' | 'ru'} lng
 */
async function readDashboard(page, lng) {
  const response = page.waitForResponse(
    (candidate) =>
      candidate.url().includes('/api/analytics/dashboard') && candidate.status() === 200,
  );
  await page.goto('/dashboard');
  /** @type {import('../src/features/dashboard/api').Dashboard} */
  const data = await (await response).json();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(tr(lng, 'dashboard:title'));
  const fromApi = data.ai.byDesigner.find((designer) => designer.name === ANNA)?.total ?? '0.00';

  const table = page.locator('table').filter({
    has: page.locator('caption', { hasText: tr(lng, 'dashboard:ai.byDesigner.caption') }),
  });
  const row = table.locator('tbody tr').filter({ hasText: ANNA });
  let fromTable = '0.00';
  if ((await row.count()) > 0) {
    fromTable = unformat((await row.locator('td').last().innerText()).trim(), lng);
    expect(toCents(fromTable)).toBe(toCents(fromApi));
  }
  await checkPage(page, lng);
  return { annaCents: toCents(fromTable), completed: data.totals.byStatus.completed };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {'en' | 'ru'} lng
 * @param {string} key a key of task:side
 */
function fact(page, lng, key) {
  return page
    .locator('.task-side dt', { hasText: tr(lng, `task:side.${key}`) })
    .locator('xpath=following-sibling::dd[1]');
}

/**
 * Submits work from the task page: files, a note, AI lines.
 * @param {import('@playwright/test').Page} page
 * @param {'en' | 'ru'} lng
 * @param {{files: ReturnType<typeof pngFile>[], note: string, ai: [string, string][]}} work
 */
async function submitWork(page, lng, { files, note, ai }) {
  await page.getByRole('button', { name: tr(lng, 'task:actions.submit'), exact: true }).click();
  const dialog = page.getByRole('dialog', { name: tr(lng, 'task:actions.submitDialog.title') });
  await expect(dialog).toBeVisible();
  await dialog.locator('input[type="file"]').setInputFiles(files);
  await expect(
    dialog.getByText(tr(lng, 'task:create.references.status.uploaded'), { exact: true }),
  ).toHaveCount(files.length);
  await dialog.getByLabel(tr(lng, 'task:actions.submitDialog.note')).fill(note);
  for (const [index, [provider, amount]] of ai.entries()) {
    await dialog.getByRole('button', { name: tr(lng, 'task:actions.aiUsage.add') }).click();
    const number = index + 1;
    await dialog
      .getByLabel(tr(lng, 'task:actions.aiUsage.providerRow', { number }), { exact: true })
      .fill(provider);
    await page.getByRole('option', { name: provider, exact: true }).click();
    await dialog
      .getByLabel(tr(lng, 'task:actions.aiUsage.creditsRow', { number }), { exact: true })
      .fill(amount);
  }
  await checkPage(page, lng);
  await dialog
    .getByRole('button', { name: tr(lng, 'task:actions.submitDialog.submit'), exact: true })
    .click();
  await expect(dialog).toBeHidden();
  await expect(fact(page, lng, 'stage')).toHaveText(tr(lng, 'board:card.status.under_review'));
}

for (const lng of LANGUAGES) {
  test(`five-minute story (${lng})`, async ({ actors }) => {
    const stamp = Date.now();
    const offer = lng === 'en' ? `E2E Story EN ${stamp}` : `E2E История RU ${stamp}`;
    const description =
      lng === 'en'
        ? `Spin 🎰 slot, bright colours. <script>window.__e2eXss = 1</script> End.`
        : `Слот 🎰, яркие цвета. <script>window.__e2eXss = 1</script> Конец.`;
    const reason = lng === 'en' ? 'Make the logo bigger, please.' : 'Сделайте логотип крупнее.';
    const country = lng === 'en' ? 'Germany' : 'Германия';
    const L = LITERALS[lng];

    /** @type {{annaCents: bigint, completed: number}} */
    let before = { annaCents: 0n, completed: 0 };
    /** @type {import('@playwright/test').Page} */
    let manager;
    await test.step('the manager notes the AI credits before the story', async () => {
      manager = await actors.open('manager', lng);
      before = await readDashboard(manager, lng);
    });

    /** @type {import('@playwright/test').Page} */
    let dmitry;
    let taskPath = '';
    await test.step('Dmitry writes the brief', async () => {
      dmitry = await actors.open('dmitry', lng);
      await expect(dmitry.locator('header nav')).toContainText(L.board);
      await checkPage(dmitry, lng);
      await dmitry.locator('header nav').getByText(tr(lng, 'common:nav.newTask')).click();
      await expect(dmitry).toHaveURL(/\/tasks\/new$/);
      const form = dmitry.locator('form.create-task__card');

      await form.getByLabel(tr(lng, 'task:create.offer.label')).fill(offer);
      await form.getByLabel(tr(lng, 'task:create.creativeType.label')).click();
      await dmitry
        .getByRole('option', { name: tr(lng, 'task:creativeTypes.static_image'), exact: true })
        .click();
      await form.getByLabel(tr(lng, 'task:create.quantity.label')).fill('3');
      await form.getByText(tr(lng, 'task:priorities.high'), { exact: true }).click();
      await form.getByLabel(tr(lng, 'task:create.geo.label')).fill(country.slice(0, 4));
      await dmitry.getByRole('option', { name: country, exact: true }).click();
      await dmitry.keyboard.press('Escape');
      await form.getByLabel(tr(lng, 'task:create.description.label')).fill(description);

      // A deadline from the picker: the 15th of next month at the default time.
      await form.getByLabel(tr(lng, 'task:create.deadline.label')).click();
      const picker = dmitry.locator('[data-dates-dropdown="true"]');
      await picker.locator('[data-direction="next"]').first().click();
      await picker.locator('button:not([data-outside])', { hasText: /^15$/ }).first().click();
      await picker.getByRole('button', { name: tr(lng, 'task:create.deadline.confirm') }).click();
      await expect(picker).toBeHidden();

      await form.getByRole('button', { name: tr(lng, 'task:create.references.addLink') }).click();
      await form
        .getByRole('textbox', {
          name: tr(lng, 'task:create.references.link', { number: 1 }),
          exact: true,
        })
        .fill('https://example.com/e2e-reference');
      await form
        .locator('input[type="file"]')
        .setInputFiles(pngFile('reference.png', [40, 120, 220]));
      await expect(
        form.getByText(tr(lng, 'task:create.references.status.uploaded'), { exact: true }),
      ).toBeVisible();
      await checkPage(dmitry, lng);

      await form.getByRole('button', { name: tr(lng, 'task:create.submit'), exact: true }).click();
      await expect(dmitry).toHaveURL(/\/tasks\/\d+$/);
      taskPath = new URL(dmitry.url()).pathname;

      await expect(dmitry.getByRole('heading', { level: 1 })).toContainText(offer);
      // User text is text: the markup shows literally and nothing runs.
      await expect(dmitry.locator('.task-brief__description')).toHaveText(description);
      expect(await dmitry.locator('script', { hasText: '__e2eXss' }).count()).toBe(0);
      expect(await dmitry.evaluate(() => /** @type {any} */ (window).__e2eXss)).toBeUndefined();
      await expect(dmitry.locator('.task-brief__references')).toContainText('reference.png');
      await expect(dmitry.locator('.task-brief__references')).toContainText(
        'https://example.com/e2e-reference',
      );
      await expect(fact(dmitry, lng, 'deadline')).toContainText('15');
      await expect(fact(dmitry, lng, 'stage')).toHaveText(tr(lng, 'board:card.status.new'));
      await checkPage(dmitry, lng);
    });

    /** @type {import('@playwright/test').Page} */
    let anna;
    await test.step('Anna picks the task up from My work', async () => {
      anna = await actors.open('anna', lng);
      await expect(anna).toHaveURL(/\/my-work$/);
      const available = anna.locator('[data-section="available"]');
      await expect(available.getByRole('link', { name: offer })).toBeVisible();
      await checkPage(anna, lng);
      await available
        .getByRole('button', { name: tr(lng, 'board:card.pickUpOffer', { offer }) })
        .click();
      const assigned = anna.locator('[data-section="assigned"]');
      await expect(assigned.getByRole('link', { name: offer })).toBeVisible();
      await assigned.getByRole('link', { name: offer }).click();
      await expect(anna).toHaveURL(new RegExp(`${taskPath}$`));
      await expect(fact(anna, lng, 'assignedTo')).toHaveText(ANNA);
    });

    await test.step('Anna submits round 1 with two files and AI usage', async () => {
      await submitWork(anna, lng, {
        files: [pngFile('banner-a.png', [220, 60, 60]), pngFile('banner-b.png', [60, 180, 90])],
        note: lng === 'en' ? 'Two variants.' : 'Два варианта.',
        ai: [
          ['Runway', '120'],
          ['Midjourney', '40'],
        ],
      });
      await checkPage(anna, lng);
    });

    await test.step('Dmitry requests revisions with a reason', async () => {
      await dmitry.reload();
      await dmitry
        .getByRole('button', { name: tr(lng, 'task:actions.requestRevisions'), exact: true })
        .click();
      const dialog = dmitry.getByRole('dialog', {
        name: tr(lng, 'task:actions.revisionsDialog.title'),
      });
      await dialog.getByLabel(tr(lng, 'task:actions.revisionsDialog.reason')).fill(reason);
      await checkPage(dmitry, lng);
      await dialog
        .getByRole('button', { name: tr(lng, 'task:actions.revisionsDialog.submit'), exact: true })
        .click();
      await expect(dialog).toBeHidden();
      await expect(fact(dmitry, lng, 'stage')).toHaveText(
        tr(lng, 'board:card.status.revisions_required'),
      );
    });

    await test.step('Anna sees it in Revisions Required and resubmits', async () => {
      await anna.locator('header nav').getByText(tr(lng, 'common:nav.myWork')).click();
      await expect(anna).toHaveURL(/\/my-work$/);
      const item = anna.locator('.my-work__item[data-attention="true"]', { hasText: offer });
      await expect(item).toContainText(tr(lng, 'mywork:attention'));
      await expect(item.getByTestId('task-card-status')).toHaveText(
        tr(lng, 'board:card.status.revisions_required'),
      );
      await checkPage(anna, lng);
      await item.getByRole('link', { name: offer }).click();
      await expect(anna.locator('.round[data-decision="revisions_requested"]')).toContainText(
        reason,
      );
      await submitWork(anna, lng, {
        files: [pngFile('banner-final.png', [90, 60, 200])],
        note: lng === 'en' ? 'Bigger logo.' : 'Логотип крупнее.',
        ai: [],
      });
    });

    await test.step('Dmitry approves and the task page tells the whole story', async () => {
      await dmitry.reload();
      await dmitry
        .getByRole('button', { name: tr(lng, 'task:actions.approve'), exact: true })
        .click();
      const dialog = dmitry.getByRole('dialog', {
        name: tr(lng, 'task:actions.approveDialog.title'),
      });
      await dialog
        .getByRole('button', { name: tr(lng, 'task:actions.approveDialog.confirm'), exact: true })
        .click();
      await expect(dialog).toBeHidden();
      await dmitry.reload();

      await expect(fact(dmitry, lng, 'stage')).toHaveText(L.completed);
      await expect(dmitry.locator('.task-side')).toContainText(L.queue);
      for (const key of ['queueWait', 'cycleTime']) {
        // Measured, so a duration ("< 1 m", "2 m"), never the dash of "not measured".
        await expect(fact(dmitry, lng, key)).toHaveText(
          new RegExp(`^(${tr(lng, 'common:duration.lessThanMinute')}|\\d.*)$`),
        );
      }
      await expect(fact(dmitry, lng, 'aiCredits')).toHaveText(credits(160, lng));

      const rounds = dmitry.locator('article.round');
      await expect(rounds).toHaveCount(2);
      const first = dmitry.locator('article.round', {
        has: dmitry.getByRole('heading', { name: tr(lng, 'task:rounds.round', { round: 1 }) }),
      });
      const second = dmitry.locator('article.round', {
        has: dmitry.getByRole('heading', { name: tr(lng, 'task:rounds.round', { round: 2 }) }),
      });
      await expect(first).toHaveAttribute('data-decision', 'revisions_requested');
      await expect(first).toContainText(tr(lng, 'task:rounds.decision.revisions_requested'));
      await expect(first).toContainText(reason);
      await expect(first.locator('.round__ai-lines li')).toHaveText([
        tr(lng, 'task:rounds.ai.line', { provider: 'Runway', credits: credits(120, lng) }),
        tr(lng, 'task:rounds.ai.line', { provider: 'Midjourney', credits: credits(40, lng) }),
      ]);
      await expect(first.locator('.round__ai-total')).toHaveText(
        tr(lng, 'task:rounds.ai.subtotal', { credits: credits(160, lng) }),
      );
      await expect(second).toHaveAttribute('data-decision', 'approved');
      await expect(second).toContainText(tr(lng, 'task:rounds.decision.approved'));
      await expect(second.locator('.round__ai-lines li')).toHaveCount(0);

      await dmitry.getByRole('button', { name: L.history }).click();
      const events = dmitry.locator('.timeline__item');
      await expect(events.first()).toBeVisible();
      const types = await events.evaluateAll((items) =>
        items.map((item) => item.getAttribute('data-event-type')),
      );
      const texts = await events.getByTestId('event-text').allInnerTexts();
      // The timeline may list the newest first; either way it is the story, in order.
      if (types[0] !== 'task_created') {
        types.reverse();
        texts.reverse();
      }
      expect(types).toEqual([
        'task_created',
        'task_picked_up',
        'round_submitted',
        'ai_usage_changed',
        'revisions_requested',
        'round_submitted',
        'task_approved',
      ]);
      expect(texts).toEqual([
        tr(lng, 'task:events.task_created', { actor: DMITRY }),
        tr(lng, 'task:events.task_picked_up', { actor: ANNA }),
        tr(lng, 'task:events.round_submitted', { actor: ANNA, round: 1, count: 2 }),
        tr(lng, 'task:events.ai_usage_changed', {
          actor: ANNA,
          round: 1,
          lines: [
            tr(lng, 'task:events.aiLine', { provider: 'Runway', credits: credits(120, lng) }),
            tr(lng, 'task:events.aiLine', { provider: 'Midjourney', credits: credits(40, lng) }),
          ].join(', '),
        }),
        tr(lng, 'task:events.revisions_requested_reason', { actor: DMITRY, round: 1, reason }),
        tr(lng, 'task:events.round_submitted', { actor: ANNA, round: 2, count: 1 }),
        tr(lng, 'task:events.task_approved', { actor: DMITRY, round: 2 }),
      ]);
      await checkPage(dmitry, lng);
    });

    await test.step('the manager sees exactly 160 more AI credits and the task completed', async () => {
      const after = await readDashboard(manager, lng);
      expect(after.annaCents - before.annaCents).toBe(STORY_CREDITS_CENTS);
      expect(after.completed).toBe(before.completed + 1);

      await manager.locator('header nav').getByText(L.board, { exact: true }).click();
      await expect(manager).toHaveURL(/\/board$/);
      const completed = manager.locator('.board-column[data-status="completed"]');
      await expect(completed.getByRole('heading', { level: 2 })).toContainText(
        tr(lng, 'board:columns.completed'),
      );
      await expect(completed.getByRole('link', { name: offer })).toBeVisible();
      await checkPage(manager, lng);
    });
  });
}
