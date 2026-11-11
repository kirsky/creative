import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { checkPage, safeScreenshot } from './support/app';
import { expect, test } from './support/fixtures';

/**
 * Visual smoke: every main screen of every role, light (English) and dark (Russian), at 1280 and
 * 390 pixels wide. Full-page screenshots go to e2e/.results/screens for a person to look at (none
 * of the login form). Each screen must not scroll sideways, show no broken values, log no
 * console errors, and the dashboard's charts must have a real size (jsdom cannot measure them).
 */

const SCREENS_DIR = join(__dirname, '.results', 'screens');
const WIDE = { width: 1280, height: 800 };
const NARROW = { width: 390, height: 844 };

/**
 * Console messages that are not defects: none so far. Each entry needs its reason.
 * @type {RegExp[]}
 */
const BENIGN_CONSOLE_ERRORS = [];

/** @typedef {import('@playwright/test').Page} Page */
/**
 * @typedef {object} Screen
 * @property {string} name
 * @property {(page: Page) => Promise<void>} open navigates and waits until the screen is ready
 */

/** @param {Page} page */
async function noSkeletons(page) {
  await expect(page.locator('.mantine-Skeleton-root')).toHaveCount(0);
}

/** @type {Record<'buyer' | 'designer' | 'manager', {user: import('./support/credentials').Username, screens: Screen[]}>} */
const ROLES = {
  buyer: {
    user: 'dmitry',
    screens: [
      {
        name: 'board',
        open: async (page) => {
          await page.goto('/board');
          await expect(page.locator('.board-column')).toHaveCount(5);
          await noSkeletons(page);
        },
      },
      {
        name: 'create',
        open: async (page) => {
          await page.goto('/tasks/new');
          await expect(page.locator('form.create-task__card')).toBeVisible();
        },
      },
      {
        name: 'task',
        open: async (page) => {
          await page.goto('/board');
          // A task of the demo that has rounds, so the page is full.
          const card = page.locator('.board-column[data-status="under_review"] .task-card__link');
          await card.first().click();
          await expect(page.locator('.task-side')).toBeVisible();
          await expect(page.locator('article.round').first()).toBeVisible();
        },
      },
    ],
  },
  designer: {
    user: 'anna',
    screens: [
      {
        name: 'mywork',
        open: async (page) => {
          await page.goto('/my-work');
          await expect(page.locator('[data-section]').first()).toBeVisible();
          await noSkeletons(page);
        },
      },
      {
        name: 'board',
        open: async (page) => {
          await page.goto('/board');
          await expect(page.locator('.board-column')).toHaveCount(5);
          await noSkeletons(page);
        },
      },
      {
        name: 'task',
        open: async (page) => {
          await page.goto('/my-work');
          const mine = page.locator(
            '[data-section="assigned"] .task-card[data-status="in_progress"]',
          );
          await mine.locator('.task-card__link').first().click();
          await expect(page.locator('.task-side')).toBeVisible();
          await expect(page.locator('.action-bar')).toBeVisible();
        },
      },
    ],
  },
  manager: {
    user: 'manager',
    screens: [
      {
        name: 'dashboard',
        open: async (page) => {
          await page.goto('/dashboard');
          await expect(page.locator('.recharts-surface').first()).toBeVisible();
          await noSkeletons(page);
        },
      },
      {
        name: 'board',
        open: async (page) => {
          await page.goto('/board');
          await expect(page.locator('.board-column')).toHaveCount(5);
          await noSkeletons(page);
        },
      },
    ],
  },
};

/**
 * @param {Page} page
 * @param {string} where
 */
async function expectNoSidewaysScroll(page, where) {
  const { scrollWidth, innerWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
  }));
  expect(scrollWidth, `${where}: page wider than the window`).toBeLessThanOrEqual(innerWidth + 1);
}

/**
 * The header's parts are side by side and whole: the navigation ends before the language
 * switcher starts, and every control is inside the window and not cut.
 * @param {Page} page
 * @param {string} where
 */
