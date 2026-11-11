import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { errorResponse, installFetch, mockResponse } from '../../test/http';
import { makeEvent, makeTaskDetail } from '../../test/taskDetail';
import { useTask, useTaskHistory } from './api';

/** @param {QueryClient} client */
function wrapperFor(client) {
  /** @param {{children: import('react').ReactNode}} props */
  return function Wrapper({ children }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

describe('useTask', () => {
  it('loads GET /api/tasks/{id} under the shared task key', async () => {
    const task = makeTaskDetail({ id: 42 });
    const fetchMock = installFetch({ 'GET /api/tasks/42': mockResponse(200, task) });
    const client = new QueryClient();
    const { result } = renderHook(() => useTask(42), { wrapper: wrapperFor(client) });
    await waitFor(() => expect(result.current.data).toEqual(task));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(client.getQueryCache().find({ queryKey: ['task', 42] })).toBeDefined();
  });

  it.each([[null], [undefined]])('does not ask without a usable id (%s)', async (id) => {
    const fetchMock = installFetch({});
    const { result } = renderHook(() => useTask(id), { wrapper: wrapperFor(new QueryClient()) });
    await act(async () => {});
    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.fetchStatus).toBe('idle');
  });

  it('fails with the API error of a task that cannot be viewed', async () => {
    installFetch({ 'GET /api/tasks/9': errorResponse(404, 'not_found') });
    const { result } = renderHook(() => useTask(9), {
      wrapper: wrapperFor(new QueryClient({ defaultOptions: { queries: { retry: false } } })),
    });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toMatchObject({ status: 404, code: 'not_found' });
  });
});

describe('useTaskHistory', () => {
  it('loads the events under the history key once enabled', async () => {
    const events = [makeEvent('task_created')];
    const fetchMock = installFetch({
      'GET /api/tasks/42/history': mockResponse(200, { events }),
    });
    const client = new QueryClient();
    const { result, rerender } = renderHook(({ enabled }) => useTaskHistory(42, { enabled }), {
      wrapper: wrapperFor(client),
      initialProps: { enabled: false },
    });
    await act(async () => {});
    expect(fetchMock).not.toHaveBeenCalled();

    rerender({ enabled: true });
    await waitFor(() => expect(result.current.data).toEqual({ events }));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(client.getQueryCache().find({ queryKey: ['task', 42, 'history'] })).toBeDefined();
  });

  it('does not ask without a usable id even when enabled', async () => {
    const fetchMock = installFetch({});
    renderHook(() => useTaskHistory(null, { enabled: true }), {
      wrapper: wrapperFor(new QueryClient()),
    });
    await act(async () => {});
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
