import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { setCsrfToken } from '../../api/client';
import { boardResponse, makeTask } from '../../test/board';
import { errorResponse, installFetch, mockResponse } from '../../test/http';
import { useBoard, usePickUp } from './api';

beforeEach(() => {
  setCsrfToken('c'.repeat(64));
});

/** @param {QueryClient} client */
function wrapperFor(client) {
  /** @param {{children: import('react').ReactNode}} props */
  return function Wrapper({ children }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

describe('useBoard', () => {
  it('loads the tasks with includeCancelled=1 and keys the query by the filters only', async () => {
    const tasks = [makeTask({ id: 4 })];
    const fetchMock = installFetch({
      'GET /api/tasks?priority=high&includeCancelled=1': boardResponse(tasks),
    });
    const client = new QueryClient();
    const { result } = renderHook(() => useBoard({ priority: 'high' }), {
      wrapper: wrapperFor(client),
    });
    await waitFor(() => expect(result.current.data).toEqual({ tasks }));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(
      client.getQueryCache().find({ queryKey: ['board', { priority: 'high' }] }),
    ).toBeDefined();
  });

  it('does not ask while disabled', async () => {
    const fetchMock = installFetch({});
    const { result } = renderHook(() => useBoard({}, { enabled: false }), {
      wrapper: wrapperFor(new QueryClient()),
    });
    await act(async () => {});
    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('usePickUp', () => {
  it('posts to the pickup endpoint with the CSRF token', async () => {
    const fetchMock = installFetch({
      'POST /api/tasks/7/pickup': mockResponse(200, { id: 7, status: 'in_progress' }),
    });
    const { result } = renderHook(() => usePickUp(), {
      wrapper: wrapperFor(new QueryClient()),
    });
    await act(() => result.current.mutateAsync(7));
    const init = /** @type {RequestInit} */ (fetchMock.mock.calls[0][1]);
    expect(init.method).toBe('POST');
    expect(init.headers).toMatchObject({ 'X-CSRF-Token': 'c'.repeat(64) });
    expect(init.body).toBeUndefined();
  });

  it('refreshes every board query and the task, after success and after failure', async () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    installFetch({
      'POST /api/tasks/7/pickup': mockResponse(200, { id: 7 }),
      'POST /api/tasks/8/pickup': errorResponse(409, 'already_taken'),
    });
    const { result } = renderHook(() => usePickUp(), { wrapper: wrapperFor(client) });

    await act(() => result.current.mutateAsync(7));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['board'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['task', 7] });

    invalidate.mockClear();
    await act(() => result.current.mutateAsync(8).catch(() => {}));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['board'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['task', 8] });
  });

  it('rejects with the API error', async () => {
    installFetch({ 'POST /api/tasks/8/pickup': errorResponse(409, 'already_taken') });
    const { result } = renderHook(() => usePickUp(), { wrapper: wrapperFor(new QueryClient()) });
    await expect(act(() => result.current.mutateAsync(8))).rejects.toMatchObject({
      status: 409,
      code: 'already_taken',
    });
  });
});