async function expectHeaderFits(page, where) {
  const problems = await page.evaluate(() => {
    const found = [];
    const links = [...document.querySelectorAll('.app-nav__link')];
    const end = document.querySelector('.app-header__end');
    if (links.length === 0 || end === null) {
      return ['no header'];
    }
    const navRight = Math.max(...links.map((link) => link.getBoundingClientRect().right));
    const endLeft = end.getBoundingClientRect().left;
    if (navRight > endLeft + 0.5) {
      found.push(`navigation (to ${navRight}) runs under the controls (from ${endLeft})`);
    }
    for (const element of [...links, ...end.querySelectorAll('button, [role="radiogroup"]')]) {
      const box = element.getBoundingClientRect();
      const name = element.textContent?.trim() || element.tagName;
      if (box.width > 0 && (box.left < 0 || box.right > window.innerWidth + 0.5)) {
        found.push(`${name} is outside the window`);
      }
      if (element.scrollWidth > element.clientWidth + 1) {
        found.push(`${name} is cut`);
      }
    }
    return found;
  });
  expect(problems, `${where}: header`).toEqual([]);
}

/** @param {Page} page */
async function expectChartsSized(page) {
  const surfaces = page.locator('.recharts-surface');
  const count = await surfaces.count();
  expect(count).toBeGreaterThanOrEqual(3);
  for (let index = 0; index < count; index += 1) {
    const box = await surfaces.nth(index).boundingBox();
    expect(box?.width ?? 0).toBeGreaterThan(0);
    expect(box?.height ?? 0).toBeGreaterThan(0);
  }
}

mkdirSync(SCREENS_DIR, { recursive: true });

for (const [role, { user, screens }] of Object.entries(ROLES)) {
  for (const [scheme, lng] of /** @type {const} */ ([
    ['light', 'en'],
    ['dark', 'ru'],
  ])) {
    test(`${role} screens, ${scheme} (${lng})`, async ({ actors }) => {
      const page = await actors.open(user, lng, { colorScheme: scheme, viewport: WIDE });
      /** @type {string[]} */
      const consoleErrors = [];
      page.on('console', (/** @type {import('@playwright/test').ConsoleMessage} */ message) => {
        if (
          message.type() === 'error' &&
          !BENIGN_CONSOLE_ERRORS.some((r) => r.test(message.text()))
        ) {
          consoleErrors.push(`${page.url()}: ${message.text()}`);
        }
      });
      page.on('pageerror', (/** @type {Error} */ error) =>
        consoleErrors.push(`${page.url()}: ${error.message}`),
      );

      for (const screen of screens) {
        await test.step(screen.name, async () => {
          await page.setViewportSize(WIDE);
          await screen.open(page);
          const background = await page.evaluate(
            () => getComputedStyle(document.body).backgroundColor,
          );
          // Dark means dark: the body is not left white.
          expect(background === 'rgb(255, 255, 255)').toBe(scheme === 'light');
          await checkPage(page, lng);
          await expectNoSidewaysScroll(page, `${screen.name} at 1280`);
          await expectHeaderFits(page, `${screen.name} at 1280`);
          if (screen.name === 'dashboard') {
            await expectChartsSized(page);
          }
          await safeScreenshot(page, join(SCREENS_DIR, `${role}-${screen.name}-${scheme}.png`));

          await page.setViewportSize(NARROW);
          // Let the layout and ResponsiveContainer follow the new width.
          await page.waitForTimeout(300);
          await expectNoSidewaysScroll(page, `${screen.name} at 390`);
          await expectHeaderFits(page, `${screen.name} at 390`);
          if (screen.name === 'dashboard') {
            await expectChartsSized(page);
          }
          await checkPage(page, lng);
          await safeScreenshot(page, join(SCREENS_DIR, `${role}-${screen.name}-${scheme}-390.png`));
        });
      }
      expect(consoleErrors).toEqual([]);
    });
  }
}
