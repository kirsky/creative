import { ApiRequestError } from './ApiRequestError';

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

/** @type {string | null} */
let csrfToken = null;
/** @type {(() => void) | null} */
let unauthenticatedHandler = null;

/**
 * The CSRF token of the current session (from GET /api/auth/me or the login response).
 * The auth provider keeps it up to date.
 * @param {string | null} token
 */
export function setCsrfToken(token) {
  csrfToken = token;
}

export function getCsrfToken() {
  return csrfToken;
}

/**
 * Registers what happens when the API answers 401 on any call except a failed login.
 * @param {(() => void) | null} handler
 */
export function setUnauthenticatedHandler(handler) {
  unauthenticatedHandler = handler;
}

/**
 * @typedef {object} ApiFetchOptions
 * @property {string} [method] HTTP method, GET by default
 * @property {unknown} [body] sent as JSON
 * @property {FormData} [formData] sent untouched, so the browser writes the multipart boundary
 * @property {AbortSignal} [signal]
 */

/**
 * @param {Response | {status: number, text(): Promise<string>}} response
 * @returns {Promise<unknown>} the parsed JSON, or undefined when the body is empty
 */
async function readBody(response) {
  const text = await response.text();
  if (text === '') {
    return undefined;
  }
  try {
    return JSON.parse(text);
  } catch {
    // An HTML page from nginx (413, 502) or from the SPA fallback.
    return null;
  }
}

/**
 * @param {number} status
 * @param {unknown} body
 */
function toError(status, body) {
  const envelope =
    typeof body === 'object' && body !== null && 'error' in body ? body.error : undefined;
  if (typeof envelope === 'object' && envelope !== null && 'code' in envelope) {
    const { code, fields, params } = /** @type {Record<string, any>} */ (envelope);
    if (typeof code === 'string') {
      return new ApiRequestError(
        status,
        code,
        Array.isArray(fields) ? fields : [],
        typeof params === 'object' && params !== null ? params : {},
      );
    }
  }
  return new ApiRequestError(status, status === 413 ? 'payload_too_large' : 'unknown');
}

/** @param {AbortSignal | undefined} signal */
function throwIfAborted(signal) {
  if (signal?.aborted) {
    throw new DOMException('The request was aborted', 'AbortError');
  }
}

/**
 * Calls the JSON API of this origin. Resolves the parsed JSON body (undefined for 204) and
 * throws ApiRequestError for every failure.
 * @param {string} path starts with /api
 * @param {ApiFetchOptions} [options]
 * @returns {Promise<any>}
 */
export async function apiFetch(path, options = {}) {
  // Same-origin paths only: an absolute URL would send the CSRF token to another site.
  if (!path.startsWith('/api/')) {
    throw new TypeError(`API paths must start with /api/, got "${path}"`);
  }
  const method = (options.method ?? 'GET').toUpperCase();

  /** @type {Record<string, string>} */
  const headers = { Accept: 'application/json' };
  if (!SAFE_METHODS.has(method) && csrfToken !== null) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  /** @type {BodyInit | undefined} */
  let body;
  if (options.formData !== undefined) {
    body = options.formData;
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  let response;
  try {
    response = await fetch(path, {
      method,
      headers,
      body,
      credentials: 'same-origin',
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw error;
    }
    throw new ApiRequestError(0, 'network_error');
  }

  // The caller gave up while the request was in flight (a stale answer must have no effect, not
  // even clearing the session on a 401), whether or not the browser could stop the request.
  throwIfAborted(options.signal);

  if (response.status === 204) {
    return undefined;
  }

  const parsed = await readBody(response);
  throwIfAborted(options.signal);

  if (!response.ok) {
    if (response.status === 401 && !(method === 'POST' && path === '/api/auth/login')) {
      unauthenticatedHandler?.();
    }
    throw toError(response.status, parsed);
  }
  if (parsed === null) {
    // A 2xx that is not JSON means the request never reached the API.
    throw new ApiRequestError(response.status, 'unknown');
  }
  return parsed;
}
