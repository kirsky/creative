import { test as base } from '@playwright/test';
import { openAs, safeScreenshot } from './app';

/** @typedef {import('@playwright/test').Page} Page */
/** @typedef {import('@playwright/test').PlaywrightTestArgs & import('@playwright/test').PlaywrightTestOptions} TestArgs */
/** @typedef {import('@playwright/test').PlaywrightWorkerArgs & import('@playwright/test').PlaywrightWorkerOptions} WorkerArgs */

/**
 * @typedef {object} Actors
 * @property {(username: import('./credentials').Username, lng: import('./i18n').Language, options?: import('@playwright/test').BrowserContextOptions) => Promise<Page>} open
 */

/**
 * `actors.open(username, lng)` gives a signed-in page in its own browser context (one per person
 * of a story). When a test fails, every open page is captured (password fields emptied first),
 * and all contexts are closed afterwards.
 * @type {import('@playwright/test').Fixtures<{actors: Actors}, {}, TestArgs, WorkerArgs>}
 */
const fixtures = {
  actors: async ({ browser }, use, testInfo) => {
    /** @type {{name: string, context: import('@playwright/test').BrowserContext, page: Page}[]} */
    const opened = [];
    await use({
      open: async (username, lng, options) => {
        const actor = await openAs(browser, username, lng, options);
        opened.push({ name: username, ...actor });
        return actor.page;
      },
    });
    if (testInfo.status !== testInfo.expectedStatus) {
      for (const { name, page } of opened) {
        if (!page.isClosed()) {
          const path = testInfo.outputPath(`failure-${name}.png`);
          try {
            await safeScreenshot(page, path);
            await testInfo.attach(`failure-${name}`, { path, contentType: 'image/png' });
          } catch {
            // The page may be gone with the failure; the error itself is what matters.
          }
        }
      }
    }
    await Promise.all(opened.map(({ context }) => context.close()));
  },
};

export const test = base.extend(fixtures);

export { expect } from '@playwright/test';
