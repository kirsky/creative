import { request } from '@playwright/test';
import { ACCOUNTS, parseCredentials } from './support/credentials';

/**
 * Sets every demo account's interface language back to English, the seed's default, so the
 * Russian runs do not leave a reviewer with a Russian interface. It goes through the real API with
 * each account's own session and CSRF token, then signs out. Passwords stay in memory; errors
 * name the account and the HTTP status only.
 * @param {import('@playwright/test').FullConfig} config
 */
export default async function globalTeardown(config) {
  const credentials = parseCredentials(process.env.E2E_CREDENTIALS);
  const baseURL = config.projects[0]?.use.baseURL;
  /** @type {string[]} */
  const failures = [];
  for (const username of Object.keys(ACCOUNTS)) {
    const api = await request.newContext({ baseURL });
    try {
      const login = await api.post('/api/auth/login', {
        data: { username, password: credentials.get(username) },
      });
      if (!login.ok()) {
        failures.push(`${username}: sign-in answered ${login.status()}`);
        continue;
      }
      const { csrfToken } = (await login.json()).user;
      const headers = { 'X-CSRF-Token': csrfToken };
      const locale = await api.patch('/api/me/locale', { headers, data: { locale: 'en' } });
      if (!locale.ok() || (await locale.json()).locale !== 'en') {
        failures.push(`${username}: PATCH /api/me/locale answered ${locale.status()}`);
      }
      await api.post('/api/auth/logout', { headers });
    } finally {
      await api.dispose();
    }
  }
  if (failures.length > 0) {
    throw new Error(`Could not set the language back to English: ${failures.join('; ')}`);
  }
}
