import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { ApiRequestError } from '../../api/ApiRequestError';
import { setCsrfToken } from '../../api/client';
import { errorResponse, installFetch, mockResponse } from '../../test/http';
import { makeOptions } from '../../test/renderApp';
import { uploadFile, useOptions } from './api';

beforeEach(() => {
  setCsrfToken('c'.repeat(64));
});

describe('uploadFile', () => {
  it('posts ONE file in the multipart field "file" and resolves the stored file', async () => {
    const stored = { id: 7, name: 'a.png', mime: 'image/png', size: 3 };
    const fetchMock = installFetch({ 'POST /api/files': mockResponse(201, stored) });
    const file = new File(['abc'], 'a.png', { type: 'image/png' });

    await expect(uploadFile(file)).resolves.toEqual(stored);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const init = /** @type {RequestInit} */ (fetchMock.mock.calls[0][1]);
    expect(init.method).toBe('POST');
    expect(init.body).toBeInstanceOf(FormData);
    const body = /** @type {FormData} */ (init.body);
    expect([...body.keys()]).toEqual(['file']);
    expect(body.get('file')).toBeInstanceOf(File);
    expect(/** @type {File} */ (body.get('file')).name).toBe('a.png');
    // The browser must write the multipart boundary itself.
    expect(init.headers).not.toHaveProperty('Content-Type');
    expect(init.headers).toMatchObject({ 'X-CSRF-Token': 'c'.repeat(64) });
  });

  it('rejects with the API error code', async () => {
    installFetch({
      'POST /api/files': errorResponse(413, 'file_too_large', { params: { maxBytes: 99000000 } }),
    });
    await expect(uploadFile(new File(['x'], 'a.png'))).rejects.toMatchObject({
      code: 'file_too_large',
      params: { maxBytes: 99000000 },
    });
  });

  it('turns the HTML 413 of nginx into payload_too_large', async () => {
    installFetch({ 'POST /api/files': mockResponse(413, '<html>413</html>') });
    const error = await uploadFile(new File(['x'], 'a.png')).catch((e) => e);
    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error.code).toBe('payload_too_large');
  });

  it('passes the abort signal on', async () => {
    const fetchMock = installFetch({ 'POST /api/files': mockResponse(201, { id: 1 }) });
    const controller = new AbortController();
    await uploadFile(new File(['x'], 'a.png'), { signal: controller.signal });
    const init = /** @type {RequestInit} */ (fetchMock.mock.calls[0][1]);
    expect(init.signal).toBe(controller.signal);
  });
});

describe('useOptions', () => {
  it('loads /api/options once and never refetches (staleTime Infinity)', async () => {
    const fetchMock = installFetch({ 'GET /api/options': mockResponse(200, makeOptions()) });
    const client = new QueryClient();
    /** @param {{children: import('react').ReactNode}} props */
    const wrapper = ({ children }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );

    const first = renderHook(() => useOptions(), { wrapper });
    await waitFor(() => expect(first.result.current.data).toEqual(makeOptions()));
    first.unmount();
    const second = renderHook(() => useOptions(), { wrapper });
    expect(second.result.current.data).toEqual(makeOptions());
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(client.getQueryCache().find({ queryKey: ['options'] })).toBeDefined();
  });
});
