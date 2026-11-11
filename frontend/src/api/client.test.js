import { apiFetch, setCsrfToken, setUnauthenticatedHandler } from './client';
import { ApiRequestError } from './ApiRequestError';
import { errorResponse, mockResponse } from '../test/http';

/** @type {jest.Mock} */
let fetchMock;

/** @returns {RequestInit & {headers: Record<string, string>}} */
function lastInit() {
  return fetchMock.mock.calls[fetchMock.mock.calls.length - 1][1];
}

beforeEach(() => {
  fetchMock = jest.fn();
  globalThis.fetch = /** @type {any} */ (fetchMock);
  setCsrfToken('csrf-token-1');
  setUnauthenticatedHandler(null);
});

describe('apiFetch', () => {
  it.each(['POST', 'PATCH', 'PUT', 'DELETE'])('sends X-CSRF-Token on %s', async (method) => {
    fetchMock.mockResolvedValue(mockResponse(200, { ok: true }));
    await apiFetch('/api/tasks', { method, body: { a: 1 } });
    expect(lastInit().headers['X-CSRF-Token']).toBe('csrf-token-1');
  });

  it('sends X-CSRF-Token on POST but not GET', async () => {
    fetchMock.mockResolvedValue(mockResponse(200, {}));
    await apiFetch('/api/tasks');
    expect(lastInit().method).toBe('GET');
    expect(lastInit().headers).not.toHaveProperty('X-CSRF-Token');

    await apiFetch('/api/tasks', { method: 'POST', body: {} });
    expect(lastInit().headers['X-CSRF-Token']).toBe('csrf-token-1');
  });

  it('sends cookies for the same origin and asks for JSON', async () => {
    fetchMock.mockResolvedValue(mockResponse(200, {}));
    await apiFetch('/api/tasks');
    expect(fetchMock.mock.calls[0][0]).toBe('/api/tasks');
    expect(lastInit().credentials).toBe('same-origin');
    expect(lastInit().headers.Accept).toBe('application/json');
  });

  it('sends a JSON body with the JSON content type', async () => {
    fetchMock.mockResolvedValue(mockResponse(200, {}));
    await apiFetch('/api/auth/login', { method: 'POST', body: { username: 'a', password: 'b' } });
    expect(lastInit().headers['Content-Type']).toBe('application/json');
    expect(lastInit().body).toBe('{"username":"a","password":"b"}');
  });

  it('does not JSON-encode FormData', async () => {
    fetchMock.mockResolvedValue(mockResponse(201, {}));
    const formData = new FormData();
    formData.append('files[]', new Blob(['x']), 'a.png');
    await apiFetch('/api/tasks/1/rounds', { method: 'POST', formData });
    expect(lastInit().body).toBe(formData);
    // The browser must add the multipart boundary itself.
    expect(lastInit().headers).not.toHaveProperty('Content-Type');
    expect(lastInit().headers['X-CSRF-Token']).toBe('csrf-token-1');
  });

  it('passes the abort signal on', async () => {
    fetchMock.mockResolvedValue(mockResponse(200, {}));
    const controller = new AbortController();
    await apiFetch('/api/tasks', { signal: controller.signal });
    expect(lastInit().signal).toBe(controller.signal);
  });

  it('parses a JSON success body', async () => {
    fetchMock.mockResolvedValue(mockResponse(200, { items: [1, 2] }));
    await expect(apiFetch('/api/tasks')).resolves.toEqual({ items: [1, 2] });
  });

  it('resolves undefined for 204', async () => {
    fetchMock.mockResolvedValue(mockResponse(204));
    await expect(apiFetch('/api/auth/logout', { method: 'POST' })).resolves.toBeUndefined();
  });

  it('refuses paths outside /api so the CSRF token cannot leave the app', async () => {
    await expect(apiFetch('https://evil.example/api/x', { method: 'POST' })).rejects.toThrow(
      TypeError,
    );
    await expect(apiFetch('/other')).rejects.toThrow(TypeError);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe('apiFetch errors', () => {
  it('throws ApiRequestError with status, code, fields from {"error":…}', async () => {
    const fields = [{ field: 'title', code: 'too_long', params: { max: 200 } }];
    fetchMock.mockResolvedValue(
      mockResponse(422, { error: { code: 'validation_failed', fields, params: { x: 1 } } }),
    );
    const error = await apiFetch('/api/tasks', { method: 'POST', body: {} }).catch((e) => e);
    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error).toBeInstanceOf(Error);
    expect(error.status).toBe(422);
    expect(error.code).toBe('validation_failed');
    expect(error.fields).toEqual(fields);
    expect(error.params).toEqual({ x: 1 });
  });

  it('defaults fields to [] and params to {}', async () => {
    fetchMock.mockResolvedValue(mockResponse(404, { error: { code: 'not_found' } }));
    const error = await apiFetch('/api/tasks/9').catch((e) => e);
    expect(error.fields).toEqual([]);
    expect(error.params).toEqual({});
  });

  it('keeps the rate limit params', async () => {
    fetchMock.mockResolvedValue(
      errorResponse(429, 'rate_limited', { params: { retryAfterSeconds: 60 } }),
    );
    const error = await apiFetch('/api/auth/login', { method: 'POST', body: {} }).catch((e) => e);
    expect(error.code).toBe('rate_limited');
    expect(error.params).toEqual({ retryAfterSeconds: 60 });
  });

  it('turns a non-JSON 413 (nginx) into payload_too_large', async () => {
    fetchMock.mockResolvedValue(
      mockResponse(413, '<html><body>413 Request Entity Too Large</body></html>'),
    );
    const error = await apiFetch('/api/tasks', { method: 'POST', body: {} }).catch((e) => e);
    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error.status).toBe(413);
    expect(error.code).toBe('payload_too_large');
    expect(error.fields).toEqual([]);
  });

  it('turns a non-JSON 502 into code unknown with the status kept', async () => {
    fetchMock.mockResolvedValue(mockResponse(502, '<html>Bad Gateway</html>'));
    const error = await apiFetch('/api/tasks').catch((e) => e);
    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error.status).toBe(502);
    expect(error.code).toBe('unknown');
  });

  it('treats JSON without an error envelope as unknown', async () => {
    fetchMock.mockResolvedValue(mockResponse(500, { message: 'boom' }));
    const error = await apiFetch('/api/tasks').catch((e) => e);
    expect(error.status).toBe(500);
    expect(error.code).toBe('unknown');
  });

  it('treats a successful response that is not JSON as an error', async () => {
    fetchMock.mockResolvedValue(mockResponse(200, '<!doctype html><html></html>'));
    const error = await apiFetch('/api/tasks').catch((e) => e);
    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error.status).toBe(200);
    expect(error.code).toBe('unknown');
  });

  it('wraps a network failure as ApiRequestError with status 0', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
    const error = await apiFetch('/api/tasks').catch((e) => e);
    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error.status).toBe(0);
    expect(error.code).toBe('network_error');
  });

  it('lets an abort through untouched', async () => {
    const abort = new DOMException('Aborted', 'AbortError');
    fetchMock.mockRejectedValue(abort);
    await expect(apiFetch('/api/tasks')).rejects.toBe(abort);
  });
});

describe('unauthenticated handler', () => {
  it('401 from a normal path triggers the handler once', async () => {
    const handler = jest.fn();
    setUnauthenticatedHandler(handler);
    fetchMock.mockResolvedValue(errorResponse(401, 'unauthenticated'));
    const error = await apiFetch('/api/tasks').catch((e) => e);
    expect(error.code).toBe('unauthenticated');
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('401 from POST /api/auth/login does not trigger the handler', async () => {
    const handler = jest.fn();
    setUnauthenticatedHandler(handler);
    fetchMock.mockResolvedValue(errorResponse(401, 'invalid_credentials'));
    const error = await apiFetch('/api/auth/login', { method: 'POST', body: {} }).catch((e) => e);
    expect(error.code).toBe('invalid_credentials');
    expect(handler).not.toHaveBeenCalled();
  });

  it('other statuses do not trigger the handler', async () => {
    const handler = jest.fn();
    setUnauthenticatedHandler(handler);
    fetchMock.mockResolvedValue(errorResponse(403, 'forbidden'));
    await apiFetch('/api/tasks').catch(() => {});
    expect(handler).not.toHaveBeenCalled();
  });
});
