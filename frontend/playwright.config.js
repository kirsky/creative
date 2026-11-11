const { defineConfig, devices } = require('@playwright/test');

// End-to-end tests: run only by `make e2e`, in the `playwright` container, against the running
// stack (see the Makefile for what that does to the data and the passwords).
//
// Passwords: they come from E2E_CREDENTIALS and are never stored. Traces and videos would keep
// what was typed, so they are off; screenshots are taken only by the tests themselves, after any
// password field has been emptied (e2e/support/fixtures.js). `make e2e` searches .results for the
// passwords after every run.
module.exports = defineConfig({
  testDir: './e2e',
  testMatch: '*.spec.js',
  outputDir: './e2e/.results/artifacts',
  globalSetup: require.resolve('./e2e/global-setup.js'),
  // The specs share one database and one set of accounts.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  forbidOnly: true,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { outputFolder: './e2e/.results/html', open: 'never' }]],
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
