import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { setCsrfToken } from '../../../api/client';
import { callsTo } from '../../../test/createTaskForm';
import { errorResponse, installFetch, mockResponse } from '../../../test/http';
import {
  IN_FLIGHT,
  useAiProviders,
  useApprove,
  useCancel,
  useReplaceAiUsage,
  useRelease,
  useRequestRevisions,
  useSubmitRound,
} from './api';

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

const keysOf = (/** @type {jest.SpyInstance} */ spy) =>
  spy.mock.calls.map(([filters]) => JSON.stringify(filters.queryKey));

describe('the task mutations', () => {
  it.each([
    ['release', useRelease, undefined, 'POST', '/api/tasks/7/release', undefined],
    [
      'submit a round',
      useSubmitRound,
      { note: 'n', fileIds: [1], aiUsage: [{ provider: 'Runway', credits: '1.50' }] },
      'POST',
      '/api/tasks/7/rounds',
      { note: 'n', fileIds: [1], aiUsage: [{ provider: 'Runway', credits: '1.50' }] },
    ],
    [
      'replace the AI usage',
      useReplaceAiUsage,
      { round: 3, lines: [{ provider: 'Runway', credits: '2' }] },
      'PUT',
      '/api/tasks/7/rounds/3/ai-usage',
      { lines: [{ provider: 'Runway', credits: '2' }] },
    ],
    ['approve', useApprove, { note: 'ok' }, 'POST', '/api/tasks/7/approve', { note: 'ok' }],
    [
      'request revisions',
      useRequestRevisions,
      { reason: 'r' },
      'POST',
      '/api/tasks/7/request-revisions',
      { reason: 'r' },
    ],
    ['cancel', useCancel, {}, 'POST', '/api/tasks/7/cancel', {}],
  ])(
    '%s calls the endpoint with the CSRF token',
    async (_name, hook, variables, method, path, body) => {
      const fetchMock = installFetch({ [`${method} ${path}`]: mockResponse(200, { number: 1 }) });
      const { result } = renderHook(() => /** @type {any} */ (hook)(7), {
        wrapper: wrapperFor(new QueryClient()),
      });
      await act(() => result.current.mutateAsync(variables));
      const [[, init]] = callsTo(fetchMock, method, path);
      expect(init.headers).toMatchObject({ 'X-CSRF-Token': 'c'.repeat(64) });
      expect(init.body === undefined ? undefined : JSON.parse(init.body)).toEqual(body);
    },
  );

  it('refreshes the task, the boards and the dashboards after a success', async () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    installFetch({ 'POST /api/tasks/7/approve': mockResponse(200, {}) });
    const { result } = renderHook(() => useApprove(7), { wrapper: wrapperFor(client) });
    await act(() => result.current.mutateAsync({}));
    expect(keysOf(invalidate)).toEqual(['["task",7]', '["board"]', '["dashboard"]']);
  });

  it('refreshes the AI providers after a round is submitted or its usage replaced', async () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    installFetch({
      'POST /api/tasks/7/rounds': mockResponse(201, { number: 1 }),
      'PUT /api/tasks/7/rounds/1/ai-usage': mockResponse(200, {}),
    });
    const submit = renderHook(() => useSubmitRound(7), { wrapper: wrapperFor(client) });
    await act(() => submit.result.current.mutateAsync({ note: '', fileIds: [1], aiUsage: [] }));
    expect(keysOf(invalidate)).toContain('["aiProviders"]');
    invalidate.mockClear();
    const replace = renderHook(() => useReplaceAiUsage(7), { wrapper: wrapperFor(client) });
    await act(() => replace.result.current.mutateAsync({ round: 1, lines: [] }));
    expect(keysOf(invalidate)).toContain('["aiProviders"]');
  });

  it('refreshes only the task after a failure, and rejects with the API error', async () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    installFetch({ 'POST /api/tasks/7/approve': errorResponse(409, 'invalid_transition') });
    const { result } = renderHook(() => useApprove(7), { wrapper: wrapperFor(client) });
    await act(async () => {
      await expect(result.current.mutateAsync({})).rejects.toMatchObject({
        status: 409,
        code: 'invalid_transition',
      });
    });
    expect(keysOf(invalidate)).toEqual(['["task",7]']);
  });

  it('sends one request when called again while the first is pending', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    const fetchMock = installFetch({
      'POST /api/tasks/7/approve': () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    });
    const { result } = renderHook(() => useApprove(7), { wrapper: wrapperFor(new QueryClient()) });
    /** @type {Promise<unknown>} */
    let first = Promise.resolve();
    /** @type {unknown} */
    let second;
    await act(async () => {
      first = result.current.mutateAsync({});
      second = await result.current.mutateAsync({});
    });
    expect(second).toBe(IN_FLIGHT);
    expect(callsTo(fetchMock, 'POST', '/api/tasks/7/approve')).toHaveLength(1);
    await act(async () => {
      release(mockResponse(200, {}));
      await first;
    });
    // Free again once the first has settled.
    await waitFor(() => expect(result.current.isPending).toBe(false));
  });
});

describe('useAiProviders', () => {
  it('loads the providers', async () => {
    installFetch({
      'GET /api/ai-providers': mockResponse(200, { providers: [{ id: 1, name: 'Runway' }] }),
    });
    const { result } = renderHook(() => useAiProviders(), {
      wrapper: wrapperFor(new QueryClient()),
    });
    await waitFor(() =>
      expect(result.current.data?.providers).toEqual([{ id: 1, name: 'Runway' }]),
    );
  });
});
