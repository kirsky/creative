import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useRef } from 'react';
import { apiFetch } from '../../../api/client';
import { queryKeys } from '../../../api/queryKeys';

/**
 * @typedef {object} AiUsageInput
 * @property {string} provider
 * @property {string} credits a decimal string, never a number
 */

/**
 * The answer of a second call while the first is still on its way.
 * @type {{readonly inFlight: true}}
 */
export const IN_FLIGHT = Object.freeze({ inFlight: true });

/**
 * A mutation of the task that sends one request at a time: a second call while the first is
 * pending (a double click that beats the disabled button) does nothing and answers IN_FLIGHT.
 * Whatever the answer, the task is asked for again, so the screen shows the real state; when the
 * call succeeded the boards, the dashboards and `alsoOnSuccess` are refreshed too. The mutation
 * stays pending until that refetch is done. A rejection is the ApiRequestError of the call.
 * @template TVariables, TResult
 * @param {number} taskId
 * @param {(variables: TVariables) => Promise<TResult>} request
 * @param {import('@tanstack/react-query').QueryKey[]} [alsoOnSuccess]
 */
function useTaskMutation(taskId, request, alsoOnSuccess = []) {
  const queryClient = useQueryClient();
  const running = useRef(false);
  const mutation = useMutation({
    mutationFn: request,
    onSettled: async (_data, error) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.task(taskId) }),
        ...(error === null
          ? [queryKeys.boards(), queryKeys.dashboards(), ...alsoOnSuccess].map((queryKey) =>
              queryClient.invalidateQueries({ queryKey }),
            )
          : []),
      ]);
    },
  });
  const { mutateAsync } = mutation;
  const guarded = useCallback(
    /** @param {TVariables} variables @returns {Promise<TResult | typeof IN_FLIGHT>} */
    async (variables) => {
      if (running.current) {
        return IN_FLIGHT;
      }
      running.current = true;
      try {
        return await mutateAsync(variables);
      } finally {
        running.current = false;
      }
    },
    [mutateAsync],
  );
  return { mutateAsync: guarded, isPending: mutation.isPending };
}

/**
 * Gives a task back to the pool (POST /api/tasks/{id}/release). No variables.
 * @param {number} taskId
 */
export function useRelease(taskId) {
  return useTaskMutation(
    taskId,
    /** @type {(variables: void) => Promise<unknown>} */ (
      () => apiFetch(`/api/tasks/${taskId}/release`, { method: 'POST' })
    ),
  );
}

/**
 * Hands in a round (POST /api/tasks/{id}/rounds); resolves `{number}`. A new provider may have
 * been created, so the provider list is refreshed too.
 * @param {number} taskId
 */
export function useSubmitRound(taskId) {
  return useTaskMutation(
    taskId,
    (/** @type {{note: string, fileIds: number[], aiUsage: AiUsageInput[]}} */ body) =>
      apiFetch(`/api/tasks/${taskId}/rounds`, { method: 'POST', body }),
    [queryKeys.aiProviders()],
  );
}

/**
 * Replaces the whole AI usage of an undecided round (PUT .../rounds/{n}/ai-usage).
 * @param {number} taskId
 */
export function useReplaceAiUsage(taskId) {
  return useTaskMutation(
    taskId,
    (/** @type {{round: number, lines: AiUsageInput[]}} */ { round, lines }) =>
      apiFetch(`/api/tasks/${taskId}/rounds/${round}/ai-usage`, { method: 'PUT', body: { lines } }),
    [queryKeys.aiProviders()],
  );
}

/**
 * Accepts the work (POST /api/tasks/{id}/approve).
 * @param {number} taskId
 */
export function useApprove(taskId) {
  return useTaskMutation(taskId, (/** @type {{note?: string}} */ body) =>
    apiFetch(`/api/tasks/${taskId}/approve`, { method: 'POST', body }),
  );
}

/**
 * Sends the work back (POST /api/tasks/{id}/request-revisions).
 * @param {number} taskId
 */
export function useRequestRevisions(taskId) {
  return useTaskMutation(taskId, (/** @type {{reason: string}} */ body) =>
    apiFetch(`/api/tasks/${taskId}/request-revisions`, { method: 'POST', body }),
  );
}

/**
 * Cancels the task (POST /api/tasks/{id}/cancel).
 * @param {number} taskId
 */
export function useCancel(taskId) {
  return useTaskMutation(taskId, (/** @type {{reason?: string}} */ body) =>
    apiFetch(`/api/tasks/${taskId}/cancel`, { method: 'POST', body }),
  );
}

/**
 * The managed list of AI providers, for the suggestions of the editor. A failure only means no
 * suggestions: the name is free text.
 * @returns {import('@tanstack/react-query').UseQueryResult<{providers: {id: number, name: string}[]}>}
 */
export function useAiProviders() {
  return useQuery({
    queryKey: queryKeys.aiProviders(),
    queryFn: ({ signal }) => apiFetch('/api/ai-providers', { signal }),
    staleTime: 60_000,
    retry: false,
  });
}
