const { defineConfig, devices } = require('@playwright/test');

// End-to-end tests: run only by `make e2e CONFIRM=yes`, in the `playwright` container, against the
// running stack (see the Makefile for what that does to the data and the passwords).
//
// Passwords: they come from E2E_CREDENTIALS and are never stored. What keeps them out of the
// results is this configuration and the suite: traces and videos would keep what was typed, so
// they are off; there is no HTML report (it packs its data compressed, where no search could find
// a password); screenshots are taken only by the tests, after every password field has been
// emptied, and a failed sign-in empties the field before Playwright's failure report (whose page
// snapshot lists input values) is written (e2e/support/app.js). Afterwards `make e2e` searches the
// plain-text files under e2e/.results for the passwords, as a backstop.
module.exports = defineConfig({
  testDir: './e2e',
  testMatch: '*.spec.js',
  outputDir: './e2e/.results/artifacts',
  globalSetup: require.resolve('./e2e/global-setup.js'),
  globalTeardown: require.resolve('./e2e/global-teardown.js'),
  // The specs share one database and one set of accounts.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  forbidOnly: true,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: 'list',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://127.0.0.1:3000',
    colorScheme: 'light',
    trace: 'off',
    video: 'off',
    screenshot: 'off',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
    },
  ],
});
