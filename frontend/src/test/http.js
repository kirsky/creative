/**
 * jsdom has no Response, so tests hand the API client this minimal stand-in. It offers only
 * what the client may rely on: status, ok and text().
 * @param {number} status
 * @param {unknown} [body] an object is sent as JSON, a string as it is
 */
export function mockResponse(status, body) {
  const text = body === undefined ? '' : typeof body === 'string' ? body : JSON.stringify(body);
  return { status, ok: status >= 200 && status < 300, text: async () => text };
}

/** @param {string} code @param {number} status */
export function errorResponse(status, code, extra = {}) {
  return mockResponse(status, { error: { code, fields: [], ...extra } });
}

/**
 * @typedef {import('../features/auth/useAuth').Me} Me
 * @param {Partial<Me>} [overrides]
 * @returns {Me}
 */
export function makeMe(overrides = {}) {
  return {
    id: 1,
    username: 'alice',
    displayName: 'Alice Kovacs',
    role: 'buyer',
    locale: 'en',
    csrfToken: 'a'.repeat(64),
    ...overrides,
  };
}

/**
 * Replaces globalThis.fetch with a router over "METHOD /api/path" keys. A handler may be a response
 * or a function returning one. Unmatched calls fail the test loudly.
 * @param {Record<string, ReturnType<typeof mockResponse> | (() => ReturnType<typeof mockResponse>)>} table
 */
export function installFetch(table) {
  const fetchMock = jest.fn(async (/** @type {string} */ path, /** @type {RequestInit} */ init) => {
    const key = `${init?.method ?? 'GET'} ${path}`;
    const handler = table[key];
    if (handler === undefined) {
      throw new Error(`Unexpected request: ${key}`);
    }
    return typeof handler === 'function' ? handler() : handler;
  });
  globalThis.fetch = /** @type {any} */ (fetchMock);
  return fetchMock;
}
