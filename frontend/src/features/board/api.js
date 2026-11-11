import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';
import { boardPath } from './filters';

/**
 * A task as the board shows it (TaskCard of the API).
 * @typedef {object} BoardTask
 * @property {number} id
 * @property {string} offer
 * @property {string[]} geo country and region codes
 * @property {string} creativeType
 * @property {number} quantity
 * @property {string} priority
 * @property {string} deadlineAt UTC, to the second
 * @property {'new' | 'in_progress' | 'under_review' | 'revisions_required' | 'completed' | 'cancelled'} status
 * @property {{id: number, displayName: string}} createdBy
 * @property {{id: number, displayName: string} | null} assignee
 * @property {number} roundCount
 * @property {string} creditsTotal decimal string, "0.00"
 * @property {boolean} isOverdue
 * @property {boolean} isReturning the task is `new` again because its latest round was sent back
 * @property {string[]} allowedActions what the signed-in user may do: pick_up, release, submit,
 *   edit_brief, report_ai_usage, approve, request_revisions, cancel
 */

/**
 * @typedef {object} BoardData
 * @property {BoardTask[]} tasks earliest deadline first
 */

/**
 * The board: the tasks the user may see, cancelled ones included (the page hides them). The
 * query key holds the filters only. While new filters load, the previous board stays on screen.
 * @param {import('./filters').BoardFilters} filters
 * @param {{enabled?: boolean}} [options]
 * @returns {import('@tanstack/react-query').UseQueryResult<BoardData>}
 */
export function useBoard(filters, { enabled = true } = {}) {
  return useQuery({
    queryKey: queryKeys.board(filters),
    queryFn: ({ signal }) => apiFetch(boardPath(filters), { signal }),
    enabled,
    placeholderData: keepPreviousData,
  });
}

/**
 * Picks a `new` task up (POST /api/tasks/{id}/pickup). Variable: the task id. Whatever the answer,
 * every board and the task are refreshed, and the mutation stays pending until the refetch is done,
 * so a card keeps its spinner until it has moved. A rejection carries the API error code
 * (already_taken, invalid_transition, forbidden, not_found).
 * @returns {import('@tanstack/react-query').UseMutationResult<unknown, Error, number>}
 */
export function usePickUp() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (/** @type {number} */ id) =>
      apiFetch(`/api/tasks/${id}/pickup`, { method: 'POST' }),
    onSettled: (_data, _error, id) =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.boards() }),
        queryClient.invalidateQueries({ queryKey: queryKeys.task(id) }),
      ]),
  });
}
