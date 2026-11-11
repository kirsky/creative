import { browserLocale, csrfToken, LANDING, signIn, signInWithWrongPassword } from './support/app';
import { ACCOUNTS } from './support/credentials';
import { expect, test } from './support/fixtures';
import { tr } from './support/i18n';

/** Who may see what, through the browser and through the API with the browser's session. */

test('a visitor without a session is sent to the login page, which lists no accounts', async ({
  browser,
}) => {
  const context = await browser.newContext({ locale: browserLocale('en') });
  const page = await context.newPage();
  await page.goto('/board');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('heading', { level: 2 })).toHaveText(tr('en', 'auth:login.title'));
  const text = await page.evaluate(() => document.body.innerText);
  for (const [username, { displayName }] of Object.entries(ACCOUNTS)) {
    expect(text).not.toContain(username);
    expect(text).not.toContain(displayName);
  }
  await expect(page.locator('select, ul, ol, table')).toHaveCount(0);
  await context.close();
});

test('a wrong password shows the generic error in the chosen language', async ({ browser }) => {
  // One attempt only: every failure counts towards the per-IP limit that the other specs'
  // sign-ins share (the limit itself is covered by PHPUnit and Jest).
  const context = await browser.newContext({ locale: browserLocale('en') });
  const page = await context.newPage();
  await page.goto('/login');
  await page.locator('.login-page__language').getByText('RU', { exact: true }).click();
  await expect(page.locator('html')).toHaveAttribute('lang', 'ru');
  await signInWithWrongPassword(page, 'dmitry');
  await expect(page.getByRole('alert')).toHaveText('Неверное имя пользователя или пароль.');
  await expect(page.getByRole('alert')).toHaveText(tr('ru', 'errors:invalid_credentials'));
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.locator('input[autocomplete="current-password"]')).toHaveValue('');
  await context.close();
});

test('the API answers 401 to every call without a session', async ({ request }) => {
  const calls = [
    request.get('/api/auth/me'),
    request.get('/api/tasks'),
    request.get('/api/tasks/1'),
    request.get('/api/analytics/dashboard'),
    request.get('/api/options'),
    request.get('/api/files/1'),
    request.post('/api/tasks', { data: {} }),
    request.patch('/api/me/locale', { data: { locale: 'ru' } }),
  ];
  for (const response of await Promise.all(calls)) {
    expect(response.status(), response.url()).toBe(401);
    expect((await response.json()).error.code).toBe('unauthenticated');
  }
});

test('a designer cannot open the dashboard, in the app or through the API', async ({ actors }) => {
  const anna = await actors.open('anna', 'en');
  await anna.goto('/dashboard');
  await expect(anna).toHaveURL(/\/my-work$/);
  await expect(anna.getByRole('heading', { level: 1 })).toHaveText(tr('en', 'mywork:title'));
  const response = await anna.request.get('/api/analytics/dashboard');
  expect(response.status()).toBe(403);
  expect((await response.json()).error.code).toBe('forbidden');
});

test("a buyer cannot open another buyer's task", async ({ actors }) => {
  const elena = await actors.open('elena', 'en');
  const created = await elena.request.post('/api/tasks', {
    headers: { 'X-CSRF-Token': await csrfToken(elena) },
    data: {
      offer: `E2E Elena only ${Date.now()}`,
      geo: ['DE'],
      creativeType: 'static_image',
      quantity: 1,
      description: 'Visible to Elena, designers and the manager.',
      priority: 'normal',
      deadline: new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString(),
      references: [],
    },
  });
  expect(created.status()).toBe(201);
  const { id } = await created.json();
  expect(typeof id).toBe('number');
  // Elena herself sees it.
  expect((await elena.request.get(`/api/tasks/${id}`)).status()).toBe(200);

  const dmitry = await actors.open('dmitry', 'ru');
  await dmitry.goto(`/tasks/${id}`);
  await expect(dmitry.getByText(tr('ru', 'task:page.notFound.title'))).toBeVisible();
  await expect(dmitry.getByText('Задача не найдена')).toBeVisible();
  const response = await dmitry.request.get(`/api/tasks/${id}`);
  expect(response.status()).toBe(404);
  expect((await response.json()).error.code).toBe('not_found');
});

test('after signing out, signing in again starts at the landing page', async ({ actors }) => {
  const dmitry = await actors.open('dmitry', 'en');
  await dmitry.locator('.task-card__link').first().click();
  await expect(dmitry).toHaveURL(/\/tasks\/\d+$/);

  await dmitry.getByRole('button', { name: tr('en', 'common:signOut') }).click();
  await expect(dmitry).toHaveURL(/\/login$/);
  // The session is gone on the server too.
  expect((await dmitry.request.get('/api/auth/me')).status()).toBe(401);

  // On the same page, without a reload: the app itself must not bring the task back.
  await signIn(dmitry, 'dmitry', { navigate: false });
  await expect(dmitry).toHaveURL(new RegExp(`${LANDING.buyer}$`));
});
