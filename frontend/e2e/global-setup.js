import { request } from '@playwright/test';
import { parseCredentials } from './support/credentials';

/**
 * Fails fast, before any browser starts, when the suite cannot work: no or malformed credentials
 * (the workers parse the same variable into memory themselves), or no app at the base URL.
 * @param {import('@playwright/test').FullConfig} config
 */
export default async function globalSetup(config) {
  parseCredentials(process.env.E2E_CREDENTIALS);

  const baseURL = config.projects[0]?.use.baseURL;
  const api = await request.newContext({ baseURL });
  try {
    const health = await api.get('/api/health');
    if (!health.ok()) {
      throw new Error(`GET ${baseURL}/api/health answered ${health.status()}; is the stack up?`);
    }
  } finally {
    await api.dispose();
  }
}
