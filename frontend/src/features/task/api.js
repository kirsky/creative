import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';

/**
 * @typedef {object} Person
 * @property {number} id
 * @property {string} displayName
 */

/**
 * @typedef {{type: 'url', url: string} | {type: 'file', fileId: number, name: string, mime: string, size: number}} Reference
 */

/**
 * A file of a round (download it with GET /api/files/{id}).
 * @typedef {object} RoundFile
 * @property {number} id
 * @property {string} name
 * @property {number} size bytes
 * @property {string} mime
 */

/**
 * @typedef {object} AiUsageLine
 * @property {string} provider
 * @property {string} credits decimal string with two decimals
 */

/**
 * One submission of a task and what the buyer decided about it.
 * @typedef {object} Round
 * @property {number} number
 * @property {string} submittedAt UTC
 * @property {Person} submittedBy
 * @property {string | null} note the designer's note
 * @property {RoundFile[]} files
 * @property {'revisions_requested' | 'approved' | null} decision null while the round waits for review
 * @property {string | null} decisionReason
 * @property {string | null} decidedAt UTC
 * @property {AiUsageLine[]} aiUsage
 */

/**
 * @typedef {object} Assignment
 * @property {Person} designer
 * @property {string} assignedAt UTC
 * @property {string | null} endedAt UTC; null while the designer holds the task
 * @property {'released' | 'completed' | 'cancelled' | null} endedHow
 */

/**
 * Elapsed wall-clock time in whole seconds, not time worked.
 * @typedef {object} TimeMetrics
 * @property {number} queueWaitSeconds
 * @property {number | null} cycleSeconds null if the task was never picked up
 * @property {number} reviewSeconds
 * @property {number} revisionTurnaroundSeconds
 */

/**
 * A task as GET /api/tasks/{id} returns it: the board card plus the brief, assignments, rounds and
 * metrics.
 * @typedef {import('../board/api').BoardTask & {
 *   description: string,
 *   references: Reference[],
 *   assignments: Assignment[],
 *   rounds: Round[],
 *   metrics: TimeMetrics,
 * }} TaskDetail
 */

/**
 * One entry of the activity history. The payload is structured data whose shape depends on the
 * type; it is only ever displayed as text.
 * @typedef {object} TaskEvent
 * @property {number} id
 * @property {string} type
 * @property {Person} actor
 * @property {string} at UTC
 * @property {Record<string, unknown>} payload
 */

/** @param {unknown} id @returns {id is number} */
function isTaskId(id) {
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0;
}

/**
 * The id of the task page's URL: a positive whole number written without leading zeros.
 * @param {string | undefined} param
 * @returns {number | null} null when the URL names no possible task
 */
export function parseTaskId(param) {
  if (param === undefined || !/^[1-9]\d*$/.test(param)) {
    return null;
  }
  const id = Number(param);
  return Number.isSafeInteger(id) ? id : null;
}

/**
 * One task with everything the task page shows. Tasks 24 and 25 invalidate this key after a
 * mutation. Nothing is asked for without a usable id.
 * @param {number | null | undefined} id
 * @returns {import('@tanstack/react-query').UseQueryResult<TaskDetail>}
 */
export function useTask(id) {
  return useQuery({
    queryKey: queryKeys.task(id ?? 'none'),
    queryFn: ({ signal }) => apiFetch(`/api/tasks/${id}`, { signal }),
    enabled: isTaskId(id),
  });
}

/**
 * The activity history of a task, oldest first. It is only loaded once `enabled` (the page opens
 * it on first expand); an invalidated task key refreshes it too, because its key extends the
 * task's.
 * @param {number | null | undefined} id
 * @param {{enabled?: boolean}} [options]
 * @returns {import('@tanstack/react-query').UseQueryResult<{events: TaskEvent[]}>}
 */
export function useTaskHistory(id, { enabled = true } = {}) {
  return useQuery({
    queryKey: queryKeys.taskHistory(id ?? 'none'),
    queryFn: ({ signal }) => apiFetch(`/api/tasks/${id}/history`, { signal }),
    enabled: enabled && isTaskId(id),
  });
}
